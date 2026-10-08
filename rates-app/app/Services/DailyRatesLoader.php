<?php

namespace App\Services;

use App\Exceptions\RatesProviderInvalidResponseException;
use App\Http\Providers\CBRRateProvider;
use App\Repositories\RatesRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class DailyRatesLoader
{
    private const string CACHE_KEY = 'cbr:date:%s';
    private const string LOCK_KEY = 'cbr:load:%s';
    private const string REQUEST_LIMIT_KEY = 'cbr:http';

    private const int TTL_LONG = 60 * 60 * 24;
    private const int TTL_SHORT = 60 * 5;

    private const int LOCK_TTL = 45;
    private const int LOCK_WAIT = 5;
    private const int LIMIT_WAIT = 5;

    public function __construct(
        private CBRRateProvider $provider,
        private RatesRepository $repository,
    ) {
    }

    public function ensureRatesForDate(string $requestedDate): string
    {
        $ratesDateCached = $this->getCachedRatesDate($requestedDate);

        if ($ratesDateCached !== null) {
            return $ratesDateCached;
        }

        $lock = Cache::store('redis')->lock(
            sprintf(self::LOCK_KEY, $requestedDate),
            self::LOCK_TTL,
        );

        $lock->block(self::LOCK_WAIT);

        try {
            $ratesDateCached = $this->getCachedRatesDate($requestedDate);

            if ($ratesDateCached !== null) {
                return $ratesDateCached;
            }

            $rates = $this->fetchRates($requestedDate);

            if ($rates === []) {
                throw new RatesProviderInvalidResponseException(
                    'CBR returned an empty rate set',
                );
            }

            $ratesDate = $rates[0]['date'];

            if ($ratesDate > $requestedDate) {
                throw new RatesProviderInvalidResponseException(
                    'CBR returned a rate set after the requested date',
                );
            }

            foreach ($rates as $rate) {
                if ($rate['date'] !== $ratesDate) {
                    throw new RatesProviderInvalidResponseException(
                        'CBR returned inconsistent rate dates',
                    );
                }
            }

            $this->repository->saveRates($rates);

            $today = now('Europe/Moscow')->format('Y-m-d');
            $ttl = $requestedDate === $today
                ? self::TTL_SHORT
                : self::TTL_LONG;

            Cache::store('redis')->put(
                sprintf(self::CACHE_KEY, $requestedDate),
                $ratesDate,
                $ttl,
            );

            return $ratesDate;
        } finally {
            $lock->release();
        }
    }

    private function getCachedRatesDate(string $requestedDate): ?string
    {
        $ratesDateCached = Cache::store('redis')->get(
            sprintf(self::CACHE_KEY, $requestedDate),
        );

        if ($ratesDateCached === null) {
            return null;
        }

        if (!$this->repository->hasRatesForDate($ratesDateCached)) {
            return null;
        }

        return $ratesDateCached;
    }

    private function fetchRates(string $requestedDate): array
    {
        return Redis::connection('cache')
            ->throttle(self::REQUEST_LIMIT_KEY)
            ->allow(1)
            ->every(1)
            ->block(self::LIMIT_WAIT)
            ->then(
                fn (): array => $this->provider->getDailyRate($requestedDate),
            );
    }
}
