<?php

/*
 * InvestmentsPageTest.php
 *
 * Custom addition (not part of upstream Firefly III): tests for the "Investments" page.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

namespace Tests\integration\Investments;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Models\Account;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Investments\InvestmentOverview;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Override;
use Tests\integration\TestCase;

/**
 * @group investments
 *
 * @internal
 *
 * @coversNothing
 */
final class InvestmentsPageTest extends TestCase
{
    use RefreshDatabase;

    private Account $etf;
    private Account $stocks;
    private User $user;

    public function testOverviewOnlyContainsInvestmentAccounts(): void
    {
        $overview = new InvestmentOverview($this->user)->getOverview(today(config('app.timezone')));
        $names    = array_column($overview['rows'], 'name');

        // largest first; "Savings", "Old investment" (investment: false) and the expense account are not included.
        $this->assertSame(['World ETF', 'Single stocks'], $names);
        $this->assertFalse($overview['converted']);
    }

    public function testOverviewNumbers(): void
    {
        $overview = new InvestmentOverview($this->user)->getOverview(today(config('app.timezone')));
        $etf      = $overview['rows'][$this->etf->id];
        $stocks   = $overview['rows'][$this->stocks->id];

        // notes
        $this->assertSame('Broker X', $etf['broker']);
        $this->assertSame('IE00B4L5Y983', $etf['isin']);
        $this->assertSame('12.5', $etf['shares']);
        $this->assertNull($stocks['isin']);

        // balances: ETF got 1000 sixty days ago and 500 ten days ago, stocks got 500 forty days ago.
        $this->assertSame(0, bccomp('1500', $etf['value'], 12));
        $this->assertSame(0, bccomp('1000', $etf['previous'], 12));
        $this->assertSame(0, bccomp('500', $etf['change'], 12));
        $this->assertSame(0, bccomp('50', $etf['change_pct'], 12));
        $this->assertSame(0, bccomp('75', $etf['share'], 12));
        $this->assertSame(0, bccomp('25', $stocks['share'], 12));
        $this->assertSame(0, bccomp('0', $stocks['change'], 12));

        // totals
        $this->assertSame(0, bccomp('2000', $overview['total']['value'], 12));
        $this->assertSame(0, bccomp('500', $overview['total']['change'], 12));
    }

    public function testMixedCurrenciesAreConvertedToPrimaryCurrency(): void
    {
        $usd     = TransactionCurrency::query()->where('code', 'USD')->first();
        $account = $this->createAccount(AccountTypeEnum::ASSET, 'US stocks', "investment: true\nbroker: US broker");
        $account->accountMeta()->create(['name' => 'currency_id', 'data' => (string) $usd->id]);

        $overview = new InvestmentOverview($this->user)->getOverview(today(config('app.timezone')));
        $this->assertTrue($overview['converted']);
        $this->assertSame(Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup)->code, $overview['currency']->code);
        $this->assertSame('USD', $overview['rows'][$account->id]['currency']->code);

