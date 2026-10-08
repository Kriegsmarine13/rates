<?php

namespace App\Http\Providers;

use App\Exceptions\RatesProviderInvalidResponseException;
use App\Exceptions\RatesProviderUnavailableException;
use App\Helpers\XMLHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use function Laravel\Prompts\warning;

class CBRRateProvider extends RatesProvider
{
    const string DAILY_RATES_URL = 'https://cbr.ru/scripts/XML_daily.asp?date_req=%s';

    /**
     * @throws RatesProviderUnavailableException
     * @throws RatesProviderInvalidResponseException
     */
    public function getDailyRate(string $date): array
    {
        try {
            $date = date('d/m/Y', strtotime($date));
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->get(sprintf(self::DAILY_RATES_URL, $date));

            if ($response->status() === 429 || $response->serverError()) {
                throw new RatesProviderUnavailableException('CBR is temporarily unavailable');
            };

            if (!$response->successful()) {
                throw new RatesProviderInvalidResponseException(
                    'Unexpected response from CBR: ' . $response->status()
                );
            }

            $data = XMLHelper::xmlToArray($response->body());

            return $this->normalizeData($data);
        } catch (ConnectionException $connectionException) {
            throw new RatesProviderUnavailableException('CBR Connection failed', 0, $connectionException);
        }
    }

    /**
     * @throws RatesProviderInvalidResponseException
     */
    private function normalizeData(array $data): array
    {
        $date = $this->parseRateDate($data);
        $rates = $this->extractRates($data);
        $normalizedData = [];

        foreach ($rates as $rate) {
            $normalizedData[] = $this->normalizeRate($rate, $date);
        }

        return $normalizedData;
    }

    private function parseRateDate(array $data): string
    {
        $value = $data['@attributes']['Date'] ?? null;

        if (!is_string($value)) {
            throw new RatesProviderInvalidResponseException(
                'CBR rate date is missing or invalid',
            );
        }

        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                ))
        ) {
            throw new RatesProviderInvalidResponseException(
                'CBR returned an invalid rate date',
            );
        }

        return $date->format('Y-m-d');
    }

    private function extractRates(array $data): array
    {
        $rates = $data['Valute'] ?? null;

        if (!is_array($rates) || $rates === []) {
            throw new RatesProviderInvalidResponseException(
                'CBR returned an invalid or empty rate set',
            );
        }

        return array_key_exists('CharCode', $rates)
            ? [$rates]
            : $rates;
    }

    /**
     * @throws RatesProviderInvalidResponseException
     */
    private function normalizeRate(mixed $rate, string $date): array
    {
        if (!is_array($rate)) {
            throw new RatesProviderInvalidResponseException(
                'CBR returned an invalid currency record',
            );
        }

        $currency = $rate['CharCode'] ?? null;
        $nominal = $rate['Nominal'] ?? null;
        $value = $rate['Value'] ?? null;

        if (
            !is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1
        ) {
            throw new RatesProviderInvalidResponseException(
                'CBR returned an invalid currency code',
            );
        }

        $nominal = is_string($nominal)
            ? filter_var($nominal, FILTER_VALIDATE_INT)
            : false;

        if ($nominal === false || $nominal <= 0) {
            throw new RatesProviderInvalidResponseException(
                'CBR returned an invalid nominal',
            );
        }

        if (
            !is_string($value)
            || preg_match('/\A[0-9]+(?:,[0-9]+)?\z/', $value) !== 1
        ) {
            throw new RatesProviderInvalidResponseException(
                'CBR returned an invalid rate value',
            );
        }

        $value = str_replace(',', '.', $value);

        if (preg_match('/[1-9]/', $value) !== 1) {
            throw new RatesProviderInvalidResponseException(
                'CBR rate must be positive',
            );
        }

        return [
            'currency' => $currency,
            'date' => $date,
            'rate' => $value,
            'nominal' => $nominal,
        ];
    }
}
