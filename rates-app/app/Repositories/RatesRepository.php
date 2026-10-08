<?php

namespace App\Repositories;

use App\Models\Rate;

class RatesRepository extends Repository
{
    public function getRate(string $targetCurrency, string $date): array
    {
        $current = Rate::query()
            ->where('currency', $targetCurrency)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->first();

        if ($current === null) {
            return [null, null];
        }

        $previous = Rate::query()
            ->where('currency', $targetCurrency)
            ->where('date', '<', $current->date)
            ->orderByDesc('date')
            ->first();

        return [$current->toArray(), $previous?->toArray()];
    }

    public function saveRates(array $rates): void
    {
        Rate::upsert(
            $rates,
            uniqueBy: ['currency', 'date'],
            update: ['rate', 'nominal']
        );
    }

    public function tryGetRateForRequestedDate(string $targetCurrency, string $date): ?array
    {
        return Rate::query()
            ->where('currency', $targetCurrency)
            ->where('date', '=', $date)
            ->first()
            ?->toArray();
    }

    public function hasRatesForDate(string $date): bool
    {
        return Rate::query()
            ->where('date', $date)
            ->exists();
    }
}
