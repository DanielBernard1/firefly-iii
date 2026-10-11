<?php

/**
 * InvestmentPortfolio.php
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
use FireflyIII\Models\Account;
use FireflyIII\Support\Investments\Models\InvestmentPrice;
use FireflyIII\Support\Investments\Models\InvestmentProduct;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 * The products in each depot, with their value, gain and price changes. Only reads data.
 */
final class InvestmentPortfolio
{
    public function __construct(private readonly User $user) {}

    /**
     * One entry per depot (an account that has products), in the order of $accounts.
     *
     * @param array<int, Account> $accounts the investment accounts, keyed by ID
     *
     * @return array<int, array{account: Account, products: array<int, array<string, mixed>>, total: array<string, null|string>}>
     */
    public function getDepots(array $accounts, Carbon $date, int $compareDays = 30): array
    {
        $products = InvestmentProduct::query()
            ->where('user_id', $this->user->id)
            ->whereIn('account_id', array_keys($accounts))
            ->orderBy('name')
            ->get()
            ->groupBy('account_id')
        ;
        $result   = [];
        foreach ($accounts as $id => $account) {
            if (!$products->has($id)) {
                continue;
            }
            $rows        = $products->get($id)->map(fn (InvestmentProduct $product): array => $this->row($product, $date, $compareDays))->all();
            $result[$id] = ['account' => $account, 'products' => $rows, 'total' => self::total($rows)];
        }

        return $result;
    }

    /**
     * Value of each product over time, for the chart. Between two prices the last known value is used.
     *
     * @param Collection<int, InvestmentProduct> $products
     *
     * @return array{labels: array<int, string>, sets: array<int, array{label: string, entries: array<string, null|string>}>}
     */
    public static function valueHistory(Collection $products, Carbon $start, Carbon $end): array
    {
        $dates  = [];
        $prices = [];
        foreach ($products as $product) {
            // the last price before the start, so the line does not start empty.
            $first                 = $product->prices()->whereDate('date', '<', $start->format('Y-m-d'))->orderBy('date', 'desc')->first();
            $inRange               = $product->prices()->whereDate('date', '>=', $start->format('Y-m-d'))->whereDate('date', '<=', $end->format('Y-m-d'))->orderBy('date')->get();
            $prices[$product->id]  = $inRange;
            foreach ($inRange as $price) {
                $dates[$price->date->format('Y-m-d')] = true;
            }
            if (null !== $first) {
                $prices[$product->id]->prepend($first);
                $dates[$start->format('Y-m-d')] = true;
            }
        }
        ksort($dates);
        $labels = array_keys($dates);
        $sets   = [];
        foreach ($products as $product) {
            $entries = [];
            $last    = null;
            $list    = $prices[$product->id];
            $index   = 0;
            foreach ($labels as $label) {
                while ($index < $list->count() && $list[$index]->date->format('Y-m-d') <= $label) {
                    $last = $list[$index]->value;
                    ++$index;
                }
                $entries[$label] = $last;
            }
            $sets[]  = ['label' => $product->name, 'entries' => $entries];
        }

        return ['labels' => $labels, 'sets' => $sets];
    }

    /**
     * Value per product, for the "allocation by product" pie charts. Largest first.
     * Products without a price, or with a value of zero or less, are left out (a pie cannot show them).
     * A product name that is used in more than one depot gets the depot name added, so the slices stay apart.
     *
     * @param array<int, array{account: Account, products: array<int, array<string, mixed>>, total: array<string, null|string>}> $depots
     * @param null|callable(string, Account): string                                                                              $convert optional: converts a value from the depot's currency
     *
     * @return array<string, string> label => value
     */
    public static function allocation(array $depots, ?callable $convert = null): array
    {
        $items = [];
        $names = [];
        foreach ($depots as $depot) {
            foreach ($depot['products'] as $row) {
                if (null === $row['value'] || 1 !== bccomp((string) $row['value'], '0', 12)) {
                    continue;
                }
                $name          = $row['product']->name;
                $names[$name]  = ($names[$name] ?? 0) + 1;
                $value         = null === $convert ? (string) $row['value'] : $convert((string) $row['value'], $depot['account']);
                $items[]       = ['name' => $name, 'depot' => $depot['account']->name, 'value' => $value];
            }
        }
        usort($items, static fn (array $a, array $b): int => bccomp($b['value'], $a['value'], 12));
        $result = [];
        foreach ($items as $item) {
            $label = $names[$item['name']] > 1 ? sprintf('%s (%s)', $item['name'], $item['depot']) : $item['name'];
            $base  = $label;
            $count = 2;
            while (array_key_exists($label, $result)) {
                $label = sprintf('%s %d', $base, $count++);
            }
            $result[$label] = $item['value'];
        }

        return $result;
    }

