<?php

/**
 * InvestmentNoteParser.php
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

/**
 * Reads the notes of an asset account and finds the investment settings in it.
 *
 * Recognised lines (one per line, anywhere in the notes, case-insensitive):
 *
 *   investment: true
 *   broker: <name>
 *   isin: <ISIN>
 *   shares: <number>
 *
 * Parsing is lenient: "=" may be used instead of ":", Markdown decoration like
 * "- ", "* ", "> " or "**bold**" is ignored, and the first occurrence of a key wins.
 */
final class InvestmentNoteParser
{
    private const array TRUE_VALUES = ['true', 'yes', 'y', '1', 'on'];

    /**
     * Returns null when the notes do not mark the account as an investment.
     *
     * Otherwise returns:
     *   broker:     the broker name, or null
     *   isin:       the ISIN in upper case without spaces, or null
     *   isin_valid: true when the ISIN has a valid format and check digit
     *   shares:     the number of shares as a plain decimal string ("12.5"), or null
     *   shares_raw: the shares value exactly as written in the notes, or null
     *
     * @return null|array{broker: null|string, isin: null|string, isin_valid: bool, shares: null|string, shares_raw: null|string}
     */
    public function parse(?string $notes): ?array
    {
        if (null === $notes || '' === trim($notes)) {
            return null;
        }
        $values    = $this->readKeyValues($notes);
        $flag      = strtolower(rtrim($values['investment'] ?? '', '.!'));
        if (!in_array($flag, self::TRUE_VALUES, true)) {
            return null;
        }
        $broker    = $values['broker'] ?? '';
        $isin      = strtoupper((string) preg_replace('/[\s\-]+/u', '', $values['isin'] ?? ''));
        $sharesRaw = $values['shares'] ?? '';

        return [
            'broker'     => '' === $broker ? null : $broker,
            'isin'       => '' === $isin ? null : $isin,
            'isin_valid' => '' !== $isin && self::isValidIsin($isin),
            'shares'     => '' === $sharesRaw ? null : self::normalizeNumber($sharesRaw),
            'shares_raw' => '' === $sharesRaw ? null : $sharesRaw,
        ];
    }

    /**
     * Turns a human-written number into a plain decimal string, or null when it is not a number.
     *
     * "12" => "12", "12.5" => "12.5", "12,5" => "12.5", "1,234.56" => "1234.56",
     * "1.234,56" => "1234.56", "1 234,5" => "1234.5", "1'234.5" => "1234.5".
     * A single "," or "." is always read as the decimal separator.
     */
    public static function normalizeNumber(string $value): ?string
    {
        // drop spaces (including non-breaking ones), apostrophes and underscores used as thousands separators.
        $value    = (string) preg_replace("/[\\s\\x{00A0}\\x{202F}'’_]+/u", '', $value);
        $lastDot  = strrpos($value, '.');
        $lastComa = strrpos($value, ',');
        if (false !== $lastDot && false !== $lastComa) {
            // both are used: whichever comes last is the decimal separator.
            $thousands = $lastDot > $lastComa ? ',' : '.';
            $value     = str_replace($thousands, '', $value);
        }
        if (false === $lastDot || false === $lastComa) {
            // only one kind is used: more than one of them means thousands separators.
            foreach (['.', ','] as $separator) {
                if (substr_count($value, $separator) > 1) {
                    $value = str_replace($separator, '', $value);
                }
            }
        }
        $value    = str_replace(',', '.', $value);
        if (1 !== preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value)) {
            return null;
        }
        $value    = ltrim($value, '+');
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if (str_starts_with($value, '.')) {
            $value = '0'.$value;
        }
        if (str_starts_with($value, '-.')) {
            $value = '-0'.substr($value, 1);
        }

        return '' === $value || '-' === $value ? '0' : $value;
    }

    /**
     * Checks the format (2 letters, 9 letters/digits, 1 check digit) and the check digit (Luhn) of an ISIN.
     */
    public static function isValidIsin(string $isin): bool
    {
        if (1 !== preg_match('/^[A-Z]{2}[A-Z0-9]{9}\d$/', $isin)) {
            return false;
        }
        // replace letters by numbers (A=10 ... Z=35), then apply the Luhn algorithm.
        $digits = '';
        foreach (str_split($isin) as $char) {
            $digits .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }
        $sum    = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            $digit  = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return 0 === $sum % 10;
    }

    /**
     * @return array<string, string>
     */
    private function readKeyValues(string $notes): array
    {
        $result = [];
        $lines  = preg_split('/\R|<br\s*\/?>/iu', $notes);
        if (false === $lines) {
            return [];
        }
        foreach ($lines as $line) {
            // remove Markdown decoration: bold/italic/code markers, then list and quote markers.
            $line = str_replace(['**', '__', '`'], '', $line);
            $line = (string) preg_replace('/^[\s>#*+\-]+/u', '', $line);
            if (1 !== preg_match('/^(investment|broker|isin|shares)\s*[:=]\s*(.*)$/iu', trim($line), $matches)) {
                continue;
            }
            $key  = strtolower($matches[1]);
            if (!array_key_exists($key, $result)) {
                $result[$key] = trim($matches[2], " \t\"'*_");
            }
        }

        return $result;
    }
}
