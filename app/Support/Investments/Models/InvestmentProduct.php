<?php

/**
 * InvestmentProduct.php
 *
 * Custom addition (not part of upstream Firefly III): "Investments" page.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

namespace FireflyIII\Support\Investments\Models;

use FireflyIII\Models\Account;
use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product (stock, ETF, fund) in a depot account.
 *
 * @property int    $id
 * @property int    $user_id
 * @property int    $account_id
 * @property string $name
 * @property ?string $isin
 * @property ?string $symbol   Yahoo Finance symbol, for example "EUNL.DE"
 * @property string $shares
 * @property string $invested  total amount paid for the current shares
 */
class InvestmentProduct extends Model
{
    use ReturnsIntegerIdTrait;

    protected $fillable = ['user_id', 'account_id', 'name', 'isin', 'symbol', 'shares', 'invested'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(InvestmentPrice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function latestPrice(): ?InvestmentPrice
    {
        /** @var null|InvestmentPrice */
        return $this->prices()->orderBy('date', 'desc')->first();
    }

    protected function casts(): array
    {
        return [
            'user_id'    => 'integer',
            'account_id' => 'integer',
            'shares'     => 'string',
            'invested'   => 'string',
        ];
    }
}
