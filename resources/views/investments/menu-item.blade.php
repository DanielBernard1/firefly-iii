{{-- Custom addition (not part of upstream Firefly III): the "Investments" entry in the menu on the left. --}}
{{-- It is included from resources/views/components/layout/sidebar.blade.php. --}}
<li class="nav-item">
    <a href="{{ route('investments.index') }}" class="nav-link {{ menu_item_active_partial('investments.') }}">
        <em class="nav-icon bi bi-graph-up-arrow"></em>
        <p>{{ __('investments.investments') }}</p>
    </a>
</li>
