<?php

/**
 * investments.php
 *
 * Custom addition (not part of upstream Firefly III): texts for the "Investments" page.
 * Other languages fall back to these English texts. To translate, copy this file to
 * resources/lang/<your language>/investments.php (for example de_DE) and translate the values.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

declare(strict_types=1);

return [
    'investments'          => 'Investments',
    'overview'             => 'Overview',
    'account'              => 'Account',
    'broker'               => 'Broker',
    'isin'                 => 'ISIN',
    'shares'               => 'Shares',
    'current_balance'      => 'Current balance',
    'share_of_total'       => '% of total',
    'change_since'         => 'Change vs. :days days ago',
    'total'                => 'Total',
    'no_broker'            => '(no broker)',
    'invalid_isin'         => 'This does not look like a valid ISIN.',
    'invalid_shares'       => 'Could not read this as a number.',
    'converted_note'       => 'Your investment accounts use different currencies. Totals, percentages and allocations are converted to :currency using the exchange rates in Firefly III.',
    'balance_chart'        => 'Balance over time (:start – :end)',
    'allocation_account'   => 'Allocation by account',
    'allocation_broker'    => 'Allocation by broker',
    'allocation_note'      => 'Based on the current balances. Accounts with a balance of zero or less are not shown.',
    'balance_on'           => 'Balance on :date',
    'no_investments_title' => 'No investment accounts yet',
    'no_investments_text'  => 'An asset account shows up here when its notes contain a line "investment: true". You can add these optional lines as well:',
    'no_investments_link'  => 'Go to your asset accounts',
];
