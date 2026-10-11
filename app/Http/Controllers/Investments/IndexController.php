<?php

/**
 * IndexController.php
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
use FireflyIII\Support\Investments\InvestmentOverview;
use FireflyIII\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Shows the "Investments" page. Read-only: it never changes any data.
 */
final class IndexController extends Controller
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

    public function index(): Factory|View
    {
        /** @var User $user */
        $user     = auth()->user();
        $overview = new InvestmentOverview($user)->getOverview(today(config('app.timezone')));

        // the date range for the balance chart is the range selected at the top of the page, like elsewhere in Firefly III.
        $start    = clone session('start', today(config('app.timezone'))->startOfMonth());
        $end      = clone session('end', today(config('app.timezone'))->endOfMonth());

        return view('investments.index', ['overview' => $overview, 'start' => $start, 'end' => $end]);
    }
}
