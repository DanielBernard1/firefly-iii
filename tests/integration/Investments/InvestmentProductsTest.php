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
use Illuminate\Http\Client\Request;
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
    private array $requested = [];
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

    public function testIsinLookupFindsXetraWhenIsinSearchOnlyReturnsLondon(): void
    {
        // the case from the real server: Yahoo's ISIN search returns London (USD/GBp) and Amsterdam only.
        $this->fakeYahoo();
        $listing = new YahooPriceSource()->findListing('IE00B4L5Y983');
        $this->assertSame('EUNL.DE', $listing['symbol']);
        $this->assertSame('EUR', $listing['quote']['currency']);
        // a fund with a different name (IQQW.DE, the distributing share class) was not considered.
        $this->assertNotContains('IQQW.DE', $this->requestedSymbols());
    }

    public function testIsinLookupPrefersXetraInIsinSearchResults(): void
    {
        $this->fakeYahoo(['IE00B4L5Y983' => [
            $this->quote('IWDA.L', 'LSE'), $this->quote('EUNL.F', 'FRA'), $this->quote('IWDA.AS', 'AMS'), $this->quote('EUNL.DE', 'GER'),
        ]]);
        $this->assertSame('EUNL.DE', new YahooPriceSource()->findSymbol('IE00B4L5Y983'));
    }

    public function testIsinLookupFallsBackToOtherEuroExchanges(): void
    {
        // no German listing at all: Amsterdam is next.
        $this->fakeYahoo();
        $this->assertSame('ASML.AS', new YahooPriceSource()->findSymbol('NL0010273215'));
    }

    public function testIsinLookupSkipsListingWhosePriceIsNotInEuro(): void
    {
        $this->fakeYahoo(['IE000TEST001' => [$this->quote('XUSD.DE', 'GER', 'Test fund'), $this->quote('XUSD.F', 'FRA', 'Test fund')]]);
        $this->assertSame('XUSD.F', new YahooPriceSource()->findSymbol('IE000TEST001'));
    }

    public function testIsinLookupWithoutEuroListingGivesClearMessage(): void
    {
        $this->fakeYahoo();

        try {
            new YahooPriceSource()->findSymbol('US0000000001');
            $this->fail('Expected an exception.');
        } catch (FireflyException $e) {
            $this->assertStringContainsString('no listing in EUR', $e->getMessage());
            $this->assertStringContainsString('found: FOO', $e->getMessage());
            $this->assertStringContainsString('.DE', $e->getMessage());
        }
    }

    public function testRankSymbols(): void
    {
        $quotes = [
            $this->quote('X.MI', 'MIL'), $this->quote('X.L', 'LSE'), $this->quote('X.PA', 'PAR'), $this->quote('X', 'NMS'),
            $this->quote('X.AS', 'AMS'), $this->quote('X.F', 'FRA'), $this->quote('X.SW', 'EBS'), $this->quote('X.DE', 'GER'),
        ];
        $this->assertSame(['X.DE', 'X.F', 'X.AS', 'X.PA', 'X.MI'], YahooPriceSource::rankSymbols($quotes));
        $this->assertSame([], YahooPriceSource::rankSymbols([$this->quote('X.L', 'LSE'), $this->quote('X', 'NMS')]));
    }

    public function testWrongSymbolIsReplacedByEuroListing(): void
    {
        // a product saved with IWDA.L by the old lookup gets EUNL.DE on the next update.
        $this->fakeYahoo();
        $product  = $this->product('MSCI World', '10', '900', 'IE00B4L5Y983', 'IWDA.L');
        $messages = new InvestmentUpdater($this->user)->updatePrices(today());
        $product->refresh();
        $this->assertSame('EUNL.DE', $product->symbol);
        $this->assertStringContainsString('symbol changed from IWDA.L', $messages[0]);
        $this->assertSame(0, bccomp('101.24', $product->latestPrice()->price, 6));
    }

    public function testLookupCommand(): void
    {
        $this->fakeYahoo();
        $this->artisan('firefly-iii:investments-update', ['--lookup' => 'IE00B4L5Y983'])
            ->expectsOutputToContain('EUNL.DE')
            ->assertSuccessful()
        ;
        $this->artisan('firefly-iii:investments-update', ['--lookup' => 'US0000000001'])->assertFailed();
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

    public function testDepotTotalAmountPaidIncludesProductsWithoutPrice(): void
    {
        // the case from the real server: one product, no price yet, amount paid 6500.
        $this->product('MSCI World', '50', '6500');
        $depots = new InvestmentPortfolio($this->user)->getDepots([$this->depot->id => $this->depot], today());
        $total  = $depots[$this->depot->id]['total'];
        $this->assertSame(0, bccomp('6500', $total['invested'], 12));
        $this->assertSame(0, bccomp('0', $total['value'], 12));
        $this->assertNull($total['gain_pct']);
        $this->get(route('investments.index'))->assertOk()->assertSee('6,500.00');

        // with a second product that has a price: amount paid counts both, gain % only the priced one.
        $priced = $this->product('Apple', '10', '1000');
        new InvestmentUpdater($this->user)->setManualPrice($priced, '110', today());
        $total  = new InvestmentPortfolio($this->user)->getDepots([$this->depot->id => $this->depot], today())[$this->depot->id]['total'];
        $this->assertSame(0, bccomp('7500', $total['invested'], 12));
        $this->assertSame(0, bccomp('1100', $total['value'], 12));
        $this->assertSame(0, bccomp('100', $total['gain'], 12));
        $this->assertSame(0, bccomp('10', $total['gain_pct'], 12));
    }

    public function testAllocationByProduct(): void
    {
        $updater = new InvestmentUpdater($this->user);
        $world   = $this->product('MSCI World', '10', '1000');
        $apple   = $this->product('Apple', '2', '400');
        $this->product('No price yet', '5', '500');
        // the same name in another depot gets the depot name added.
        $other   = InvestmentProduct::query()->create(['user_id' => $this->user->id, 'account_id' => $this->cash->id, 'name' => 'MSCI World', 'shares' => '1', 'invested' => '100']);
        $updater->setManualPrice($world, '120', today());   // 1200
        $updater->setManualPrice($apple, '250', today());   // 500
        $updater->setManualPrice($other, '120', today());   // 120

        $accounts = [$this->depot->id => $this->depot, $this->cash->id => $this->cash];
        $depots   = new InvestmentPortfolio($this->user)->getDepots($accounts, today());
        $values   = InvestmentPortfolio::allocation($depots);
        $this->assertSame(['MSCI World (Scalable Depot)', 'Apple', 'MSCI World (Scalable Balance)'], array_keys($values));
        $this->assertSame(0, bccomp('1200', $values['MSCI World (Scalable Depot)'], 12));
        $this->assertSame(['No price yet'], InvestmentPortfolio::withoutPrice($depots));

        // overall chart, per-depot chart, and the note on the page.
        $json     = $this->getJson(route('investments.chart.allocation.product'))->assertOk()->json();
        $this->assertSame(['MSCI World (Scalable Depot)', 'Apple', 'MSCI World (Scalable Balance)'], $json['labels']);
        $json     = $this->getJson(route('investments.chart.products.allocation', [$this->depot->id]))->assertOk()->json();
        $this->assertSame(['MSCI World', 'Apple'], $json['labels']);
        $this->assertSame([], $this->getJson(route('investments.chart.products.allocation', [$this->other->id]))->assertOk()->json());
        $this->get(route('investments.index'))
            ->assertOk()
            ->assertSee('Allocation by product')
            ->assertSee('Not shown because they have no price yet: No price yet.')
            ->assertSee(route('investments.chart.products.allocation', [$this->depot->id]))
        ;
    }

    public function testAllocationByProductWithoutPrices(): void
    {
        $this->product('No price yet', '5', '500');
        $this->assertSame([], $this->getJson(route('investments.chart.allocation.product'))->assertOk()->json());
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

    /**
     * Simulates Yahoo Finance with answers in the same format as the real one.
     *
     * @param array<string, array<int, array<string, mixed>>> $searches extra or replaced search answers, by query
     */
    private function fakeYahoo(array $searches = []): void
    {
        $world     = 'iShares Core MSCI World UCITS ETF USD (Acc)';
        $searches  = array_merge([
            // only London and Amsterdam, like the real search for this ISIN
            'IE00B4L5Y983'                                   => [$this->quote('IWDA.L', 'LSE', $world), $this->quote('SWDA.L', 'LSE', $world), $this->quote('IWDA.AS', 'AMS', $world)],
            $world                                           => [
                $this->quote('IWDA.L', 'LSE', $world), $this->quote('IQQW.DE', 'GER', 'iShares MSCI World UCITS ETF (Dist)'),
                $this->quote('EUNL.F', 'FRA', $world), $this->quote('IWDA.AS', 'AMS', $world), $this->quote('EUNL.DE', 'GER', $world),
            ],
            'NL0010273215'                                   => [$this->quote('ASML', 'NMS', 'ASML Holding N.V.'), $this->quote('ASML.AS', 'AMS', 'ASML Holding N.V.')],
            'ASML Holding N.V.'                              => [$this->quote('ASML', 'NMS', 'ASML Holding N.V.'), $this->quote('ASML.AS', 'AMS', 'ASML Holding N.V.')],
            'US0000000001'                                   => [$this->quote('FOO', 'NMS', 'Foo Inc.')],
            'Foo Inc.'                                       => [$this->quote('FOO', 'NMS', 'Foo Inc.')],
        ], $searches);
        $prices    = [
            'EUNL.DE' => [101.24, 'EUR'], 'EUNL.F' => [101.2, 'EUR'], 'IWDA.AS' => [101.3, 'EUR'], 'IWDA.L' => [118.5, 'USD'],
            'SWDA.L'  => [8820.0, 'GBp'], 'IQQW.DE' => [70.1, 'EUR'], 'ASML.AS' => [640.5, 'EUR'], 'ASML' => [745.0, 'USD'],
            'FOO'     => [10.0, 'USD'], 'XUSD.DE' => [12.0, 'USD'], 'XUSD.F' => [11.0, 'EUR'], 'AAPL' => [230.5, 'USD'],
        ];
        $this->requested = [];
        Http::fake(function (Request $request) use ($searches, $prices) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (str_contains($url, '/v1/finance/search')) {
                return Http::response(['explains' => [], 'count' => count($searches[$query['q']] ?? []), 'quotes' => $searches[$query['q']] ?? [], 'news' => []]);
            }
            $symbol            = rawurldecode(explode('?', substr($url, strrpos($url, '/') + 1))[0]);
            $this->requested[] = $symbol;
            if (!array_key_exists($symbol, $prices)) {
                return Http::response(['chart' => ['result' => null, 'error' => ['code' => 'Not Found', 'description' => 'No data found, symbol may be delisted']]], 404);
            }
            [$price, $currency] = $prices[$symbol];

            return Http::response(['chart' => ['result' => [[
                'meta'       => ['currency' => $currency, 'symbol' => $symbol, 'regularMarketPrice' => $price, 'regularMarketTime' => time(), 'previousClose' => $price - 1],
                'timestamp'  => [time()],
                'indicators' => ['quote' => [['close' => [$price]]]],
            ]], 'error' => null]]);
        });
    }

    /**
     * One result of Yahoo's search, in the same format as the real one.
     */
    private function quote(string $symbol, string $exchange, string $name = 'Some product'): array
    {
        return ['exchange' => $exchange, 'shortname' => $name, 'quoteType' => 'ETF', 'symbol' => $symbol, 'index' => 'quotes', 'score' => 20000, 'typeDisp' => 'ETF', 'longname' => $name, 'isYahooFinance' => true];
    }

    /**
     * @return array<int, string> the symbols whose price was asked for
     */
    private function requestedSymbols(): array
    {
        return $this->requested;
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
