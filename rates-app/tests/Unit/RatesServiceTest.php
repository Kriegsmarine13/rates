<?php

use App\Exceptions\RateNotFoundException;
use App\Exceptions\RatesProviderUnavailableException;
use App\Exceptions\RatesServiceException;
use App\Repositories\RatesRepository;
use App\Services\DailyRatesLoader;
use App\Services\RatesService;

function ratesServiceTestQuote(
    string $date,
    string $rate,
    int $nominal = 1,
): array {
    return [
        'date' => $date,
        'rate' => $rate,
        'nominal' => $nominal,
    ];
}

beforeEach(function () {
    $this->repository = Mockery::mock(RatesRepository::class);
    $this->loader = Mockery::mock(DailyRatesLoader::class);

    $this->service = new RatesService(
        $this->repository,
        $this->loader,
    );

    $this->quotes = [
        'USD' => [
            '2026-03-06' => ratesServiceTestQuote(
                '2026-03-06', '80.0000',
            ),
            '2026-03-05' => ratesServiceTestQuote(
                '2026-03-05', '100.0000',
            ),
        ],
        'EUR' => [
            '2026-03-06' => ratesServiceTestQuote(
                '2026-03-06', '100.0000',
            ),
            '2026-03-05' => ratesServiceTestQuote(
                '2026-03-05', '100.0000',
            ),
        ],
        'KRW' => [
            '2026-03-06' => ratesServiceTestQuote(
                '2026-03-06', '60.0000', 1000,
            ),
            '2026-03-05' => ratesServiceTestQuote(
                '2026-03-05', '50.0000', 1000,
            ),
        ],
        'JPY' => [
            '2026-03-06' => ratesServiceTestQuote(
                '2026-03-06', '50.0000', 100,
            ),
            '2026-03-05' => ratesServiceTestQuote(
                '2026-03-05', '40.0000', 100,
            ),
        ],
    ];

    $this->loader->shouldReceive('ensureRatesForDate')
        ->with('2026-03-08')
        ->andReturn('2026-03-06')
        ->byDefault();

    $this->loader->shouldReceive('ensureRatesForDate')
        ->with('2026-03-05')
        ->andReturn('2026-03-05')
        ->byDefault();

    $this->repository->shouldReceive('tryGetRateForRequestedDate')
        ->andReturnUsing(
            fn (string $currency, string $date): ?array =>
                $this->quotes[$currency][$date] ?? null,
        )
        ->byDefault();
});

afterEach(function () {
    Mockery::close();
});

it('calculates rates and differences for currency pairs', function (
    string $target,
    string $base,
    int $nominal,
    string $rate,
    string $diff,
) {
    $result = $this->service->getRates(
        $target,
        '2026-03-08',
        $base,
    );

    expect($result)->toBe([
        'currency' => $target,
        'base' => $base,
        'date' => '2026-03-06',
        'previous_date' => '2026-03-05',
        'nominal' => $nominal,
        'rate' => $rate,
        'diff' => $diff,
    ]);
})->with([
    'foreign currency to ruble' => [
        'USD', 'RUR', 1, '80.0000', '-20.0000',
    ],
    'ruble to foreign currency' => [
        'RUR', 'USD', 1, '0.0125', '0.0025',
    ],
    'cross rate' => [
        'USD', 'EUR', 1, '0.8000', '-0.2000',
    ],
    'reverse cross rate' => [
        'EUR', 'USD', 1, '1.2500', '0.2500',
    ],
    'target nominal of 1000' => [
        'KRW', 'USD', 1000, '0.7500', '0.2500',
    ],
    'base nominal of 100' => [
        'USD', 'JPY', 1, '160.0000', '-90.0000',
    ],
    'both currencies have non-unit nominals' => [
        'KRW', 'JPY', 1000, '120.0000', '-5.0000',
    ],
]);

it('defaults the base currency to RUR', function () {
    $result = $this->service->getRates('USD', '2026-03-08');

    expect($result['base'])->toBe('RUR')
        ->and($result['rate'])->toBe('80.0000');
});

it('requests the previous date relative to the effective rate date', function () {
    $this->loader->shouldReceive('ensureRatesForDate')
        ->once()
        ->with('2026-03-08')
        ->andReturn('2026-03-06');

    $this->loader->shouldReceive('ensureRatesForDate')
        ->once()
        ->with('2026-03-05')
        ->andReturn('2026-03-05');

    $result = $this->service->getRates('USD', '2026-03-08');

    expect($result['date'])->toBe('2026-03-06')
        ->and($result['previous_date'])->toBe('2026-03-05');
});

