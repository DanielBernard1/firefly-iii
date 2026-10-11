<?php

/**
 * InvestmentPrice.php
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

use Carbon\Carbon;
use FireflyIII\Support\Models\ReturnsIntegerIdTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price of a product on one day. "shares" and "value" are stored too, so the value history
 * stays correct after you change the number of shares.
 *
 * @property int    $id
 * @property int    $investment_product_id
 * @property Carbon $date
 * @property string $price
 * @property string $shares
 * @property string $value
 * @property string $source "yahoo" or "manual"
 */
class InvestmentPrice extends Model
{
    use ReturnsIntegerIdTrait;

    protected $fillable = ['investment_product_id', 'date', 'price', 'shares', 'value', 'source'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(InvestmentProduct::class, 'investment_product_id');
    }

    protected function casts(): array
    {
        return [
            'investment_product_id' => 'integer',
            'date'                  => 'date',
            'price'                 => 'string',
            'shares'                => 'string',
            'value'                 => 'string',
        ];
    }
}