        $response = $this->get(route('investments.index'));
        $response->assertOk();
        $response->assertSee('US stocks');
        $response->assertSee((string) trans('investments.converted_note', ['currency' => $overview['currency']->name]));
        $this->getJson(route('investments.chart.allocation.broker'))->assertOk();
    }

    public function testIndexPage(): void
    {
        $response = $this->get(route('investments.index'));
        $response->assertOk();
        $response->assertSee('World ETF');
        $response->assertSee('Single stocks');
        $response->assertSee('IE00B4L5Y983');
        $response->assertSee('Broker X');
        $response->assertDontSee('Savings');
        $response->assertDontSee('Old investment');
        $response->assertSee(route('investments.chart.balances'));
    }

    public function testIndexPageWithoutInvestments(): void
    {
        $this->actingAs($this->createUser('other@example.com'));
        $response = $this->get(route('investments.index'));
        $response->assertOk();
        $response->assertSee((string) trans('investments.no_investments_title'));
        $response->assertDontSee('World ETF');
    }

    public function testMenuEntry(): void
    {
        $response = $this->get(route('index'));
        $response->assertOk();
        $response->assertSee(route('investments.index'));
    }

    public function testBalanceChart(): void
    {
        $this->withSession(['start' => today()->subDays(90)->startOfDay(), 'end' => today()->endOfDay()]);
        $response = $this->getJson(route('investments.chart.balances'));
        $response->assertOk();
        $response->assertJsonPath('count', 2);
        $response->assertJsonPath('datasets.0.label', 'World ETF');
        $response->assertJsonPath('datasets.1.label', 'Single stocks');
        $data     = $response->json('datasets.0.data');
        $this->assertCount(91, $data);
        $this->assertSame(0, bccomp('0', (string) $data[0], 12));
        $this->assertSame(0, bccomp('1500', (string) $data[90], 12));
    }

    public function testAllocationCharts(): void
    {
        $response = $this->getJson(route('investments.chart.allocation.account'));
        $response->assertOk();
        $response->assertJsonPath('labels', ['World ETF', 'Single stocks']);

        $response = $this->getJson(route('investments.chart.allocation.broker'));
        $response->assertOk();
        $response->assertJsonPath('labels', ['Broker X', (string) trans('investments.no_broker')]);
    }

    public function testPagesDoNotChangeData(): void
    {
        // On the very first page view, Firefly III itself stores some default preferences for a new user
        // (any page does this). So first open an existing page, then take the snapshot.
        $this->get(route('piggy-banks.index'))->assertOk();

        $tables = ['accounts', 'account_meta', 'notes', 'transactions', 'transaction_journals', 'transaction_groups', 'preferences'];
        $before = $this->snapshot($tables);

        $this->get(route('investments.index'))->assertOk();
        $this->getJson(route('investments.chart.balances'))->assertOk();
        $this->getJson(route('investments.chart.allocation.account'))->assertOk();
        $this->getJson(route('investments.chart.allocation.broker'))->assertOk();

        $this->assertSame($before, $this->snapshot($tables));
    }

    public function testRequiresLogin(): void
    {
        auth()->logout();
        $this->get(route('investments.index'))->assertRedirect();
        $this->get(route('investments.chart.balances'))->assertRedirect();
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->user   = $this->createUser('test@example.com');
        $this->actingAs($this->user);

        $this->etf    = $this->createAccount(AccountTypeEnum::ASSET, 'World ETF', "My ETF.\n\ninvestment: true\nbroker: Broker X\nisin: IE00B4L5Y983\nshares: 12,5");
        $this->stocks = $this->createAccount(AccountTypeEnum::ASSET, 'Single stocks', "- investment: yes\n- shares: 3");
        $this->createAccount(AccountTypeEnum::ASSET, 'Savings', 'Just savings.');
        $this->createAccount(AccountTypeEnum::ASSET, 'Old investment', 'investment: false');
        $this->createAccount(AccountTypeEnum::EXPENSE, 'Shop', 'investment: true');
        $revenue      = $this->createAccount(AccountTypeEnum::REVENUE, 'Employer', null);

        $this->deposit($revenue, $this->etf, '1000', today()->subDays(60));
        $this->deposit($revenue, $this->etf, '500', today()->subDays(10));
        $this->deposit($revenue, $this->stocks, '500', today()->subDays(40));
    }

    /**
     * All rows of the given tables, so any insert, update or delete shows up as a difference.
     */
    private function snapshot(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->toArray();
        }

        return $result;
    }

    private function createAccount(AccountTypeEnum $type, string $name, ?string $notes): Account
    {
        /** @var Account $account */
        $account = Account::factory()->for($this->user)->withType($type)->create(['name' => $name, 'user_group_id' => $this->user->user_group_id]);
        if (null !== $notes) {
            $account->notes()->create(['text' => $notes]);
        }

        return $account;
    }

    private function createUser(string $email): User
    {
        // same as createAuthenticatedUser() in the parent class, but with a custom e-mail address.
        $group = UserGroup::create(['title' => $email]);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $user  = User::create(['email' => $email, 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }

    private function deposit(Account $source, Account $destination, string $amount, Carbon $date): void
    {
        /** @var TransactionCurrency $currency */
        $currency = Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup);
        $type     = TransactionType::query()->where('type', TransactionTypeEnum::DEPOSIT->value)->first();
        $group    = TransactionGroup::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'title' => null]);
        $journal  = TransactionJournal::create([
            'user_id'                 => $this->user->id,
            'user_group_id'           => $this->user->user_group_id,
            'transaction_type_id'     => $type->id,
            'transaction_currency_id' => $currency->id,
            'description'             => 'Deposit',
            'completed'               => true,
            'order'                   => 0,
            'tag_count'               => 0,
            'date'                    => $date->copy()->setTime(12, 0),
        ]);
        $journal->transaction_group_id = $group->id;
        $journal->save();
        $journal->transactions()->create(['account_id' => $source->id, 'amount' => '-'.$amount, 'transaction_currency_id' => $currency->id]);
        $journal->transactions()->create(['account_id' => $destination->id, 'amount' => $amount, 'transaction_currency_id' => $currency->id]);
    }
}
