<?php

/**
 * InvestmentUpdater.php
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
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Investments\Models\InvestmentPrice;
use FireflyIII\Support\Investments\Models\InvestmentProduct;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Updates prices and books the change in value of each depot.
 *
 * 1. updatePrices(): gets the latest price of every product from Yahoo Finance and saves it.
 * 2. revalue(): for every depot account, compares "shares x latest price" of its products with the
 *    account's balance in Firefly III, and books the difference as a TRANSFER between the depot and
 *    the account "Investment value changes". That account is not included in net worth, so the
 *    net worth goes up and down with the market value, while income, expenses and budgets are not
 *    affected (a transfer between your own accounts is neither income nor an expense).
 */
final class InvestmentUpdater
{
    public const string TAG                = 'investment-value-change';
    private const string PREFERENCE_PREFIX = 'investments_value_change_account_';

    private readonly AccountRepositoryInterface $accounts;
    private readonly TransactionCurrency $primary;
    private YahooPriceSource $source;

    public function __construct(private readonly User $user)
    {
        $this->accounts = app(AccountRepositoryInterface::class);
        $this->accounts->setUser($user);
        $this->primary  = Amount::getPrimaryCurrencyByUserGroup($user->userGroup);
        $this->source   = new YahooPriceSource();
    }

    public function setSource(YahooPriceSource $source): void
    {
        $this->source = $source;
    }

    /**
     * @return Collection<int, InvestmentProduct>
     */
    public function getProducts(): Collection
    {
        return InvestmentProduct::query()
            ->where('user_id', $this->user->id)
            ->whereHas('account', static fn ($query) => $query->whereNull('deleted_at'))
            ->orderBy('name')
            ->get()
        ;
    }

    /**
     * Gets a price for every product that has a Yahoo symbol or an ISIN.
     * A product without a symbol gets one looked up from its ISIN, and the symbol is saved.
     *
     * @return array<int, string> one message per product
     */
    public function updatePrices(Carbon $date): array
    {
        $messages = [];
        foreach ($this->getProducts() as $product) {
            try {
                $messages[] = $this->updatePrice($product, $date);
            } catch (FireflyException $e) {
                Log::warning(sprintf('Investments: %s', $e->getMessage()));
                $messages[] = sprintf('%s: %s', $product->name, $e->getMessage());
            }
        }

        return $messages;
    }

    /**
     * Saves a price that you entered yourself.
     */
    public function setManualPrice(InvestmentProduct $product, string $price, Carbon $date): InvestmentPrice
    {
        return $this->storePrice($product, $price, $date, 'manual');
    }

    /**
     * Books the difference between market value and balance for every depot.
     *
     * @return array<int, string> one message per depot
     */
    public function revalue(Carbon $date): array
    {
        $messages = [];
        $date     = $date->copy()->endOfDay();
        foreach ($this->getProducts()->groupBy('account_id') as $products) {
            /** @var Account $depot */
            $depot = $products->first()->account;

            try {
                $messages[] = $this->revalueDepot($depot, $products, $date);
            } catch (FireflyException $e) {
                Log::warning(sprintf('Investments: %s', $e->getMessage()));
                $messages[] = sprintf('%s: %s', $depot->name, $e->getMessage());
            }
        }

        return $messages;
    }

    /**
     * The current value of a depot: shares x latest price, per product. Null when a product has no price yet.
     *
     * @param Collection<int, InvestmentProduct> $products
     */
    public static function marketValue(Collection $products, Carbon $date): ?string
    {
        $total = '0';
        foreach ($products as $product) {
            if (0 === bccomp($product->shares, '0', 12)) {
                continue;
            }

            /** @var null|InvestmentPrice $price */
            $price = $product->prices()->whereDate('date', '<=', $date->format('Y-m-d'))->orderBy('date', 'desc')->first();
            if (null === $price) {
                return null;
            }
            $total = bcadd($total, bcmul($product->shares, $price->price, 12), 12);
        }

        return $total;
    }

    /**
     * @throws FireflyException
     */
    private function updatePrice(InvestmentProduct $product, Carbon $date): string
    {
        if (null === $product->symbol || '' === $product->symbol) {
            if (null === $product->isin || '' === $product->isin) {
                return sprintf('%s: no symbol and no ISIN, enter the price yourself.', $product->name);
            }
            $symbol = $this->source->findSymbol($product->isin);
            if (null === $symbol) {
                throw new FireflyException(sprintf('Yahoo Finance does not know ISIN %s. Enter a symbol or the price yourself.', $product->isin));
            }
            $product->symbol = $symbol;
            $product->save();
        }
        $quote    = $this->source->getPrice((string) $product->symbol);
        $currency = $this->accounts->getAccountCurrency($product->account) ?? $this->primary;
        if ($quote['currency'] !== $currency->code) {
            throw new FireflyException(sprintf(
                'The price of %s is in %s, but the depot is in %s. Use a symbol from a German exchange (for example ending in .DE).',
                $product->symbol,
                $quote['currency'],
                $currency->code
            ));
        }
        $this->storePrice($product, $quote['price'], $date, 'yahoo');

        return sprintf('%s (%s): %s %s', $product->name, $product->symbol, $quote['price'], $quote['currency']);
    }

