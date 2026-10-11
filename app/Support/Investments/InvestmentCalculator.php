<?php

/**
 * InvestmentCalculator.php
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
 * Pure math for the investments page: totals, percentages and allocations.
 * It does not touch the database, so it is easy to test.
 *
 * All amounts are strings (like everywhere in Firefly III) and are calculated with bcmath.
 */
final class InvestmentCalculator
{
    private const int SCALE = 12;

    /**
     * Adds "share" (% of total), "change" and "change_pct" to every row and calculates the totals.
     *
     * Every row needs "value" (value now) and "previous" (value 30 days ago), in the same currency for all rows.
     * Other keys in the rows are kept as they are.
     *
     * @param array<int|string, array<string, mixed>> $rows
     *
     * @return array{rows: array<int|string, array<string, mixed>>, total: array{value: string, previous: string, change: string, change_pct: null|string}}
     */
    public static function summarize(array $rows): array
    {
        $total    = '0';
        $previous = '0';
        foreach ($rows as $row) {
            $total    = bcadd($total, (string) $row['value'], self::SCALE);
            $previous = bcadd($previous, (string) $row['previous'], self::SCALE);
        }
        foreach ($rows as $key => $row) {
            $change                   = bcsub((string) $row['value'], (string) $row['previous'], self::SCALE);
            $rows[$key]['share']      = self::percentage((string) $row['value'], $total);
            $rows[$key]['change']     = $change;
            $rows[$key]['change_pct'] = self::percentage($change, (string) $row['previous'], true);
        }
        $change   = bcsub($total, $previous, self::SCALE);

        return [
            'rows'  => $rows,
            'total' => [
                'value'      => $total,
                'previous'   => $previous,
                'change'     => $change,
                'change_pct' => self::percentage($change, $previous, true),
            ],
        ];
    }

    /**
     * Adds up the "value" of the rows per group, for example per broker.
     * Rows without a group (null or empty string) are added up under $emptyLabel.
     *
     * @param array<int|string, array<string, mixed>> $rows
     *
     * @return array<string, string> group name => total value, largest first
     */
    public static function groupBy(array $rows, string $field, string $emptyLabel): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $name          = trim((string) ($row[$field] ?? ''));
            $name          = '' === $name ? $emptyLabel : $name;
            $groups[$name] = bcadd($groups[$name] ?? '0', (string) $row['value'], self::SCALE);
        }
        uasort($groups, static fn (string $a, string $b): int => bccomp($b, $a, self::SCALE));

        return $groups;
    }

    /**
     * Returns $part as a percentage of $whole, or null when that cannot be calculated (whole is zero).
     * With $absoluteBase the sign of $whole is ignored, so a change from -100 to -50 is +50%.
     */
    public static function percentage(string $part, string $whole, bool $absoluteBase = false): ?string
    {
        if (0 === bccomp($whole, '0', self::SCALE)) {
            return null;
        }
        if ($absoluteBase && -1 === bccomp($whole, '0', self::SCALE)) {
            $whole = bcmul($whole, '-1', self::SCALE);
        }

        return bcdiv(bcmul($part, '100', self::SCALE), $whole, self::SCALE);
    }
}
