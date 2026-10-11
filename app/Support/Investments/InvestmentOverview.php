<?php

/**
 * InvestmentOverview.php
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
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 * Collects everything the investments page shows. It only reads data, it never changes anything.
 *
 * Investment accounts are active asset accounts whose notes contain "investment: true".
 */
final class InvestmentOverview
{
    private readonly InvestmentNoteParser $parser;
    private readonly AccountRepositoryInterface $repository;

    public function __construct(User $user)
    {
        $this->parser     = new InvestmentNoteParser();
        $this->repository = app(AccountRepositoryInterface::class);
        $this->repository->setUser($user);
    }

    /**
     * The investment accounts, each with the information found in its notes.
     *
     * @return array<int, array{account: Account, info: array<string, mixed>}> keyed by account ID
     */
    public function getInvestmentAccounts(): array
    {
        $result   = [];
        $accounts = $this->repository->getActiveAccountsByType([AccountTypeEnum::DEFAULT->value, AccountTypeEnum::ASSET->value]);

        /** @var Account $account */
        foreach ($accounts as $account) {
            $info = $this->parser->parse($this->repository->getNoteText($account));
            if (null !== $info) {
                $result[$account->id] = ['account' => $account, 'info' => $info];
            }
        }

        return $result;
    }

    /**
     * Builds the table: balances on $date and $compareDays days earlier, percentages and totals.
     *
     * When all investment accounts use the same currency, everything is shown in that currency.
     * When they use different currencies, totals, percentages and allocations are converted to
     * the primary currency (using Firefly III's exchange rates), because otherwise they cannot be added up.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: array<string, null|string>, currency: TransactionCurrency, converted: bool, date: Carbon, compare_date: Carbon}
     */
    public function getOverview(Carbon $date, int $compareDays = 30): array
    {
        $date        = $date->copy()->endOfDay();
        $compareDate = $date->copy()->subDays($compareDays)->endOfDay();
        $primary     = Amount::getPrimaryCurrency();
        $investments = $this->getInvestmentAccounts();
        $currencies  = [];
        foreach ($investments as $id => $investment) {
            $currencies[$id] = $this->repository->getAccountCurrency($investment['account']) ?? $primary;
        }
        $codes       = array_unique(array_map(static fn (TransactionCurrency $currency): string => $currency->code, $currencies));
        $converted   = count($codes) > 1;
        $display     = 1 === count($codes) ? array_values($currencies)[0] : $primary;

        $rows        = [];
        if (count($investments) > 0) {
            $accounts = new Collection(array_column($investments, 'account'));
            $now      = Steam::accountsBalancesOptimized($accounts, $date, $primary, $converted);
            $before   = Steam::accountsBalancesOptimized($accounts, $compareDate, $primary, $converted);
            $field    = $converted ? 'pc_balance' : 'balance';
            foreach ($investments as $id => $investment) {
                $rows[$id] = array_merge($investment['info'], [
                    'account'          => $investment['account'],
                    'name'             => $investment['account']->name,
                    'currency'         => $currencies[$id],
                    'balance'          => (string) ($now[$id]['balance'] ?? '0'),
                    'balance_previous' => (string) ($before[$id]['balance'] ?? '0'),
                    'value'            => (string) ($now[$id][$field] ?? '0'),
                    'previous'         => (string) ($before[$id][$field] ?? '0'),
                ]);
            }
        }
        // largest investment first.
        uasort($rows, static fn (array $a, array $b): int => bccomp($b['value'], $a['value'], 12));
        $summary     = InvestmentCalculator::summarize($rows);

        return [
            'rows'         => $summary['rows'],
            'total'        => $summary['total'],
            'currency'     => $display,
            'converted'    => $converted,
            'date'         => $date,
            'compare_date' => $compareDate,
        ];
    }
}
