<?php

namespace App\Helpers;

use App\Exceptions\RatesProviderInvalidResponseException;

class XMLHelper
{
    /**
     * @throws RatesProviderInvalidResponseException
     */
    public static function xmlToArray(string $xml): array {
        $before = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($xml);

            if ($xml === false) {
                throw new RatesProviderInvalidResponseException('Invalid XML from CBR');
            }

            return json_decode(json_encode($xml, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new RatesProviderInvalidResponseException('Failed to decode CBR XML data', 0, $jsonException);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
    }
}
