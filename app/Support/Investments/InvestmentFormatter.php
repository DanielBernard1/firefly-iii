<?php

/**
 * InvestmentFormatter.php
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

use FireflyIII\Support\Facades\Steam;
use NumberFormatter;

/**
 * Formats share counts and percentages in the user's locale ("1,234.5" or "1.234,5").
 * Money amounts are formatted with Firefly III's own format_amount_by_symbol() helper instead.
 */
final class InvestmentFormatter
{
    /**
     * A plain number, with up to $maxDecimals decimals (no trailing zeros).
     */
    public static function number(?string $value, int $maxDecimals = 6, ?string $locale = null): string
    {
        if (null === $value) {
            return '';
        }
        $formatter = new NumberFormatter($locale ?? Steam::getLocale(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimals);

        return (string) $formatter->format((float) $value); // float is fine here: this is only for display.
    }

    /**
     * A percentage like "12.3 %" (or "12,3 %"). With $signed a "+" is shown for positive values.
     * Returns "–" when there is no percentage (for example when dividing by zero).
     */
    public static function percentage(?string $value, bool $signed = false, ?string $locale = null): string
    {
        if (null === $value) {
            return '–';
        }
        $formatter = new NumberFormatter($locale ?? Steam::getLocale(), NumberFormatter::PERCENT);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 1);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);
        $result    = (string) $formatter->format((float) $value / 100);
        // only add "+" when the value does not round to zero.
        if ($signed && round((float) $value, 1) > 0) {
            $result = '+'.$result;
        }

        return $result;
    }
}
