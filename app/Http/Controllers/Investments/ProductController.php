<?php

/**
 * ProductController.php
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

use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Support\Investments\InvestmentNoteParser;
use FireflyIII\Support\Investments\InvestmentOverview;
use FireflyIII\Support\Investments\InvestmentUpdater;
use FireflyIII\Support\Investments\Models\InvestmentProduct;
use FireflyIII\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Add, change and delete the products (stocks, ETFs, funds) in a depot, and update prices.
 */
final class ProductController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(static function ($request, $next) {
            app('view')->share('title', (string) trans('investments.investments'));
            app('view')->share('mainTitleIcon', 'bi-graph-up-arrow');

            return $next($request);
        });
    }

    public function create(Request $request): Factory|View
    {
        return view('investments.product', [
            'product'  => null,
            'accounts' => $this->depotChoices(),
            'selected' => (int) $request->get('account'),
        ]);
    }

    public function edit(int $productId): Factory|View
    {
        $product = $this->findProduct($productId);

        return view('investments.product', [
            'product'  => $product,
            'accounts' => $this->depotChoices(),
            'selected' => $product->account_id,
            'latest'   => $product->latestPrice(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data              = $this->validated($request);
        $data['user_id']   = $this->user()->id;
        $product           = InvestmentProduct::query()->create($data);
        session()->flash('success', (string) trans('investments.product_stored', ['name' => $product->name]));

        return redirect(route('investments.index'));
    }

    public function update(Request $request, int $productId): RedirectResponse
    {
        $product = $this->findProduct($productId);
        $data    = $this->validated($request);
        // a new ISIN needs a new symbol, unless one was entered.
        if ($data['isin'] !== $product->isin && $data['symbol'] === $product->symbol) {
            $data['symbol'] = null;
        }
        $product->update($data);

        $price   = trim((string) $request->get('manual_price'));
        if ('' !== $price) {
            $number = InvestmentNoteParser::normalizeNumber($price);
            if (null === $number || 1 !== bccomp($number, '0', 12)) {
                throw ValidationException::withMessages(['manual_price' => (string) trans('investments.invalid_number')]);
            }
            new InvestmentUpdater($this->user())->setManualPrice($product, $number, today(config('app.timezone')));
        }
        session()->flash('success', (string) trans('investments.product_updated', ['name' => $product->name]));

        return redirect(route('investments.index'));
    }

    public function destroy(int $productId): RedirectResponse
    {
        $product = $this->findProduct($productId);
        $name    = $product->name;
        // not every database deletes the prices by itself (foreign keys may be off), so do it here.
        $product->prices()->delete();
        $product->delete();
        session()->flash('success', (string) trans('investments.product_deleted', ['name' => $name]));

        return redirect(route('investments.index'));
    }

    /**
     * The "update now" button: same as the command that runs from cron.
     */
    public function updateNow(): RedirectResponse
    {
        $updater  = new InvestmentUpdater($this->user());
        $today    = today(config('app.timezone'));
        $messages = array_merge($updater->updatePrices($today), $updater->revalue($today));
        session()->flash('info', array_merge([(string) trans('investments.update_done')], $messages));

        return redirect(route('investments.index'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(Request $request): array
    {
        $request->validate([
            'account_id' => 'required|integer',
            'name'       => 'required|string|max:255',
            'isin'       => 'nullable|string|max:20',
            'symbol'     => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-^=]+$/'],
            'shares'     => 'required|string|max:40',
            'invested'   => 'nullable|string|max:40',
        ]);
        $errors   = [];
        $accounts = $this->depotChoices();
        $account  = (int) $request->get('account_id');
        if (!array_key_exists($account, $accounts)) {
            $errors['account_id'] = (string) trans('investments.invalid_depot');
        }
        $isin     = strtoupper(str_replace(' ', '', trim((string) $request->get('isin'))));
        if ('' !== $isin && !InvestmentNoteParser::isValidIsin($isin)) {
            $errors['isin'] = (string) trans('investments.invalid_isin');
        }
        $shares   = InvestmentNoteParser::normalizeNumber(trim((string) $request->get('shares')));
        if (null === $shares || -1 === bccomp($shares, '0', 12)) {
            $errors['shares'] = (string) trans('investments.invalid_number');
        }
        $invested = trim((string) $request->get('invested'));
        $invested = '' === $invested ? '0' : InvestmentNoteParser::normalizeNumber($invested);
        if (null === $invested || -1 === bccomp($invested, '0', 12)) {
            $errors['invested'] = (string) trans('investments.invalid_number');
        }
        if (count($errors) > 0) {
            throw ValidationException::withMessages($errors);
        }
        $symbol   = strtoupper(trim((string) $request->get('symbol')));

        return [
            'account_id' => $account,
            'name'       => trim((string) $request->get('name')),
            'isin'       => '' === $isin ? null : $isin,
            'symbol'     => '' === $symbol ? null : $symbol,
            'shares'     => $shares,
            'invested'   => $invested,
        ];
    }

    /**
     * The investment accounts you can put products in.
     *
     * @return array<int, Account>
     */
    private function depotChoices(): array
    {
        return array_map(static fn (array $item): Account => $item['account'], new InvestmentOverview($this->user())->getInvestmentAccounts());
    }

    private function findProduct(int $productId): InvestmentProduct
    {
        /** @var null|InvestmentProduct $product */
        $product = InvestmentProduct::query()->where('user_id', $this->user()->id)->find($productId);
        if (null === $product) {
            throw new NotFoundHttpException();
        }

        return $product;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