    private function storePrice(InvestmentProduct $product, string $price, Carbon $date, string $source): InvestmentPrice
    {
        /** @var InvestmentPrice */
        return InvestmentPrice::query()->updateOrCreate(
            ['investment_product_id' => $product->id, 'date' => $date->copy()->startOfDay()],
            [
                'price'  => $price,
                'shares' => $product->shares,
                'value'  => bcmul($product->shares, $price, 12),
                'source' => $source,
            ]
        );
    }

    /**
     * @param Collection<int, InvestmentProduct> $products
     *
     * @throws FireflyException
     */
    private function revalueDepot(Account $depot, Collection $products, Carbon $date): string
    {
        $market     = self::marketValue($products, $date);
        if (null === $market) {
            return sprintf('%s: not every product has a price yet, nothing booked.', $depot->name);
        }
        $currency   = $this->accounts->getAccountCurrency($depot) ?? $this->primary;
        $balances   = Steam::accountsBalancesOptimized(new Collection([$depot]), $date, $this->primary, false);
        $balance    = (string) ($balances[$depot->id]['balance'] ?? '0');
        $difference = Steam::bcround(bcsub($market, $balance, 12), $currency->decimal_places);
        if (0 === bccomp($difference, '0', 12)) {
            return sprintf('%s: value unchanged (%s %s).', $depot->name, Steam::bcround($market, 2), $currency->code);
        }
        $other      = $this->getValueChangeAccount($currency);
        $positive   = 1 === bccomp($difference, '0', 12);
        $this->bookTransfer(
            $positive ? $other : $depot,
            $positive ? $depot : $other,
            Steam::positive($difference),
            $currency,
            $date,
            (string) trans('investments.value_change_description', ['depot' => $depot->name]),
            $this->describe($products, $date)
        );

        return sprintf('%s: booked %s %s (value now %s %s).', $depot->name, $difference, $currency->code, Steam::bcround($market, 2), $currency->code);
    }

    /**
     * The asset account that the value changes are booked against. It is created when needed,
     * and it is not included in net worth. There is one per currency.
     */
    private function getValueChangeAccount(TransactionCurrency $currency): Account
    {
        $key        = self::PREFERENCE_PREFIX.$currency->code;
        $preference = Preferences::getForUser($this->user, $key);
        $accountId  = null === $preference ? 0 : (int) $preference->data;
        $account    = 0 === $accountId ? null : $this->accounts->find($accountId);
        if ($account instanceof Account && AccountTypeEnum::ASSET->value === $account->accountType->type) {
            return $account;
        }
        $name       = (string) trans('investments.value_change_account');
        if ($currency->code !== $this->primary->code) {
            $name = sprintf('%s (%s)', $name, $currency->code);
        }
        $account    = $this->accounts->store([
            'name'              => $name,
            'account_type_name' => AccountTypeEnum::ASSET->value,
            'account_type_id'   => null,
            'account_role'      => 'savingAsset',
            'currency_id'       => $currency->id,
            'include_net_worth' => false,
            'active'            => true,
            'iban'              => null,
            'virtual_balance'   => null,
            'notes'             => (string) trans('investments.value_change_account_notes'),
        ]);
        Preferences::setForUser($this->user, $key, $account->id);

        return $account;
    }

    /**
     * @throws FireflyException
     */
    private function bookTransfer(Account $source, Account $destination, string $amount, TransactionCurrency $currency, Carbon $date, string $description, string $notes): TransactionGroup
    {
        /** @var TransactionGroupRepositoryInterface $repository */
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($this->user);

        return $repository->store([
            'user'                    => $this->user,
            'user_group'              => $this->user->userGroup,
            'group_title'             => null,
            'apply_rules'             => false,
            'fire_webhooks'           => false,
            'error_if_duplicate_hash' => false,
            'transactions'            => [[
                'type'           => 'transfer',
                'date'           => $date->copy()->startOfDay(),
                'description'    => $description,
                'amount'         => $amount,
                'currency_id'    => $currency->id,
                'source_id'      => $source->id,
                'destination_id' => $destination->id,
                'tags'           => [self::TAG],
                'notes'          => $notes,
                'reconciled'     => false,
            ]],
        ]);
    }

    /**
     * The notes of a value change: shares and price of every product.
     *
     * @param Collection<int, InvestmentProduct> $products
     */
    private function describe(Collection $products, Carbon $date): string
    {
        $lines = [(string) trans('investments.value_change_notes')];
        foreach ($products as $product) {
            /** @var null|InvestmentPrice $price */
            $price   = $product->prices()->whereDate('date', '<=', $date->format('Y-m-d'))->orderBy('date', 'desc')->first();
            $lines[] = sprintf(
                '- %s: %s x %s = %s (%s)',
                $product->name,
                InvestmentFormatter::number($product->shares, 6, 'en_US'),
                null === $price ? '?' : Steam::bcround($price->price, 4),
                null === $price ? '?' : Steam::bcround(bcmul($product->shares, $price->price, 12), 2),
                null === $price ? '-' : $price->date->format('Y-m-d')
            );
        }

        return implode("\n", $lines);
    }
}
