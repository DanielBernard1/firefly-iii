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
            <div class="col-lg-6 col-md-12">
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
            <div class="col-lg-6 col-md-12">
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
        </script>
        <script src="v1/js/lib/Chart.bundle.min.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script src="v1/js/ff/charts.defaults.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script src="v1/js/ff/charts.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
        <script src="v1/js/ff/investments/index.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
    @endif
@endsection
