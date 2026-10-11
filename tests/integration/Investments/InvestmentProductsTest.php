<?php

/*
 * InvestmentProductsTest.php
 *
 * Custom addition (not part of upstream Firefly III): tests for products, prices and value changes.
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
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\Account;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Investments\InvestmentPortfolio;
use FireflyIII\Support\Investments\InvestmentUpdater;
use FireflyIII\Support\Investments\Models\InvestmentPrice;
use FireflyIII\Support\Investments\Models\InvestmentProduct;
use FireflyIII\Support\Investments\YahooPriceSource;
use FireflyIII\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Override;
use Tests\integration\TestCase;

/**
 * @group investments
 *
 * @internal
 *
 * @coversNothing
 */
final class InvestmentProductsTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;
    private Account $depot;
    private Account $other;
    private User $user;

    public function testAddProductThroughForm(): void
    {
        $this->get(route('investments.products.create', ['account' => $this->depot->id]))->assertOk()->assertSee('Scalable Depot');

        $this->post(route('investments.products.store'), [
            'account_id' => $this->depot->id,
            'name'       => 'MSCI World',
            'isin'       => 'ie00b4l5y983',
            'symbol'     => '',
            'shares'     => '1.234,5',
            'invested'   => '1000',
        ])->assertRedirect(route('investments.index'));

        $product = InvestmentProduct::query()->where('name', 'MSCI World')->first();
        $this->assertNotNull($product);
        $this->assertSame('IE00B4L5Y983', $product->isin);
        $this->assertNull($product->symbol);
        $this->assertSame(0, bccomp('1234.5', $product->shares, 12));
        $this->assertSame($this->user->id, $product->user_id);
    }

    public function testFormValidation(): void
    {
        $this->post(route('investments.products.store'), [
            'account_id' => $this->other->id, // not an investment account
            'name'       => 'X',
            'isin'       => 'IE00B4L5Y984', // wrong check digit
            'shares'     => 'abc',
            'invested'   => '-5',
        ])->assertSessionHasErrors(['account_id', 'isin', 'shares', 'invested']);
        $this->assertSame(0, InvestmentProduct::query()->count());
    }

    public function testCannotSeeProductsOfOtherUsers(): void
    {
        $product = $this->product('Mine', '10', '1000');
        $this->actingAs($this->createUser('other@example.com'));
        $this->get(route('investments.products.edit', [$product->id]))->assertNotFound();
        $this->post(route('investments.products.destroy', [$product->id]))->assertNotFound();
        $this->assertSame(1, InvestmentProduct::query()->count());
    }

    public function testEditManualPriceAndDelete(): void
    {
        $product = $this->product('Deka fund', '4', '400');
        $this->get(route('investments.products.edit', [$product->id]))->assertOk()->assertSee('Deka fund');

        $this->post(route('investments.products.update', [$product->id]), [
            'account_id'   => $this->depot->id,
            'name'         => 'Deka fund',
            'shares'       => '5',
            'invested'     => '500',
            'manual_price' => '101,50',
        ])->assertRedirect(route('investments.index'));

        $product->refresh();
        $this->assertSame(0, bccomp('5', $product->shares, 12));
        $price   = $product->latestPrice();
        $this->assertSame('manual', $price->source);
        $this->assertSame(0, bccomp('101.5', $price->price, 12));
        $this->assertSame(0, bccomp('507.5', $price->value, 12));

        $this->post(route('investments.products.destroy', [$product->id]))->assertRedirect(route('investments.index'));
        $this->assertSame(0, InvestmentProduct::query()->count());
        $this->assertSame(0, InvestmentPrice::query()->count());
    }

    public function testYahooPriceIsRead(): void
    {
        $this->fakeYahoo();
        $quote = new YahooPriceSource()->getPrice('EUNL.DE');
        $this->assertSame('EUR', $quote['currency']);
        $this->assertSame(0, bccomp('101.24', $quote['price'], 6));
    }

    public function testYahooErrorBecomesException(): void
    {
        $this->fakeYahoo();
        $this->expectException(FireflyException::class);
        new YahooPriceSource()->getPrice('NOPE.DE');
    }

    public function testSymbolIsFoundFromIsinPreferringXetra(): void
    {
        $this->fakeYahoo();
        $this->assertSame('EUNL.DE', new YahooPriceSource()->findSymbol('IE00B4L5Y983'));
        $this->assertSame('ABC.F', YahooPriceSource::bestSymbol(['ABC', 'ABC.F', 'ABC.L']));
        $this->assertSame('ABC', YahooPriceSource::bestSymbol(['ABC', 'ABC.L']));
        $this->assertNull(YahooPriceSource::bestSymbol([]));
    }

    public function testUpdatePricesLooksUpSymbolAndStoresPrice(): void
    {
        $this->fakeYahoo();
        $product  = $this->product('MSCI World', '10', '900', 'IE00B4L5Y983');
        $messages = new InvestmentUpdater($this->user)->updatePrices(today());

        $product->refresh();
        $this->assertSame('EUNL.DE', $product->symbol);
        $price    = $product->latestPrice();
        $this->assertSame('yahoo', $price->source);
        $this->assertSame(today()->format('Y-m-d'), $price->date->format('Y-m-d'));
        $this->assertSame(0, bccomp('1012.4', $price->value, 6));
        $this->assertStringContainsString('EUNL.DE', $messages[0]);
    }

    public function testPriceInOtherCurrencyIsRefused(): void
    {
        $this->fakeYahoo();
        $product  = $this->product('Apple', '1', '100', null, 'AAPL');
        $messages = new InvestmentUpdater($this->user)->updatePrices(today());
        $this->assertStringContainsString('USD', $messages[0]);
        $this->assertNull($product->latestPrice());
    }

    public function testRevalueBooksTransfersAgainstValueChangeAccount(): void
    {
        // the depot got 1000 (bought 10 shares at 100). Now the price is 120: value 1200, so +200 is booked.
        $this->transfer($this->cash, $this->depot, '1000', today()->subDays(20));
        $product  = $this->product('MSCI World', '10', '1000');
        $updater  = new InvestmentUpdater($this->user);
        $updater->setManualPrice($product, '120', today());
        $messages = $updater->revalue(today());
        $this->assertStringContainsString('200.00', $messages[0]);

        $this->assertSame(0, bccomp('1200', $this->balance($this->depot), 12));
        $this->assertSame(0, bccomp('-1000', $this->balance($this->cash), 12));
        $valueAccount = $this->valueChangeAccount();
        $this->assertSame('Investment value changes', $valueAccount->name);
        $this->assertSame(0, bccomp('-200', $this->balance($valueAccount), 12));

        // it is a transfer with the tag, and the value-change account is not part of net worth.
        $journal      = TransactionJournal::query()->where('description', 'Value change Scalable Depot')->first();
        $this->assertSame(TransactionTypeEnum::TRANSFER->value, $journal->transactionType->type);
        $this->assertSame([InvestmentUpdater::TAG], $journal->tags()->pluck('tag')->all());
        $repository   = app(AccountRepositoryInterface::class);
        $repository->setUser($this->user);
        $this->assertNotSame('1', $repository->getMetaValue($valueAccount, 'include_net_worth'));

        // running again books nothing.
        $updater->revalue(today());
        $this->assertSame(1, TransactionJournal::query()->where('description', 'Value change Scalable Depot')->count());

        // price drops to 110: -100 is booked from the depot to the value-change account.
        $updater->setManualPrice($product, '110', today());
        $updater->revalue(today());
        $this->assertSame(2, TransactionJournal::query()->where('description', 'Value change Scalable Depot')->count());
        $this->assertSame(0, bccomp('1100', $this->balance($this->depot), 12));
        $this->assertSame(0, bccomp('-100', $this->balance($valueAccount), 12));
        // still only one value-change account.
        $this->assertSame(1, Account::query()->where('name', 'Investment value changes')->count());
    }

    public function testRevalueWaitsUntilEveryProductHasAPrice(): void
    {
        $this->transfer($this->cash, $this->depot, '1000', today()->subDays(20));
        $one      = $this->product('One', '10', '500');
        $this->product('Two', '5', '500');
        $updater  = new InvestmentUpdater($this->user);
        $updater->setManualPrice($one, '60', today());
        $messages = $updater->revalue(today());
        $this->assertStringContainsString('not every product has a price', $messages[0]);
        $this->assertSame(0, TransactionJournal::query()->where('description', 'Value change Scalable Depot')->count());
    }

    public function testPortfolioNumbers(): void
    {
        $product = $this->product('MSCI World', '10', '900');
        $updater = new InvestmentUpdater($this->user);
        $updater->setManualPrice($product, '80', today()->subDays(40));
        $updater->setManualPrice($product, '100', today()->subDays(3));
        $updater->setManualPrice($product, '110', today());

        $depots  = new InvestmentPortfolio($this->user)->getDepots([$this->depot->id => $this->depot, $this->cash->id => $this->cash], today());
        $this->assertSame([$this->depot->id], array_keys($depots)); // the cash account has no products
        $row     = $depots[$this->depot->id]['products'][0];
        $this->assertSame(0, bccomp('1100', $row['value'], 12));
        $this->assertSame(0, bccomp('200', $row['gain'], 12));
        $this->assertSame(0, bccomp('22.222222222222', $row['gain_pct'], 10));
        $this->assertSame(0, bccomp('100', $row['change'], 12));      // since previous price (100 -> 110)
        $this->assertSame(0, bccomp('10', $row['change_pct'], 12));
        $this->assertSame(0, bccomp('300', $row['change_30'], 12));   // since 30 days ago (80 -> 110)
        $this->assertSame(0, bccomp('37.5', $row['change_30_pct'], 12));
        $total   = $depots[$this->depot->id]['total'];
        $this->assertSame(0, bccomp('1100', $total['value'], 12));
        $this->assertSame(0, bccomp('10', $total['change_pct'], 12));

        $history = InvestmentPortfolio::valueHistory(new Collection([$product]), today()->subDays(10), today());
        // the price from 40 days ago is carried to the start of the range.
        $this->assertSame([today()->subDays(10)->format('Y-m-d'), today()->subDays(3)->format('Y-m-d'), today()->format('Y-m-d')], $history['labels']);
        $values  = array_values($history['sets'][0]['entries']);
        $this->assertSame(0, bccomp('800', $values[0], 12));
        $this->assertSame(0, bccomp('1000', $values[1], 12));
        $this->assertSame(0, bccomp('1100', $values[2], 12));
    }

    public function testPageAndChartShowProducts(): void
    {
        $product = $this->product('MSCI World', '10', '900', 'IE00B4L5Y983', 'EUNL.DE');
        new InvestmentUpdater($this->user)->setManualPrice($product, '110', today());

        $this->get(route('investments.index'))
            ->assertOk()
            ->assertSee('MSCI World')
            ->assertSee('EUNL.DE')
            ->assertSee(route('investments.chart.products', [$this->depot->id]))
        ;
        $json = $this->getJson(route('investments.chart.products', [$this->depot->id]))->assertOk()->json();
        $this->assertSame('MSCI World', $json['datasets'][0]['label']);
        $this->assertSame([], $this->getJson(route('investments.chart.products', [$this->cash->id]))->assertOk()->json());
    }

    public function testUpdateNowButton(): void
    {
        $this->fakeYahoo();
        $this->transfer($this->cash, $this->depot, '1000', today()->subDays(20));
        $this->product('MSCI World', '10', '1000', 'IE00B4L5Y983');

        $this->post(route('investments.update'))->assertRedirect(route('investments.index'))->assertSessionHas('info');
        $this->assertSame(0, bccomp('1012.4', $this->balance($this->depot), 12));
    }

    public function testCommand(): void
    {
        $this->fakeYahoo();
        $this->transfer($this->cash, $this->depot, '1000', today()->subDays(20));
        $this->product('MSCI World', '10', '1000', null, 'EUNL.DE');

        $this->artisan('firefly-iii:investments-update')->assertSuccessful();
        $this->assertSame(0, bccomp('1012.4', $this->balance($this->depot), 12));
    }

    public function testPostRoutesRequireLogin(): void
    {
        auth()->logout();
        $this->post(route('investments.update'))->assertRedirect();
        $this->post(route('investments.products.store'), ['name' => 'x'])->assertRedirect();
        $this->assertSame(0, InvestmentProduct::query()->count());
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->user  = $this->createUser('test@example.com');
        $this->actingAs($this->user);
        $this->depot = $this->createAccount('Scalable Depot', "investment: true\nbroker: Scalable Capital");
        $this->cash  = $this->createAccount('Scalable Balance', "investment: true\nbroker: Scalable Capital");
        $this->other = $this->createAccount('Checking', null);
    }

    private function fakeYahoo(): void
    {
        $chart = static fn (string $symbol, float $price, string $currency): array => ['chart' => ['result' => [[
            'meta'       => ['currency' => $currency, 'symbol' => $symbol, 'exchangeName' => 'GER', 'regularMarketPrice' => $price, 'regularMarketTime' => time(), 'previousClose' => $price - 1],
            'timestamp'  => [time()],
            'indicators' => ['quote' => [['close' => [$price]]]],
        ]], 'error' => null]];
        Http::fake([
            'query1.finance.yahoo.com/v8/finance/chart/EUNL.DE*' => Http::response($chart('EUNL.DE', 101.24, 'EUR')),
            'query1.finance.yahoo.com/v8/finance/chart/AAPL*'    => Http::response($chart('AAPL', 230.5, 'USD')),
            'query1.finance.yahoo.com/v8/finance/chart/*'        => Http::response(['chart' => ['result' => null, 'error' => ['code' => 'Not Found', 'description' => 'No data found, symbol may be delisted']]], 404),
            'query2.finance.yahoo.com/v1/finance/search*'        => Http::response(['quotes' => [
                ['exchange' => 'LSE', 'symbol' => 'SWDA.L', 'quoteType' => 'ETF'],
                ['exchange' => 'FRA', 'symbol' => 'EUNL.F', 'quoteType' => 'ETF'],
                ['exchange' => 'GER', 'symbol' => 'EUNL.DE', 'quoteType' => 'ETF'],
            ], 'news' => []]),
        ]);
    }

    private function product(string $name, string $shares, string $invested, ?string $isin = null, ?string $symbol = null): InvestmentProduct
    {
        /** @var InvestmentProduct */
        return InvestmentProduct::query()->create([
            'user_id'    => $this->user->id,
            'account_id' => $this->depot->id,
            'name'       => $name,
            'isin'       => $isin,
            'symbol'     => $symbol,
            'shares'     => $shares,
            'invested'   => $invested,
        ]);
    }

    private function valueChangeAccount(): Account
    {
        $currency = Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup);
        $id       = (int) Preferences::getForUser($this->user, 'investments_value_change_account_'.$currency->code)->data;

        return Account::query()->findOrFail($id);
    }

    private function balance(Account $account): string
    {
        $balances = Steam::accountsBalancesOptimized(new Collection([$account]), today()->endOfDay(), null, false);

        return (string) ($balances[$account->id]['balance'] ?? '0');
    }

    private function createAccount(string $name, ?string $notes): Account
    {
        /** @var Account $account */
        $account = Account::factory()->for($this->user)->withType(AccountTypeEnum::ASSET)->create(['name' => $name, 'user_group_id' => $this->user->user_group_id]);
        if (null !== $notes) {
            $account->notes()->create(['text' => $notes]);
        }

        return $account;
    }

    private function createUser(string $email): User
    {
        $group = UserGroup::create(['title' => $email]);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $user  = User::create(['email' => $email, 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }

    private function transfer(Account $source, Account $destination, string $amount, Carbon $date): void
    {
        /** @var TransactionCurrency $currency */
        $currency = Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup);
        $type     = TransactionType::query()->where('type', TransactionTypeEnum::TRANSFER->value)->first();
        $group    = TransactionGroup::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'title' => null]);
        $journal  = TransactionJournal::create([
            'user_id'                 => $this->user->id,
            'user_group_id'           => $this->user->user_group_id,
            'transaction_type_id'     => $type->id,
            'transaction_currency_id' => $currency->id,
            'description'             => 'Buy',
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
