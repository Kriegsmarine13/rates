<?php


use App\Exceptions\RatesProviderUnavailableException;
use App\Jobs\FetchRates;
use App\Services\DailyRatesLoader;
use Illuminate\Support\Facades\Queue;

it('dispatches one job for today and each of the previous 180 days', function () {
    Queue::fake();
    $today = new DateTimeImmutable('today');
    $this->artisan('rates:fetch')->assertExitCode(0);
    Queue::assertPushed(FetchRates::class, 181);
    $jobs = Queue::pushed(FetchRates::class);
    $expectedDates = [];

    for ($i = 0; $i <= 180; $i++) {
        $expectedDates[] = $today
            ->modify("-{$i} day")
            ->format('Y-m-d');
    }

    $actualDates = $jobs->map(fn(FetchRates $job) => $job->date)->all();
    sort($expectedDates);
    sort($actualDates);
    expect($actualDates)->toBe($expectedDates);
    expect(
        $jobs->every(
            fn(FetchRates $job) => $job->connection === 'rabbitmq',
        )
    )->toBeTrue();
});

it('delegates loading to DailyRatesLoader', function () {
    $loader = $this->mock(DailyRatesLoader::class);

    $loader->shouldReceive('ensureRatesForDate')
        ->once()
        ->with('2026-10-04')
        ->andReturn('2026-10-03');

    (new FetchRates('2026-10-04'))->handle($loader);
});

it('propagates loading failures so the worker can retry', function () {
    $loader = $this->mock(DailyRatesLoader::class);

    $loader->shouldReceive('ensureRatesForDate')
        ->once()
        ->with('2026-10-04')
        ->andThrow(new RatesProviderUnavailableException('Unavailable'));

    expect(
        fn() => (new FetchRates('2026-10-04'))->handle($loader)
    )->toThrow(RatesProviderUnavailableException::class);
});
