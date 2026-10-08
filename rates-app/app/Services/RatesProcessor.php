<?php

namespace App\Services;

use App\Exceptions\RatesProcessorException;
use App\Jobs\FetchRates;

class RatesProcessor
{
    const int AMOUNT_OF_DAYS_TO_FETCH = 180;

    public function process(): void
    {
        try {
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'));
            for ($i = 0; $i <= self::AMOUNT_OF_DAYS_TO_FETCH; $i++) {
                $date = $today->modify("-{$i} day")->format("Y-m-d");

                FetchRates::dispatch($date)->onConnection('rabbitmq');
            }
        } catch (\Throwable $e) {
            throw new RatesProcessorException(
                'Failed to dispatch rate jobs', 0, $e);
        }

    }
}
