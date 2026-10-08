<?php

use App\Exceptions\RatesProviderInvalidResponseException;
use App\Exceptions\RatesProviderUnavailableException;
use App\Models\Rate;
use App\Repositories\RatesRepository;
use App\Services\DailyRatesLoader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(
        CarbonImmutable::parse(
            '2026-10-07 12:00:00',
            'Europe/Moscow',
        ),
    );

    $this->loader = $this->mock(DailyRatesLoader::class);

    app(RatesRepository::class)->saveRates([
        [
            'currency' => 'USD',
            'date' => '2026-10-03',
            'rate' => '80.0000',
            'nominal' => 1,
        ],
        [
            'currency' => 'EUR',
            'date' => '2026-10-03',
            'rate' => '100.0000',
            'nominal' => 1,
        ],
        [
            'currency' => 'KRW',
            'date' => '2026-10-03',
            'rate' => '60.0000',
            'nominal' => 1000,
        ],
        [
            'currency' => 'USD',
            'date' => '2026-10-02',
            'rate' => '100.0000',
            'nominal' => 1,
        ],
        [
            'currency' => 'EUR',
            'date' => '2026-10-02',
            'rate' => '100.0000',
            'nominal' => 1,
        ],
        [
            'currency' => 'KRW',
            'date' => '2026-10-02',
            'rate' => '50.0000',
            'nominal' => 1000,
        ],
    ]);
});

function expectEndpointRateDates($loader): void
{
    $loader->shouldReceive('ensureRatesForDate')
        ->once()
        ->with('2026-10-04')
        ->andReturn('2026-10-03');

    // Предыдущая дата считается от фактической даты курса.
    $loader->shouldReceive('ensureRatesForDate')
        ->once()
        ->with('2026-10-02')
        ->andReturn('2026-10-02');
}

it('returns the rate and difference for a currency pair', function (
    string $target,
    string $base,
    int $nominal,
    string $rate,
    string $diff,
) {
    expectEndpointRateDates($this->loader);

    $query = http_build_query([
        'date' => '2026-10-04',
        'target' => $target,
        'base' => $base,
    ]);

    $this->get('/rate?' . $query)
        ->assertOk()
        ->assertExactJson([
            'currency' => $target,
            'base' => $base,
            'date' => '2026-10-03',
            'previous_date' => '2026-10-02',
            'nominal' => $nominal,
            'rate' => $rate,
            'diff' => $diff,
        ]);
})->with([
    'foreign currency to ruble' => [
        'USD', 'RUR', 1, '80.0000', '-20.0000',
    ],
    'cross rate' => [
        'USD', 'EUR', 1, '0.8000', '-0.2000',
    ],
    'reverse cross rate' => [
        'EUR', 'USD', 1, '1.2500', '0.2500',
    ],
    'rate per target nominal' => [
        'KRW', 'USD', 1000, '0.7500', '0.2500',
    ],
    'ruble as target' => [
        'RUR', 'USD', 1, '0.0125', '0.0025',
    ],
]);

it('normalizes the currency code and defaults the base to RUR', function () {
    expectEndpointRateDates($this->loader);

    $query = http_build_query([
        'date' => '2026-10-04',
        'target' => ' usd ',
    ]);

    $this->get('/rate?' . $query)
        ->assertOk()
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('base', 'RUR')
        ->assertJsonPath('rate', '80.0000');
});

it('returns a null difference when previous quotes are missing', function () {
    expectEndpointRateDates($this->loader);

    Rate::query()
        ->where('date', '2026-10-02')
        ->delete();

    $this->get('/rate?date=2026-10-04&target=USD&base=EUR')
        ->assertOk()
        ->assertJsonPath('rate', '0.8000')
        ->assertJsonPath('diff', null)
        ->assertJsonPath('previous_date', null);
});

it('compares unit rates when the target nominal changes', function () {
    expectEndpointRateDates($this->loader);

    // Раньше котировка была за 100 вон: 5 / 100 = 0.05 RUB.
    // Теперь за 1000 вон: 60 / 1000 = 0.06 RUB.
    app(RatesRepository::class)->saveRates([
        [
            'currency' => 'KRW',
            'date' => '2026-10-02',
            'rate' => '5.0000',
            'nominal' => 100,
        ],
    ]);

    $this->get('/rate?date=2026-10-04&target=KRW&base=RUR')
        ->assertOk()
        ->assertJsonPath('nominal', 1000)
        ->assertJsonPath('rate', '60.0000')
        ->assertJsonPath('diff', '10.0000');
});

it('rejects invalid input without loading rates', function (
    array $query,
    string $field,
) {
    $this->loader->shouldNotReceive('ensureRatesForDate');

    $this->get('/rate?' . http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'missing date' => [
        ['target' => 'USD'],
        'date',
    ],
    'invalid date format' => [
        ['date' => '04.10.2026', 'target' => 'USD'],
        'date',
    ],
    'impossible date' => [
        ['date' => '2026-02-31', 'target' => 'USD'],
        'date',
    ],
    'future date' => [
        ['date' => '2026-10-08', 'target' => 'USD'],
        'date',
    ],
    'missing target' => [
        ['date' => '2026-10-04'],
        'target',
    ],
    'invalid target code' => [
        ['date' => '2026-10-04', 'target' => 'US'],
        'target',
    ],
    'target is an array' => [
        ['date' => '2026-10-04', 'target' => ['USD']],
        'target',
    ],
    'empty base' => [
        ['date' => '2026-10-04', 'target' => 'USD', 'base' => ''],
        'base',
    ],
    'same currencies after normalization' => [
        ['date' => '2026-10-04', 'target' => 'usd', 'base' => 'USD'],
        'base',
    ],
]);

it('returns 404 for a valid but unavailable currency code', function () {
    expectEndpointRateDates($this->loader);

    $this->get('/rate?date=2026-10-04&target=ZZZ&base=RUR')
        ->assertNotFound()
        ->assertJsonPath(
            'message',
            'Currency rate is unavailable for the requested date.',
        );
});

it('maps execution errors to public HTTP responses', function (
    string $exceptionClass,
    int $status,
    string $message,
) {
    $this->loader->shouldReceive('ensureRatesForDate')
        ->once()
        ->with('2026-10-04')
        ->andThrow(new $exceptionClass('Internal diagnostic details'));

    $this->get('/rate?date=2026-10-04&target=USD')
        ->assertStatus($status)
        ->assertExactJson(['message' => $message]);
})->with([
    'invalid provider response' => [
        RatesProviderInvalidResponseException::class,
        502,
        'Failed to obtain valid currency rates from CBR.',
    ],
    'provider unavailable' => [
        RatesProviderUnavailableException::class,
        503,
        'CBR is temporarily unavailable. Please retry later.',
    ],
    'lock timeout' => [
        LockTimeoutException::class,
        503,
        'Rate loading is currently busy. Please try again later.',
    ],
    'limiter timeout' => [
        LimiterTimeoutException::class,
        503,
        'Rate loading is currently busy. Please try again later.',
    ],
]);
