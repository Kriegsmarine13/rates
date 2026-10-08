<?php

namespace App\Http\Providers;

abstract class RatesProvider
{
    public function getRate(string $targetCurrency, string $date, string $baseCurrency = 'RUR')
    {

    }
}
