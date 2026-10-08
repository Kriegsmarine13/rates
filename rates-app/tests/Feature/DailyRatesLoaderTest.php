<?php

use App\Exceptions\RatesProviderInvalidResponseException;
use App\Exceptions\RatesProviderUnavailableException;
use App\Http\Providers\CBRRateProvider;
use App\Repositories\RatesRepository;
use App\Services\DailyRatesLoader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function loaderTestRates(string $date = '2026-10-03'): array
{
    return [
        [
            'currency' => 'USD',
            'date' => $date,
            'rate' => '80.0000',
            'nominal' => 1,
        ],
        [
            'currency' => 'KRW',
            'date' => $date,
            'rate' => '60.0000',
            'nominal' => 1000,
        ],
    ];
}

beforeEach(function () {
    $this->travelTo(
        CarbonImmutable::parse(
            '2026-10-07 12:00:00',
            'Europe/Moscow',
        ),
    );

    config()->set('cache.stores.redis', [
        'driver' => 'array',
        'serialize' => false,
    ]);

    Cache::purge('redis');

    Redis::shouldReceive(
        'connection->throttle->allow->every->block->then'
    )->andReturnUsing(
        fn (callable $callback) => $callback(),
    );

    $this->provider = $this->mock(CBRRateProvider::class);
    $this->loader = app(DailyRatesLoader::class);
});

it('saves all currencies and caches the effective date', function () {
    $this->provider->shouldReceive('getDailyRate')
        ->once()
        ->with('2026-10-04')
        ->andReturn(loaderTestRates());

    expect($this->loader->ensureRatesForDate('2026-10-04'))
        ->toBe('2026-10-03');

    $this->assertDatabaseCount('rates', 2);

    $this->assertDatabaseHas('rates', [
        'currency' => 'KRW',
        'date' => '2026-10-03',
        'nominal' => 1000,
    ]);

    expect(Cache::store('redis')->get('cbr:date:2026-10-04'))
        ->toBe('2026-10-03');

    // Второй запрос не должен снова обращаться к провайдеру.
    expect($this->loader->ensureRatesForDate('2026-10-04'))
        ->toBe('2026-10-03');
});

it('uses cached data without calling the provider or limiter', function () {
    app(RatesRepository::class)->saveRates(loaderTestRates());

    Cache::store('redis')->put(
        'cbr:date:2026-10-04',
        '2026-10-03',
        86400,
    );

    $this->provider->shouldNotReceive('getDailyRate');
    Redis::shouldReceive('connection')->never();

    expect($this->loader->ensureRatesForDate('2026-10-04'))
        ->toBe('2026-10-03');
});

it('reloads rates when the cached date has no database records', function () {
    Cache::store('redis')->put(
        'cbr:date:2026-10-04',
        '2026-10-03',
        86400,
    );

    $this->provider->shouldReceive('getDailyRate')
        ->once()
        ->with('2026-10-04')
        ->andReturn(loaderTestRates());

    expect($this->loader->ensureRatesForDate('2026-10-04'))
        ->toBe('2026-10-03');

    $this->assertDatabaseCount('rates', 2);
});

it('updates existing quotes without creating duplicates', function () {
    $original = loaderTestRates();
    $updated = loaderTestRates();
    $updated[0]['rate'] = '81.0000';

    $this->provider->shouldReceive('getDailyRate')
        ->twice()
        ->with('2026-10-04')
        ->andReturn($original, $updated);

    $this->loader->ensureRatesForDate('2026-10-04');

    Cache::store('redis')->forget('cbr:date:2026-10-04');

    $this->loader->ensureRatesForDate('2026-10-04');

    $this->assertDatabaseCount('rates', 2);

    $this->assertDatabaseHas('rates', [
        'currency' => 'USD',
        'date' => '2026-10-03',
        'rate' => '81.0000',
    ]);
});

it('does not save or cache inconsistent provider data', function (
    string $scenario,
) {
    $rates = match ($scenario) {
        'empty' => [],
        'future date' => loaderTestRates('2026-10-05'),
        'different dates' => [
            loaderTestRates('2026-10-03')[0],
            loaderTestRates('2026-10-02')[1],
        ],
    };

    $this->provider->shouldReceive('getDailyRate')
        ->once()
        ->with('2026-10-04')
        ->andReturn($rates);

    expect(
        fn () => $this->loader->ensureRatesForDate('2026-10-04')
    )->toThrow(RatesProviderInvalidResponseException::class);

    $this->assertDatabaseCount('rates', 0);

    expect(Cache::store('redis')->get('cbr:date:2026-10-04'))
        ->toBeNull();
})->with([
    'empty',
    'future date',
    'different dates',
]);

it('releases the lock after a provider failure', function () {
    $this->provider->shouldReceive('getDailyRate')
        ->once()
        ->with('2026-10-04')
        ->andThrow(new RatesProviderUnavailableException('Unavailable'));

    expect(
        fn () => $this->loader->ensureRatesForDate('2026-10-04')
    )->toThrow(RatesProviderUnavailableException::class);

    $this->assertDatabaseCount('rates', 0);

    expect(Cache::store('redis')->get('cbr:date:2026-10-04'))
        ->toBeNull();

    $lock = Cache::store('redis')->lock(
        'cbr:load:2026-10-04',
        45,
    );

    try {
        expect($lock->get())->toBeTrue();
    } finally {
        $lock->release();
    }
});

it('expires the mapping for today after five minutes', function () {
    $this->provider->shouldReceive('getDailyRate')
        ->once()
        ->with('2026-10-07')
        ->andReturn(loaderTestRates('2026-10-07'));

    $this->loader->ensureRatesForDate('2026-10-07');

    $this->travel(299)->seconds();

    expect(Cache::store('redis')->get('cbr:date:2026-10-07'))
        ->toBe('2026-10-07');

    $this->travel(2)->seconds();

    expect(Cache::store('redis')->get('cbr:date:2026-10-07'))
        ->toBeNull();
});

it('keeps a historical date mapping longer than five minutes', function () {
    $this->provider->shouldReceive('getDailyRate')
        ->once()
        ->with('2026-10-04')
        ->andReturn(loaderTestRates());

    $this->loader->ensureRatesForDate('2026-10-04');

    $this->travel(301)->seconds();

    expect(Cache::store('redis')->get('cbr:date:2026-10-04'))
        ->toBe('2026-10-03');

    $this->travel(86400)->seconds();

    expect(Cache::store('redis')->get('cbr:date:2026-10-04'))
        ->toBeNull();
});
