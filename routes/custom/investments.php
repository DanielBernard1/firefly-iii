<?php

/**
 * investments.php
 *
 * Custom addition (not part of upstream Firefly III): routes for the "Investments" page.
 * This file is loaded from the last line of routes/web.php.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Investments page. The POST routes only change the products and prices of the investments page,
// and "update" books the change in value of each depot (see InvestmentUpdater).
Route::group(
    ['middleware' => 'user-full-auth', 'namespace' => 'FireflyIII\Http\Controllers\Investments', 'prefix' => 'investments', 'as' => 'investments.'],
    static function (): void {
        Route::get('', ['uses' => 'IndexController@index', 'as' => 'index']);
        Route::get('chart/balances', ['uses' => 'ChartController@balances', 'as' => 'chart.balances']);
        Route::get('chart/allocation/account', ['uses' => 'ChartController@allocationByAccount', 'as' => 'chart.allocation.account']);
        Route::get('chart/allocation/broker', ['uses' => 'ChartController@allocationByBroker', 'as' => 'chart.allocation.broker']);
        Route::get('chart/products/{accountId}', ['uses' => 'ChartController@productValues', 'as' => 'chart.products'])->where('accountId', '[0-9]+');

        Route::get('products/create', ['uses' => 'ProductController@create', 'as' => 'products.create']);
        Route::post('products', ['uses' => 'ProductController@store', 'as' => 'products.store']);
        Route::get('products/{productId}/edit', ['uses' => 'ProductController@edit', 'as' => 'products.edit'])->where('productId', '[0-9]+');
        Route::post('products/{productId}', ['uses' => 'ProductController@update', 'as' => 'products.update'])->where('productId', '[0-9]+');
        Route::post('products/{productId}/delete', ['uses' => 'ProductController@destroy', 'as' => 'products.destroy'])->where('productId', '[0-9]+');
        Route::post('update', ['uses' => 'ProductController@updateNow', 'as' => 'update']);
    }
);
