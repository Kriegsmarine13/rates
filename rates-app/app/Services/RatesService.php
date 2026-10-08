<?php

namespace App\Services;

use App\Exceptions\RateNotFoundException;
use App\Exceptions\RatesServiceException;
use App\Repositories\RatesRepository;

class RatesService
{
    private RatesRepository $repository;
    private DailyRatesLoader $loader;
    private const int CALCULATION_SCALE = 12;
    private const int OUTPUT_SCALE = 4;


    public function __construct(
        RatesRepository $ratesRepository,
        DailyRatesLoader $loader,
    )
    {
        $this->repository = $ratesRepository;
        $this->loader = $loader;
    }

    public function getRates(string $targetCurrency, string $date, string $baseCurrency = 'RUR'): array
    {
        $quotes = $this->getQuotesForPair($targetCurrency, $baseCurrency, $date);
        $rates = $this->calculatePairRates($quotes);

        return $this->formatResult($quotes, $rates);
    }

    /**
     * @throws RatesServiceException
     */
    private function getQuotesForPair(
        string $targetCurrency,
        string $baseCurrency,
        string $date,
    ): array {
        if ($targetCurrency === $baseCurrency) {
            throw new \InvalidArgumentException(
                'Target and base currencies must differ',
            );
        }

        $quotesByCurrency = [];
        $referenceCurrency = $targetCurrency === 'RUR' ? $baseCurrency : $targetCurrency;
        $ensuredTargetDate = $this->loader->ensureRatesForDate($date);
        $ensuredPreviousDate = $this->loader->ensureRatesForDate(
            (new \DateTimeImmutable($ensuredTargetDate))->modify('-1 day')->format('Y-m-d')
        );

        foreach ([$targetCurrency, $baseCurrency] as $currency) {
            if ($currency !== 'RUR') {
                $current = $this->repository->tryGetRateForRequestedDate(
                    $currency,
                    $ensuredTargetDate,
                );

                $previous = $this->repository->tryGetRateForRequestedDate(
                    $currency,
                    $ensuredPreviousDate,
                );
                $quotesByCurrency[$currency] = [
                    'current' => $current,
                    'previous' => $previous,
                ];
            }
        }

        $reference = $quotesByCurrency[$referenceCurrency];

        foreach (['current', 'previous'] as $period) {
            $quote = $reference[$period];
            $quotesByCurrency['RUR'][$period] = $quote === null
                ? null
                : [
                    'date' => $quote['date'],
                    'rate' => '1',
                    'nominal' => 1,
                ];
        }

        $result = [
            'currency' => $targetCurrency,
            'base' => $baseCurrency,
        ];

        foreach (['current', 'previous'] as $period) {
            $target = $quotesByCurrency[$targetCurrency][$period];
            $base = $quotesByCurrency[$baseCurrency][$period];

            if ($target === null || $base === null) {
                if ($period === 'current') {
                    throw new RateNotFoundException(
                        'Currency rate is unavailable for requested date'
                    );
                }

                $result[$period] = null;

                continue;
            }

            if ($target['date'] !== $base['date']) {
                throw new RatesServiceException(
                    "Currency rate dates do not match: {$period}",
                );
            }

            foreach ([$target, $base] as $quote) {
                if (
                    $quote['nominal'] <= 0
                    || bccomp(
                        $quote['rate'],
                        '0',
                        self::CALCULATION_SCALE,
                    ) <= 0
                ) {
                    throw new RatesServiceException(
                        'Rate and nominal must be positive',
                    );
                }
            }

            $result[$period] = [
                'target' => $target,
                'base' => $base,
            ];
        }

        return $result;
    }

    private function calculatePairRates(array $quotes): array
    {
        $current = $this->calculateCrossRate($quotes['current']['target'], $quotes['current']['base']);
        $diff = null;

        if ($quotes['previous'] !== null) {
            $previous = $this->calculateCrossRate($quotes['previous']['target'], $quotes['previous']['base']);
            $diff = bcsub($current, $previous, self::CALCULATION_SCALE);
        }

        return [
            'rate' => $current,
            'diff' => $diff,
        ];
    }

    private function calculateCrossRate(array $target, array $base): string
    {
        $targetRatePerBaseNominal = bcmul($target['rate'], (string) $base['nominal'], self::CALCULATION_SCALE);
        $baseRatePerTargetNominal = bcmul($base['rate'], (string) $target['nominal'], self::CALCULATION_SCALE);

        return bcdiv(
            $targetRatePerBaseNominal,
            $baseRatePerTargetNominal,
            self::CALCULATION_SCALE,
        );
    }

    private function formatResult(array $quotes, array $rates): array
    {
        $target = $quotes['current']['target'];
        $nominal = (string) $target['nominal'];

        $rate = bcmul(
            $rates['rate'],
            $nominal,
            self::CALCULATION_SCALE,
        );

        $diff = $rates['diff'] === null
            ? null
            : bcmul(
                $rates['diff'],
                $nominal,
                self::CALCULATION_SCALE,
            );

        return [
            'currency' => $quotes['currency'],
            'base' => $quotes['base'],
            'date' => $target['date'],
            'previous_date' =>
                $quotes['previous']['target']['date'] ?? null,
            'nominal' => $target['nominal'],
            'rate' => bcround($rate, self::OUTPUT_SCALE),
            'diff' => $diff === null
                ? null
                : bcround($diff, self::OUTPUT_SCALE),
        ];
    }
}