    /**
     * Names of the products that have no price yet (they are missing from the allocation charts).
     *
     * @param array<int, array{account: Account, products: array<int, array<string, mixed>>, total: array<string, null|string>}> $depots
     *
     * @return array<int, string>
     */
    public static function withoutPrice(array $depots): array
    {
        $names = [];
        foreach ($depots as $depot) {
            foreach ($depot['products'] as $row) {
                if (null === $row['value']) {
                    $names[] = $row['product']->name;
                }
            }
        }

        return $names;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, null|string>
     */
    public static function total(array $rows): array
    {
        $total = ['value' => '0', 'invested' => '0', 'gain' => '0', 'change' => '0', 'change_30' => '0', 'previous_value' => '0', 'value_30' => '0'];
        // the amount paid of the products that have a price: the gain in % is based on these only.
        $investedWithPrice = '0';
        foreach ($rows as $row) {
            // amount paid counts for every product, also when it has no price yet.
            $total['invested'] = bcadd($total['invested'], $row['invested'], 12);
            if (null === $row['value']) {
                continue;
            }
            $investedWithPrice       = bcadd($investedWithPrice, $row['invested'], 12);
            $total['value']          = bcadd($total['value'], $row['value'], 12);
            $total['gain']           = bcadd($total['gain'], $row['gain'], 12);
            $total['change']         = bcadd($total['change'], $row['change'] ?? '0', 12);
            $total['change_30']      = bcadd($total['change_30'], $row['change_30'] ?? '0', 12);
            $total['previous_value'] = bcsub($total['value'], $total['change'], 12);
            $total['value_30']       = bcsub($total['value'], $total['change_30'], 12);
        }
        $total['gain_pct']      = InvestmentCalculator::percentage($total['gain'], $investedWithPrice, true);
        $total['change_pct']    = InvestmentCalculator::percentage($total['change'], $total['previous_value'], true);
        $total['change_30_pct'] = InvestmentCalculator::percentage($total['change_30'], $total['value_30'], true);

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(InvestmentProduct $product, Carbon $date, int $compareDays): array
    {
        $latest   = $this->priceOn($product, $date);
        $previous = null === $latest ? null : $this->priceOn($product, $latest->date->copy()->subDay());
        $old      = $this->priceOn($product, $date->copy()->subDays($compareDays));
        $row      = [
            'product'        => $product,
            'price'          => $latest?->price,
            'price_date'     => $latest?->date,
            'price_source'   => $latest?->source,
            'value'          => null,
            'invested'       => $product->invested,
            'gain'           => null,
            'gain_pct'       => null,
            'change'         => null,
            'change_pct'     => null,
            'change_30'      => null,
            'change_30_pct'  => null,
        ];
        if (null === $latest) {
            return $row;
        }
        $row['value']    = bcmul($product->shares, $latest->price, 12);
        $row['gain']     = bcsub($row['value'], $product->invested, 12);
        $row['gain_pct'] = InvestmentCalculator::percentage($row['gain'], $product->invested, true);
        if (null !== $previous) {
            $row['change']     = bcmul($product->shares, bcsub($latest->price, $previous->price, 12), 12);
            $row['change_pct'] = InvestmentCalculator::percentage(bcsub($latest->price, $previous->price, 12), $previous->price, true);
        }
        if (null !== $old && $old->id !== $latest->id) {
            $row['change_30']     = bcmul($product->shares, bcsub($latest->price, $old->price, 12), 12);
            $row['change_30_pct'] = InvestmentCalculator::percentage(bcsub($latest->price, $old->price, 12), $old->price, true);
        }

        return $row;
    }

    private function priceOn(InvestmentProduct $product, Carbon $date): ?InvestmentPrice
    {
        /** @var null|InvestmentPrice */
        return $product->prices()->whereDate('date', '<=', $date->format('Y-m-d'))->orderBy('date', 'desc')->first();
    }
}
