{{-- Custom addition (not part of upstream Firefly III): add or change a product of the "Investments" page. --}}
@extends('layout.v3.session')
@section('breadcrumbs')
    <ol class="breadcrumb float-sm-end">
        <li class="breadcrumb-item"><a href="{{ route('index') }}">{{ trans('breadcrumbs.home') }}</a></li>
        <li class="breadcrumb-item"><a href="{{ route('investments.index') }}">{{ trans('investments.investments') }}</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ null === $product ? trans('investments.add_product') : $product->name }}</li>
    </ol>
@endsection
@section('content')
    @use('FireflyIII\Support\Investments\InvestmentFormatter')
    <div class="row">
        <div class="col-lg-8 offset-lg-2 col-md-12">
            @if(0 === count($accounts))
                <div class="alert alert-warning">{{ trans('investments.no_depots') }}</div>
            @else
            <form method="POST" action="{{ null === $product ? route('investments.products.store') : route('investments.products.update', [$product->id]) }}" id="investments-product-form">
                <input name="_token" type="hidden" value="{{ csrf_token() }}">
                <div class="card mb-2">
                    <div class="card-header">
                        <h3 class="card-title">{{ null === $product ? trans('investments.add_product') : trans('investments.edit_product') }}</h3>
                    </div>
                    <div class="card-body">
                        @php($field = static fn (string $name, $default = null) => old($name, $default))
                        <div class="mb-3">
                            <label for="account_id" class="form-label">{{ trans('investments.depot') }}</label>
                            <select name="account_id" id="account_id" class="form-select @error('account_id') is-invalid @enderror">
                                @foreach($accounts as $id => $account)
                                    <option value="{{ $id }}" @if((int) $field('account_id', $selected) === $id) selected @endif>{{ $account->name }}</option>
                                @endforeach
                            </select>
                            @error('account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label for="name" class="form-label">{{ trans('investments.product_name') }}</label>
                            <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ $field('name', $product?->name) }}" required maxlength="255" placeholder="iShares Core MSCI World">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="isin" class="form-label">{{ trans('investments.isin') }}</label>
                                <input type="text" name="isin" id="isin" class="form-control font-monospace @error('isin') is-invalid @enderror" value="{{ $field('isin', $product?->isin) }}" maxlength="20" placeholder="IE00B4L5Y983">
                                @error('isin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="symbol" class="form-label">{{ trans('investments.symbol') }}</label>
                                <input type="text" name="symbol" id="symbol" class="form-control font-monospace @error('symbol') is-invalid @enderror" value="{{ $field('symbol', $product?->symbol) }}" maxlength="32" placeholder="EUNL.DE">
                                <div class="form-text">{{ trans('investments.symbol_help') }}</div>
                                @error('symbol')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="shares" class="form-label">{{ trans('investments.shares') }}</label>
                                <input type="text" inputmode="decimal" name="shares" id="shares" class="form-control @error('shares') is-invalid @enderror" value="{{ $field('shares', null === $product ? null : InvestmentFormatter::number($product->shares, 8, 'en_US')) }}" required placeholder="12.5">
                                @error('shares')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="invested" class="form-label">{{ trans('investments.invested') }}</label>
                                <input type="text" inputmode="decimal" name="invested" id="invested" class="form-control @error('invested') is-invalid @enderror" value="{{ $field('invested', null === $product ? null : \FireflyIII\Support\Facades\Steam::bcround($product->invested, 2)) }}" placeholder="1000.00">
                                <div class="form-text">{{ trans('investments.invested_help') }}</div>
                                @error('invested')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        @if(null !== $product)
                            <div class="mb-3">
                                <label for="manual_price" class="form-label">{{ trans('investments.manual_price') }}</label>
                                <input type="text" inputmode="decimal" name="manual_price" id="manual_price" class="form-control @error('manual_price') is-invalid @enderror" value="{{ $field('manual_price') }}">
                                <div class="form-text">
                                    {{ trans('investments.manual_price_help') }}
                                    @if(null !== $latest)
                                        {{ trans('investments.latest_price', ['price' => \FireflyIII\Support\Facades\Steam::bcround($latest->price, 4), 'date' => $latest->date->isoFormat($monthAndDayFormat)]) }}
                                    @endif
                                </div>
                                @error('manual_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endif
                        <div class="alert alert-info mb-0"><small>{{ trans('investments.shares_reminder') }}</small></div>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ trans('investments.save') }}</button>
                        <a href="{{ route('investments.index') }}" class="btn btn-secondary">{{ trans('investments.cancel') }}</a>
                    </div>
                </div>
            </form>
            @if(null !== $product)
                <form method="POST" action="{{ route('investments.products.destroy', [$product->id]) }}" id="investments-product-delete">
                    <input name="_token" type="hidden" value="{{ csrf_token() }}">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><em class="bi bi-trash"></em> {{ trans('investments.delete_product') }}</button>
                    <small class="text-muted ms-2">{{ trans('investments.delete_help') }}</small>
                </form>
            @endif
            @endif
        </div>
    </div>
@endsection
@section('scripts')
    @vite(['js/pages/generic.js'])
    <script nonce="{{ $JS_NONCE }}">
        var deleteForm = document.getElementById('investments-product-delete');
        if (null !== deleteForm) {
            deleteForm.addEventListener('submit', function (event) {
                if (!window.confirm(@json(trans('investments.delete_confirm')))) {
                    event.preventDefault();
                }
            });
        }
    </script>
@endsection
