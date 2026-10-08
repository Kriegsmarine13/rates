<?php

namespace App\Jobs;

use App\Exceptions\RatesServiceException;
use App\Services\DailyRatesLoader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\MaxExceptions;

#[Backoff(5, 15, 60)]
#[MaxExceptions(3)]
class FetchRates implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $date
    )
    {
        //
    }

    /**
     * @throws RatesServiceException
     */
    public function handle(DailyRatesLoader $loader): void
    {
        $loader->ensureRatesForDate($this->date);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->plus(minutes: 15);
    }
}