it('compares unit rates when the target nominal changes', function () {
    $this->quotes['KRW']['2026-03-05'] = ratesServiceTestQuote(
        '2026-03-05',
        '5.0000',
        100,
    );

    $result = $this->service->getRates('KRW', '2026-03-08');

    // Текущий: 60 / 1000 = 0.06 RUB за одну вону.
    // Предыдущий: 5 / 100 = 0.05 RUB за одну вону.
    // Разница за текущий номинал: (0.06 - 0.05) * 1000 = 10.
    expect($result['nominal'])->toBe(1000)
        ->and($result['rate'])->toBe('60.0000')
        ->and($result['diff'])->toBe('10.0000');
});

it('rounds the rate and difference to four decimal places', function () {
    $this->quotes['USD']['2026-03-06']['rate'] = '1.0000';
    $this->quotes['USD']['2026-03-05']['rate'] = '1.0000';
    $this->quotes['EUR']['2026-03-06']['rate'] = '3.0000';
    $this->quotes['EUR']['2026-03-05']['rate'] = '6.0000';

    $result = $this->service->getRates(
        'USD',
        '2026-03-08',
        'EUR',
    );

    expect($result['rate'])->toBe('0.3333')
        ->and($result['diff'])->toBe('0.1667');
});

it('returns a null difference when a previous quote is missing', function (
    string $currency,
) {
    unset($this->quotes[$currency]['2026-03-05']);

    $result = $this->service->getRates(
        'USD',
        '2026-03-08',
        'EUR',
    );

    expect($result['rate'])->toBe('0.8000')
        ->and($result['diff'])->toBeNull()
        ->and($result['previous_date'])->toBeNull();
})->with(['USD', 'EUR']);

it('throws when a current quote is missing', function (
    string $currency,
) {
    unset($this->quotes[$currency]['2026-03-06']);

    expect(
        fn () => $this->service->getRates(
            'USD',
            '2026-03-08',
            'EUR',
        )
    )->toThrow(RateNotFoundException::class);
})->with(['USD', 'EUR']);

it('rejects quotes with mismatched dates', function (string $date) {
    $this->quotes['EUR'][$date]['date'] = '2026-03-04';

    expect(
        fn () => $this->service->getRates(
            'USD',
            '2026-03-08',
            'EUR',
        )
    )->toThrow(RatesServiceException::class);
})->with([
    'current quotes' => ['2026-03-06'],
    'previous quotes' => ['2026-03-05'],
]);

it('rejects non-positive rates and nominals', function (
    string $currency,
    string $date,
    string $field,
    string|int $value,
) {
    $this->quotes[$currency][$date][$field] = $value;

    expect(
        fn () => $this->service->getRates(
            'USD',
            '2026-03-08',
            'EUR',
        )
    )->toThrow(RatesServiceException::class);
})->with([
    'current target rate is zero' => [
        'USD', '2026-03-06', 'rate', '0.0000',
    ],
    'current base rate is negative' => [
        'EUR', '2026-03-06', 'rate', '-1.0000',
    ],
    'previous target rate is zero' => [
        'USD', '2026-03-05', 'rate', '0.0000',
    ],
    'previous base rate is negative' => [
        'EUR', '2026-03-05', 'rate', '-1.0000',
    ],
    'current target nominal is zero' => [
        'USD', '2026-03-06', 'nominal', 0,
    ],
    'current base nominal is negative' => [
        'EUR', '2026-03-06', 'nominal', -1,
    ],
    'previous target nominal is zero' => [
        'USD', '2026-03-05', 'nominal', 0,
    ],
    'previous base nominal is negative' => [
        'EUR', '2026-03-05', 'nominal', -1,
    ],
]);

it('rejects identical currencies without accessing dependencies', function () {
    $this->loader->shouldNotReceive('ensureRatesForDate');
    $this->repository->shouldNotReceive('tryGetRateForRequestedDate');

    expect(
        fn () => $this->service->getRates(
            'USD',
            '2026-03-08',
            'USD',
        )
    )->toThrow(InvalidArgumentException::class);
});

it('propagates a loading failure without accessing the repository', function () {
    $exception = new RatesProviderUnavailableException('Unavailable');

    $this->loader->shouldReceive('ensureRatesForDate')
        ->once()
        ->with('2026-03-08')
        ->andThrow($exception);

    $this->repository->shouldNotReceive('tryGetRateForRequestedDate');

    try {
        $this->service->getRates('USD', '2026-03-08');

        $this->fail('Expected the loading exception.');
    } catch (RatesProviderUnavailableException $caught) {
        expect($caught)->toBe($exception);
    }
});
