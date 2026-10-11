{{-- Custom addition (not part of upstream Firefly III): the "Investments" page. --}}
@extends('layout.v3.session')
@section('breadcrumbs')
    <ol class="breadcrumb float-sm-end">
        <li class="breadcrumb-item"><a href="{{ route('index') }}">{{ trans('breadcrumbs.home') }}</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ trans('investments.investments') }}</li>
    </ol>
@endsection
@section('content')
    @use('FireflyIII\Support\Investments\InvestmentFormatter')
    @php($currency = $overview['currency'])
    @if(0 === count($overview['rows']))
        <div class="row">
            <div class="col-lg-8 offset-lg-2 col-md-12">
                <div class="card mb-2">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('investments.no_investments_title') }}</h3>
                    </div>
                    <div class="card-body">
                        <p>{{ trans('investments.no_investments_text') }}</p>
<pre class="bg-body-secondary p-2 rounded">investment: true
broker: My Broker
isin: IE00B4L5Y983
shares: 12.5</pre>
                        <p class="mb-0">
                            <a href="{{ route('accounts.index', ['asset']) }}">{{ trans('investments.no_investments_link') }}</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @if(count($overview['rows']) > 0)
        <div class="row">
            <div class="col-lg-12">
                <div class="card mb-2">
                    <div class="card-header">
                        <h3 class="card-title">
                            {{ trans('investments.overview') }}
                            <small class="text-muted">({{ trans('investments.balance_on', ['date' => $overview['date']->isoFormat($monthAndDayFormat)]) }})</small>
                        </h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0" id="investments-table">
                            <thead>
                            <tr>
                                <th>{{ trans('investments.account') }}</th>
                                <th>{{ trans('investments.broker') }}</th>
                                <th>{{ trans('investments.isin') }}</th>
                                <th class="text-end">{{ trans('investments.shares') }}</th>
                                <th class="text-end">{{ trans('investments.current_balance') }}</th>
                                <th class="text-end">{{ trans('investments.share_of_total') }}</th>
                                <th class="text-end" title="{{ $overview['compare_date']->isoFormat($monthAndDayFormat) }}">{{ trans('investments.change_since', ['days' => 30]) }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($overview['rows'] as $id => $row)
                                <tr>
                                    <td><a href="{{ route('accounts.show', [$id]) }}">{{ $row['name'] }}</a></td>
                                    <td>{{ $row['broker'] ?? '' }}</td>
                                    <td>
                                        @if(null !== $row['isin'])
                                            <span class="font-monospace">{{ $row['isin'] }}</span>
                                            @if(!$row['isin_valid'])
                                                <em class="bi bi-exclamation-triangle text-warning" title="{{ trans('investments.invalid_isin') }}"></em>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if(null !== $row['shares'])
                                            {{ InvestmentFormatter::number($row['shares']) }}
                                        @elseif(null !== $row['shares_raw'])
                                            {{ $row['shares_raw'] }}
                                            <em class="bi bi-exclamation-triangle text-warning" title="{{ trans('investments.invalid_shares') }}"></em>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        {!! format_amount_by_symbol($row['balance'], $row['currency']->symbol, $row['currency']->decimal_places) !!}
                                        @if($overview['converted'] && $row['currency']->id !== $currency->id)
                                            <br><small class="text-muted">≈ {!! format_amount_by_symbol($row['value'], $currency->symbol, $currency->decimal_places, false) !!}</small>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ InvestmentFormatter::percentage($row['share']) }}</td>
                                    <td class="text-end">
                                        {!! format_amount_by_symbol($row['change'], $currency->symbol, $currency->decimal_places) !!}
                                        <br><small class="text-muted">{{ InvestmentFormatter::percentage($row['change_pct'], true) }}</small>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                            <tr class="fw-bold">
                                <td colspan="4">{{ trans('investments.total') }}</td>
                                <td class="text-end">{!! format_amount_by_symbol($overview['total']['value'], $currency->symbol, $currency->decimal_places) !!}</td>
                                <td class="text-end">{{ InvestmentFormatter::percentage(0 === bccomp($overview['total']['value'], '0', 12) ? null : '100') }}</td>
                                <td class="text-end">
                                    {!! format_amount_by_symbol($overview['total']['change'], $currency->symbol, $currency->decimal_places) !!}
                                    <br><small class="text-muted">{{ InvestmentFormatter::percentage($overview['total']['change_pct'], true) }}</small>
                                </td>
                            </tr>
                            </tfoot>
                        </table>
                        </div>
                    </div>
                    @if($overview['converted'])
                        <div class="card-footer">
                            <small class="text-muted">{{ trans('investments.converted_note', ['currency' => $currency->name]) }}</small>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- products per depot --}}
        <div class="row">
            <div class="col-lg-12 mb-2 d-flex flex-wrap gap-2 align-items-center">
                <form method="POST" action="{{ route('investments.update') }}" class="d-inline">
                    <input name="_token" type="hidden" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-primary" id="investments-update"><em class="bi bi-arrow-repeat"></em> {{ trans('investments.update_now') }}</button>
                </form>
                <a href="{{ route('investments.products.create') }}" class="btn btn-secondary"><em class="bi bi-plus-circle"></em> {{ trans('investments.add_product') }}</a>
                <small class="text-muted">{{ trans('investments.update_explained') }}</small>
            </div>
        </div>
        @foreach($depots as $depotId => $depot)
            @php($depotCurrency = $overview['rows'][$depotId]['currency'])
            <div class="row">
                <div class="col-lg-12">
                    <div class="card mb-2 investments-depot" id="investments-depot-{{ $depotId }}">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="card-title mb-0">
                                {{ $depot['account']->name }}
                                @if(null !== ($overview['rows'][$depotId]['broker'] ?? null))
                                    <small class="text-muted">({{ $overview['rows'][$depotId]['broker'] }})</small>
                                @endif
                            </h3>
                            <a href="{{ route('investments.products.create', ['account' => $depotId]) }}" class="btn btn-sm btn-secondary ms-auto"><em class="bi bi-plus"></em> {{ trans('investments.add_product') }}</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0 investments-products">
                                <thead>
                                <tr>
                                    <th>{{ trans('investments.product') }}</th>
                                    <th class="text-end">{{ trans('investments.shares') }}</th>
                                    <th class="text-end">{{ trans('investments.price') }}</th>
                                    <th class="text-end">{{ trans('investments.value') }}</th>
                                    <th class="text-end">{{ trans('investments.invested') }}</th>
                                    <th class="text-end">{{ trans('investments.gain') }}</th>
                                    <th class="text-end">{{ trans('investments.change_last') }}</th>
                                    <th class="text-end">{{ trans('investments.change_since', ['days' => 30]) }}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($depot['products'] as $row)
                                    <tr>
                                        <td>
                                            {{ $row['product']->name }}
                                            <br><small class="text-muted font-monospace">{{ $row['product']->isin }}@if(null !== $row['product']->isin && null !== $row['product']->symbol) · @endif{{ $row['product']->symbol }}</small>
                                        </td>
                                        <td class="text-end">{{ InvestmentFormatter::number($row['product']->shares) }}</td>
                                        <td class="text-end">
                                            @if(null === $row['price'])
                                                <span class="text-muted" title="{{ trans('investments.no_price_yet') }}">–</span>
                                            @else
                                                {!! format_amount_by_symbol(\FireflyIII\Support\Facades\Steam::bcround($row['price'], 4), $depotCurrency->symbol, 4, false) !!}
                                                <br><small class="text-muted">{{ $row['price_date']->isoFormat($monthAndDayFormat) }}@if('manual' === $row['price_source']) · {{ trans('investments.manual') }}@endif</small>
                                            @endif
                                        </td>
                                        <td class="text-end">@if(null !== $row['value']){!! format_amount_by_symbol($row['value'], $depotCurrency->symbol, $depotCurrency->decimal_places) !!}@else – @endif</td>
                                        <td class="text-end">{!! format_amount_by_symbol($row['invested'], $depotCurrency->symbol, $depotCurrency->decimal_places, false) !!}</td>
                                        <td class="text-end">
                                            @if(null !== $row['gain'])
                                                {!! format_amount_by_symbol($row['gain'], $depotCurrency->symbol, $depotCurrency->decimal_places) !!}
                                                <br><small class="text-muted">{{ InvestmentFormatter::percentage($row['gain_pct'], true) }}</small>
                                            @else – @endif
                                        </td>
                                        <td class="text-end">
                                            @if(null !== $row['change'])
                                                {!! format_amount_by_symbol($row['change'], $depotCurrency->symbol, $depotCurrency->decimal_places) !!}
                                                <br><small class="text-muted">{{ InvestmentFormatter::percentage($row['change_pct'], true) }}</small>
                                            @else – @endif
                                        </td>
                                        <td class="text-end">
                                            @if(null !== $row['change_30'])
                                                {!! format_amount_by_symbol($row['change_30'], $depotCurrency->symbol, $depotCurrency->decimal_places) !!}
                                                <br><small class="text-muted">{{ InvestmentFormatter::percentage($row['change_30_pct'], true) }}</small>
                                            @else – @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('investments.products.edit', [$row['product']->id]) }}" class="btn btn-sm btn-secondary" title="{{ trans('investments.edit_product') }}"><em class="bi bi-pencil"></em></a>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                                <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="3">{{ trans('investments.total') }}</td>
                                    <td class="text-end">{!! format_amount_by_symbol($depot['total']['value'], $depotCurrency->symbol, $depotCurrency->decimal_places) !!}</td>
                                    <td class="text-end">{!! format_amount_by_symbol($depot['total']['invested'], $depotCurrency->symbol, $depotCurrency->decimal_places, false) !!}</td>
                                    <td class="text-end">
                                        {!! format_amount_by_symbol($depot['total']['gain'], $depotCurrency->symbol, $depotCurrency->decimal_places) !!}
                                        <br><small class="text-muted">{{ InvestmentFormatter::percentage($depot['total']['gain_pct'], true) }}</small>
                                    </td>
                                    <td class="text-end">
                                        {!! format_amount_by_symbol($depot['total']['change'], $depotCurrency->symbol, $depotCurrency->decimal_places) !!}
                                        <br><small class="text-muted">{{ InvestmentFormatter::percentage($depot['total']['change_pct'], true) }}</small>
                                    </td>
                                    <td class="text-end">
                                        {!! format_amount_by_symbol($depot['total']['change_30'], $depotCurrency->symbol, $depotCurrency->decimal_places) !!}
                                        <br><small class="text-muted">{{ InvestmentFormatter::percentage($depot['total']['change_30_pct'], true) }}</small>
                                    </td>
                                    <td></td>
                                </tr>
                                </tfoot>
                            </table>
                            </div>
                            <div class="row p-3">
                                <div class="col-lg-8 col-md-12">
                                    <h4 class="h6">{{ trans('investments.product_chart', ['start' => $start->isoFormat($monthAndDayFormat), 'end' => $end->isoFormat($monthAndDayFormat)]) }}</h4>
                                    <div>
                                        <canvas id="investments-products-chart-{{ $depotId }}" class="investments-products-chart" data-url="{{ route('investments.chart.products', [$depotId]) }}"></canvas>
                                    </div>
                                </div>
                                <div class="col-lg-4 col-md-12">
                                    <h4 class="h6">{{ trans('investments.allocation_product') }}</h4>
                                    <div class="center-chart">
                                        <canvas id="investments-depot-allocation-{{ $depotId }}" class="medium-chart investments-depot-allocation" data-url="{{ route('investments.chart.products.allocation', [$depotId]) }}" height="250"></canvas>
                                    </div>
                                    @php($depotMissing = \FireflyIII\Support\Investments\InvestmentPortfolio::withoutPrice([$depot]))
                                    @if(count($depotMissing) > 0)
                                        <small class="text-muted">{{ trans('investments.allocation_product_missing', ['products' => implode(', ', $depotMissing)]) }}</small>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="row">
            <div class="col-lg-12">
                <div class="card mb-2">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('investments.balance_chart', ['start' => $start->isoFormat($monthAndDayFormat), 'end' => $end->isoFormat($monthAndDayFormat)]) }}</h3>
                    </div>
                    <div class="card-body">
                        <canvas id="investments-balance-chart" class="wide-chart" height="400" width="100%"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="{{ count($depots) > 0 ? 'col-lg-4' : 'col-lg-6' }} col-md-12">
                <div class="card mb-2">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('investments.allocation_account') }}</h3>
                    </div>
                    <div class="card-body">
                        <div class="center-chart">
                            <canvas id="investments-allocation-account" class="medium-chart" height="250"></canvas>
                        </div>
                    </div>
                    <div class="card-footer"><small class="text-muted">{{ trans('investments.allocation_note') }}</small></div>
                </div>
            </div>
            <div class="{{ count($depots) > 0 ? 'col-lg-4' : 'col-lg-6' }} col-md-12">
                <div class="card mb-2">
                    <div class="card-header">
                        <h3 class="card-title">{{ trans('investments.allocation_broker') }}</h3>
                    </div>
                    <div class="card-body">
                        <div class="center-chart">
                            <canvas id="investments-allocation-broker" class="medium-chart" height="250"></canvas>
                        </div>
                    </div>
                    <div class="card-footer"><small class="text-muted">{{ trans('investments.allocation_note') }}</small></div>
                </div>
            </div>
            @if(count($depots) > 0)
                @php($missing = \FireflyIII\Support\Investments\InvestmentPortfolio::withoutPrice($depots))
                <div class="col-lg-4 col-md-12">
                    <div class="card mb-2">
                        <div class="card-header">
                            <h3 class="card-title">{{ trans('investments.allocation_product') }}</h3>
                        </div>
                        <div class="card-body">
                            <div class="center-chart">
                                <canvas id="investments-allocation-product" class="medium-chart" height="250"></canvas>
                            </div>
                        </div>
                        <div class="card-footer">
                            <small class="text-muted">
                                {{ trans('investments.allocation_product_note') }}
                                @if(count($missing) > 0)
                                    {{ trans('investments.allocation_product_missing', ['products' => implode(', ', $missing)]) }}
                                @endif
                            </small>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif
@endsection
@section('scripts')
    @vite(['js/pages/generic.js'])
    @if(count($overview['rows']) > 0)
        <script nonce="{{ $JS_NONCE }}">
            var investmentsBalanceChartUrl = '{{ route('investments.chart.balances') }}';
            var investmentsAllocationAccountUrl = '{{ route('investments.chart.allocation.account') }}';
            var investmentsAllocationBrokerUrl = '{{ route('investments.chart.allocation.broker') }}';
            var investmentsAllocationProductUrl = '{{ route('investments.chart.allocation.product') }}';
        </script>
        <script src="v1/js/lib/Chart.bundle.min.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script src="v1/js/ff/charts.defaults.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script src="v1/js/ff/charts.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script src="v1/js/ff/investments/index.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
    @endif
@endsection
