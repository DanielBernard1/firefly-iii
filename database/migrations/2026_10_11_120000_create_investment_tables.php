<?php

/*
 * Custom addition (not part of upstream Firefly III): tables for the "Investments" page.
 *
 * investment_products: the products (stocks, ETFs, funds) held in a depot account.
 * investment_prices:   the price history of each product, written by "php artisan firefly-iii:investments-update".
 *
 * Both tables are new. No existing table is changed. The official Firefly III image ignores them.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function down(): void
    {
        Schema::dropIfExists('investment_prices');
        Schema::dropIfExists('investment_products');
    }

    public function up(): void
    {
        if (!Schema::hasTable('investment_products')) {
            Schema::create('investment_products', static function (Blueprint $table): void {
                $table->increments('id');
                $table->timestamps();
                $table->integer('user_id', false, true);
                $table->integer('account_id', false, true);
                $table->string('name', 255);
                $table->string('isin', 12)->nullable();
                $table->string('symbol', 32)->nullable();
                $table->decimal('shares', 32, 12)->default('0');
                $table->decimal('invested', 32, 12)->default('0');

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            });
        }
        if (!Schema::hasTable('investment_prices')) {
            Schema::create('investment_prices', static function (Blueprint $table): void {
                $table->increments('id');
                $table->timestamps();
                $table->integer('investment_product_id', false, true);
                $table->date('date');
                $table->decimal('price', 32, 12);
                $table->decimal('shares', 32, 12);
                $table->decimal('value', 32, 12);
                $table->string('source', 16);

                $table->foreign('investment_product_id')->references('id')->on('investment_products')->onDelete('cascade');
                $table->unique(['investment_product_id', 'date']);
            });
        }
    }
};
