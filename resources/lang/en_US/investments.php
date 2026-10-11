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
    'allocation_product'   => 'Allocation by product',
    'allocation_product_note' => 'Based on the latest prices.',
    'allocation_product_missing' => 'Not shown because they have no price yet: :products.',
    'allocation_note'      => 'Based on the current balances. Accounts with a balance of zero or less are not shown.',
    'balance_on'           => 'Balance on :date',
    'no_investments_title' => 'No investment accounts yet',
    'no_investments_text'  => 'An asset account shows up here when its notes contain a line "investment: true". You can add these optional lines as well:',
    'no_investments_link'  => 'Go to your asset accounts',

    // products and prices
    'product'                    => 'Product',
    'product_name'               => 'Name',
    'depot'                      => 'Depot (investment account)',
    'symbol'                     => 'Yahoo Finance symbol',
    'symbol_help'                => 'Optional. Leave empty and a euro listing is looked up from the ISIN (XETRA first, then Frankfurt, then Amsterdam, Paris, Milan). Or enter one yourself, for example EUNL.DE.',
    'price'                      => 'Price',
    'value'                      => 'Value',
    'invested'                   => 'Amount paid',
    'invested_help'              => 'What you paid in total for these shares. Used for the gain or loss.',
    'gain'                       => 'Gain / loss',
    'change_last'                => 'Since previous price',
    'manual'                     => 'entered by hand',
    'no_price_yet'               => 'No price yet. Click "Update prices now" or enter a price by hand.',
    'product_chart'              => 'Value per product (:start – :end)',
    'add_product'                => 'Add product',
    'edit_product'               => 'Change product',
    'delete_product'             => 'Delete product',
    'delete_help'                => 'Also deletes its price history. Transactions in Firefly III are not touched.',
    'delete_confirm'             => 'Delete this product and its price history?',
    'save'                       => 'Save',
    'cancel'                     => 'Cancel',
    'manual_price'               => 'Price today (optional)',
    'manual_price_help'          => 'Only needed when Yahoo Finance has no price for this product, for example some funds.',
    'latest_price'               => 'Latest price: :price on :date.',
    'shares_reminder'            => 'After buying or selling: change the shares and amount paid here, and enter the money you moved as a transfer in Firefly III (for example from your Scalable balance to your Scalable depot).',
    'no_depots'                  => 'You have no investment accounts yet. Put a line "investment: true" in the notes of an asset account first.',
    'invalid_number'             => 'Could not read this as a number.',
    'invalid_depot'              => 'Pick one of your investment accounts.',
    'product_stored'             => 'Added ":name".',
    'product_updated'            => 'Saved ":name".',
    'product_deleted'            => 'Deleted ":name".',
    'update_now'                 => 'Update prices now',
    'update_done'                => 'Update finished. Results:',
    'update_explained'           => 'Prices are also updated automatically if you set up the scheduled update. Value changes are booked as transfers to or from the account "Investment value changes", which is not part of your net worth.',

    // the automatic value-change transfers
    'value_change_account'       => 'Investment value changes',
    'value_change_account_notes' => 'Created by the Investments page. Changes in the market value of your depots are booked as transfers between this account and the depot. This account is not included in net worth, so your net worth shows the market value of your depots, and income, expenses and budgets are not affected. Please do not use this account for anything else.',
    'value_change_description'   => 'Value change :depot',
    'value_change_notes'         => 'Market value change, booked automatically by the Investments page:',
];
