<?php

use App\Exceptions\RatesProviderInvalidResponseException;
use App\Exceptions\RatesProviderUnavailableException;
use App\Http\Providers\CBRRateProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function cbrProviderTestXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ValCurs Date="05.03.2026" name="Foreign Currency Market">
    <Valute ID="R01815">
        <NumCode>410</NumCode>
        <CharCode>KRW</CharCode>
        <Nominal>1000</Nominal>
        <Name>Вон Республики Корея</Name>
        <Value>55,1234</Value>
    </Valute>
</ValCurs>
XML;
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('normalizes a single currency record', function () {
    Http::fake([
        'cbr.ru/*' => Http::response(cbrProviderTestXml()),
    ]);

    $rates = app(CBRRateProvider::class)
        ->getDailyRate('2026-03-05');

    expect($rates)->toBe([
        [
            'currency' => 'KRW',
            'date' => '2026-03-05',
            'rate' => '55.1234',
            'nominal' => 1000,
        ],
    ]);

    Http::assertSent(function ($request) {
        parse_str(
            parse_url($request->url(), PHP_URL_QUERY),
            $query,
        );

        return $request->method() === 'GET'
            && ($query['date_req'] ?? null) === '05/03/2026';
    });

    Http::assertSentCount(1);
});

it('reads XML encoded as windows-1251', function () {
    $xml = str_replace(
        'encoding="UTF-8"',
        'encoding="windows-1251"',
        cbrProviderTestXml(),
    );

    $xml = iconv('UTF-8', 'Windows-1251', $xml);

    Http::fake([
        'cbr.ru/*' => Http::response($xml),
    ]);

    $rates = app(CBRRateProvider::class)
        ->getDailyRate('2026-03-05');

    expect($rates[0])->toBe([
        'currency' => 'KRW',
        'date' => '2026-03-05',
        'rate' => '55.1234',
        'nominal' => 1000,
    ]);
});

it('rejects invalid XML data', function (
    string $search,
    string $replacement,
) {
    $xml = str_replace(
        $search,
        $replacement,
        cbrProviderTestXml(),
    );

    Http::fake([
        'cbr.ru/*' => Http::response($xml),
    ]);

    expect(
        fn () => app(CBRRateProvider::class)
            ->getDailyRate('2026-03-05')
    )->toThrow(RatesProviderInvalidResponseException::class);
})->with([
    'broken XML' => [
        '</ValCurs>',
        '',
    ],
    'missing date' => [
        'Date="05.03.2026"',
        '',
    ],
    'impossible date' => [
        'Date="05.03.2026"',
        'Date="31.02.2026"',
    ],
    'invalid currency code' => [
        '<CharCode>KRW</CharCode>',
        '<CharCode>krw</CharCode>',
    ],
    'missing nominal' => [
        '<Nominal>1000</Nominal>',
        '',
    ],
    'zero nominal' => [
        '<Nominal>1000</Nominal>',
        '<Nominal>0</Nominal>',
    ],
    'invalid nominal' => [
        '<Nominal>1000</Nominal>',
        '<Nominal>abc</Nominal>',
    ],
    'zero rate' => [
        '<Value>55,1234</Value>',
        '<Value>0,0000</Value>',
    ],
    'invalid rate' => [
        '<Value>55,1234</Value>',
        '<Value>abc</Value>',
    ],
]);

it('reports temporary HTTP failures', function (int $status) {
    Http::fake([
        'cbr.ru/*' => Http::response('Unavailable', $status),
    ]);

    expect(
        fn () => app(CBRRateProvider::class)
            ->getDailyRate('2026-03-05')
    )->toThrow(RatesProviderUnavailableException::class);
})->with([429, 500, 503]);

it('reports unexpected HTTP responses', function () {
    Http::fake([
        'cbr.ru/*' => Http::response('Not found', 404),
    ]);

    expect(
        fn () => app(CBRRateProvider::class)
            ->getDailyRate('2026-03-05')
    )->toThrow(RatesProviderInvalidResponseException::class);
});

it('preserves the cause of a connection failure', function () {
    Http::fake([
        'cbr.ru/*' => Http::failedConnection(),
    ]);

    try {
        app(CBRRateProvider::class)->getDailyRate('2026-03-05');

        $this->fail('Expected a provider exception.');
    } catch (RatesProviderUnavailableException $exception) {
        expect($exception->getPrevious())
            ->toBeInstanceOf(ConnectionException::class);
    }
});
