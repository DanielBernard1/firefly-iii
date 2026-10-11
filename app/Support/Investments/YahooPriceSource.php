<?php

/**
 * YahooPriceSource.php
 *
 * Custom addition (not part of upstream Firefly III): "Investments" page.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

namespace FireflyIII\Support\Investments;

use Carbon\Carbon;
use FireflyIII\Exceptions\FireflyException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gets prices from Yahoo Finance. Yahoo has no official API for this; these are the same
 * addresses its website uses. They need no account or key, but Yahoo may change them.
 */
class YahooPriceSource
{
    public const string CHART_URL  = 'https://query1.finance.yahoo.com/v8/finance/chart/%s';
    public const string SEARCH_URL = 'https://query2.finance.yahoo.com/v1/finance/search';

    // German exchanges first (prices in EUR), XETRA before the others.
    private const array PREFERRED_SUFFIXES = ['.DE', '.F', '.SG', '.MU', '.BE', '.DU', '.HM', '.HA'];

    /**
     * The latest price of a symbol, for example "EUNL.DE".
     *
     * @return array{price: string, currency: string, date: Carbon}
     *
     * @throws FireflyException
     */
    public function getPrice(string $symbol): array
    {
        $json  = $this->get(sprintf(self::CHART_URL, rawurlencode($symbol)), ['range' => '5d', 'interval' => '1d']);
        $meta  = $json['chart']['result'][0]['meta'] ?? null;
        $price = $meta['regularMarketPrice'] ?? null;
        if (!is_array($meta) || !is_numeric($price) || $price <= 0) {
            $error = $json['chart']['error']['description'] ?? 'no price in the answer';

            throw new FireflyException(sprintf('Yahoo Finance has no price for "%s": %s', $symbol, $error));
        }
        $currency = strtoupper((string) ($meta['currency'] ?? ''));
        $price    = number_format((float) $price, 6, '.', '');
        // London prices are in pence.
        if ('GBP' === $currency && 'GBp' === ($meta['currency'] ?? '')) {
            $price = bcdiv($price, '100', 8);
        }
        $date     = isset($meta['regularMarketTime']) && is_numeric($meta['regularMarketTime'])
            ? Carbon::createFromTimestamp((int) $meta['regularMarketTime'], config('app.timezone'))
            : now(config('app.timezone'));

        return ['price' => $price, 'currency' => $currency, 'date' => $date];
    }

    /**
     * Finds the Yahoo symbol for an ISIN. Prefers German exchanges, because those quote in EUR.
     *
     * @throws FireflyException
     */
    public function findSymbol(string $isin): ?string
    {
        $json    = $this->get(self::SEARCH_URL, ['q' => $isin, 'quotesCount' => 20, 'newsCount' => 0]);
        $symbols = [];
        foreach ($json['quotes'] ?? [] as $quote) {
            if (is_array($quote) && is_string($quote['symbol'] ?? null) && '' !== $quote['symbol']) {
                $symbols[] = $quote['symbol'];
            }
        }

        return self::bestSymbol($symbols);
    }

    /**
     * Picks the best symbol: the first German exchange in the order above, otherwise the first one.
     *
     * @param array<int, string> $symbols
     */
    public static function bestSymbol(array $symbols): ?string
    {
        foreach (self::PREFERRED_SUFFIXES as $suffix) {
            foreach ($symbols as $symbol) {
                if (str_ends_with(strtoupper($symbol), $suffix)) {
                    return $symbol;
                }
            }
        }

        return $symbols[0] ?? null;
    }

    /**
     * @throws FireflyException
     */
    private function get(string $url, array $query): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefly-III-Investments'])
                ->timeout(20)
                ->get($url, $query)
            ;
        } catch (Throwable $e) {
            // keep the message short: cut off the technical details (cURL documentation link and full URL).
            $reason = trim(explode(' (see ', $e->getMessage())[0]);

            throw new FireflyException(sprintf('Could not reach Yahoo Finance (%s).', $reason), 0, $e);
        }
        $json     = $response->json();
        if (!is_array($json)) {
            throw new FireflyException(sprintf('Yahoo Finance answered with HTTP %d and no usable data.', $response->status()));
        }

        return $json;
    }
}
