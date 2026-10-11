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

    // Exchanges that quote in euro, best first: XETRA, Frankfurt, the other German exchanges,
    // then Amsterdam, Paris and Milan. Listings on other exchanges are skipped when euro is wanted.
    public const array EUR_SUFFIXES = ['.DE', '.F', '.SG', '.MU', '.BE', '.DU', '.HM', '.HA', '.AS', '.PA', '.MI'];

    // Yahoo's exchange codes in search results, for results that have an unusual symbol.
    private const array EXCHANGE_SUFFIXES = [
        'GER' => '.DE', 'FRA' => '.F', 'STU' => '.SG', 'MUN' => '.MU', 'BER' => '.BE', 'DUS' => '.DU',
        'HAM' => '.HM', 'HAN' => '.HA', 'AMS' => '.AS', 'PAR' => '.PA', 'MIL' => '.MI',
    ];

    // at most this many listings are checked with an extra request.
    private const int MAX_CHECKS = 4;

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
     * Finds the Yahoo symbol for an ISIN, see findListing().
     *
     * @throws FireflyException
     */
    public function findSymbol(string $isin, string $currency = 'EUR'): string
    {
        return $this->findListing($isin, $currency)['symbol'];
    }

    /**
     * Finds a listing of an ISIN that is quoted in $currency, and its latest price.
     *
     * 1. Search Yahoo for the ISIN.
     * 2. If that finds no listing on a German exchange (Yahoo often only returns London or Amsterdam
     *    for an ISIN), search again by the product's name, and add the results with exactly the same name.
     * 3. Check the best candidates (see EUR_SUFFIXES) one by one: the first one whose price really is
     *    in $currency wins.
     *
     * @return array{symbol: string, quote: array{price: string, currency: string, date: Carbon}}
     *
     * @throws FireflyException when there is no such listing, with a message that says what to do
     */
    public function findListing(string $isin, string $currency = 'EUR'): array
    {
        $quotes     = $this->search($isin);
        if (0 === count($quotes)) {
            throw new FireflyException(sprintf('Yahoo Finance does not know ISIN %s. Enter the symbol yourself (for example ending in .DE for XETRA), or enter the price by hand.', $isin));
        }
        $candidates = self::rankSymbols($quotes, $currency);
        if ('EUR' === $currency && !self::hasGermanListing($candidates)) {
            // Yahoo's ISIN search often returns only London or Amsterdam: also search by name.
            $name = self::quoteName($quotes[0]);
            if ('' !== $name) {
                $sameName   = array_values(array_filter(
                    $this->search($name),
                    static fn (array $quote): bool => self::normalizeName(self::quoteName($quote)) === self::normalizeName($name)
                ));
                $candidates = self::rankSymbols(array_merge($quotes, $sameName), $currency);
            }
        }
        foreach (array_slice($candidates, 0, self::MAX_CHECKS) as $symbol) {
            try {
                $quote = $this->getPrice($symbol);
            } catch (FireflyException) {
                continue;
            }
            if ($quote['currency'] === $currency) {
                return ['symbol' => $symbol, 'quote' => $quote];
            }
        }
        $found      = implode(', ', array_map(static fn (array $quote): string => (string) $quote['symbol'], $quotes));

        throw new FireflyException(sprintf(
            'Yahoo Finance has no listing in %s for ISIN %s (found: %s). Enter a symbol from a German exchange yourself (for example ending in .DE for XETRA), or enter the price by hand.',
            $currency,
            $isin,
            $found
        ));
    }

    /**
     * The symbols from search results, best first. For euro: only euro exchanges, in the order of
     * EUR_SUFFIXES. For other currencies: all symbols in Yahoo's order (the price check decides).
     *
     * @param array<int, array<string, mixed>> $quotes
     *
     * @return array<int, string>
     */
    public static function rankSymbols(array $quotes, string $currency = 'EUR'): array
    {
        $ranked = [];
        foreach ($quotes as $index => $quote) {
            $symbol = (string) $quote['symbol'];
            if ('EUR' !== $currency) {
                $ranked[$symbol] = $index;

                continue;
            }
            $suffix = self::suffix($symbol, (string) ($quote['exchange'] ?? ''));
            $rank   = null === $suffix ? false : array_search($suffix, self::EUR_SUFFIXES, true);
            if (false !== $rank && !array_key_exists($symbol, $ranked)) {
                $ranked[$symbol] = $rank * 1000 + $index;
            }
        }
        asort($ranked);

        return array_map('strval', array_keys($ranked));
    }

    /**
     * @param array<int, string> $symbols
     */
    private static function hasGermanListing(array $symbols): bool
    {
        $german = array_slice(self::EUR_SUFFIXES, 0, 8); // .DE to .HA
        foreach ($symbols as $symbol) {
            foreach ($german as $suffix) {
                if (str_ends_with(strtoupper($symbol), $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The euro exchange of a symbol, as one of EUR_SUFFIXES, or null.
     */
    private static function suffix(string $symbol, string $exchange): ?string
    {
        $dot = strrpos($symbol, '.');
        if (false !== $dot) {
            $suffix = strtoupper(substr($symbol, $dot));

            return in_array($suffix, self::EUR_SUFFIXES, true) ? $suffix : null;
        }

        // no suffix: a US listing, unless Yahoo says otherwise.
        return self::EXCHANGE_SUFFIXES[strtoupper($exchange)] ?? null;
    }

    /**
     * Search results that have a symbol.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws FireflyException
     */
    private function search(string $query): array
    {
        $json   = $this->get(self::SEARCH_URL, ['q' => $query, 'quotesCount' => 20, 'newsCount' => 0, 'lang' => 'de-DE', 'region' => 'DE']);
        $quotes = [];
        foreach ($json['quotes'] ?? [] as $quote) {
            if (is_array($quote) && is_string($quote['symbol'] ?? null) && '' !== $quote['symbol']) {
                $quotes[] = $quote;
            }
        }

        return $quotes;
    }

    private static function quoteName(array $quote): string
    {
        return trim((string) ($quote['longname'] ?? $quote['shortname'] ?? ''));
    }

    private static function normalizeName(string $name): string
    {
        return (string) preg_replace('/\s+/', ' ', strtolower(trim($name)));
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
