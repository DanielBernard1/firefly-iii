<?php

/**
 * ChartController.php
 *
 * Custom addition (not part of upstream Firefly III): "Investments" page.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Investments;

use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Generator\Chart\Basic\GeneratorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Support\Http\Controllers\ChartGeneration;
use FireflyIII\Support\Investments\InvestmentCalculator;
use FireflyIII\Support\Investments\InvestmentOverview;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Delivers the chart data (JSON) for the "Investments" page. Read-only: it never changes any data.
 */
final class ChartController extends Controller
{
    use ChartGeneration;

    /**
     * Balance over time, one line per investment account, for the date range selected at the top of the page.
     * Uses the same code as the account chart on the dashboard.
     *
     * @throws FireflyException
     */
    public function balances(): JsonResponse
    {
        $start    = clone session('start', today(config('app.timezone'))->startOfMonth());
        $end      = clone session('end', today(config('app.timezone'))->endOfMonth());
        $end->endOfDay();
        // same order as the table (largest first), so the colours match the "allocation by account" chart.
        $rows     = $this->overview()->getOverview(today(config('app.timezone')))['rows'];
        $accounts = new Collection(array_column($rows, 'account'));
        if (0 === $accounts->count()) {
            return response()->json([]);
        }

        return response()->json($this->accountBalanceChart($accounts, $start, $end));
    }

    /**
     * Pie chart: current value per investment account.
     */
    public function allocationByAccount(): JsonResponse
    {
        return response()->json($this->allocation('name', ''));
    }

    /**
     * Pie chart: current value per broker.
     */
    public function allocationByBroker(): JsonResponse
    {
        return response()->json($this->allocation('broker', (string) trans('investments.no_broker')));
    }

    private function allocation(string $field, string $emptyLabel): array
    {
        $overview = $this->overview()->getOverview(today(config('app.timezone')));
        // a pie chart cannot show negative or zero values, so those accounts are left out.
        $rows     = array_filter($overview['rows'], static fn (array $row): bool => 1 === bccomp((string) $row['value'], '0', 12));
        $groups   = InvestmentCalculator::groupBy($rows, $field, $emptyLabel);
        $data     = [];
        foreach ($groups as $name => $value) {
            $data[(string) $name] = ['amount' => $value, 'currency_symbol' => $overview['currency']->symbol];
        }
        if (0 === count($data)) {
            return [];
        }

        return app(GeneratorInterface::class)->multiCurrencyPieChart($data);
    }

    private function overview(): InvestmentOverview
    {
        /** @var User $user */
        $user = auth()->user();

        return new InvestmentOverview($user);
    }
}
