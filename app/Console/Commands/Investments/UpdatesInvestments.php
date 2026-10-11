<?php

/**
 * UpdatesInvestments.php
 *
 * Custom addition (not part of upstream Firefly III): "Investments" page.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

namespace FireflyIII\Console\Commands\Investments;

use FireflyIII\Support\Investments\InvestmentUpdater;
use FireflyIII\Support\Investments\Models\InvestmentProduct;
use FireflyIII\User;
use Illuminate\Console\Command;

/**
 * Gets the latest prices from Yahoo Finance and books the change in value of every depot.
 * Run it from cron, for example every 3 days (see custom/README.md).
 */
final class UpdatesInvestments extends Command
{
    protected $description = 'Investments: get the latest prices and book the change in value of every depot.';
    protected $signature   = 'firefly-iii:investments-update {--no-prices : Do not get new prices, only book the value changes}';

    public function handle(): int
    {
        $today   = today(config('app.timezone'));
        $userIds = InvestmentProduct::query()->distinct()->pluck('user_id');
        if (0 === $userIds->count()) {
            $this->info('No investment products yet. Add them on the Investments page.');

            return Command::SUCCESS;
        }
        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (null === $user) {
                continue;
            }
            $this->info(sprintf('User #%d:', $user->id));
            $updater = new InvestmentUpdater($user);
            if (!$this->option('no-prices')) {
                foreach ($updater->updatePrices($today) as $line) {
                    $this->line('  price  '.$line);
                }
            }
            foreach ($updater->revalue($today) as $line) {
                $this->line('  depot  '.$line);
            }
        }

        return Command::SUCCESS;
    }
}
