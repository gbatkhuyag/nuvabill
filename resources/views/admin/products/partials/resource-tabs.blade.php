{{-- Product pages head: the tabs "Details" and "Resource options (n)". The count comes from ProductResourceTabsComposer. --}}
@if ($product->exists)
    <nav class="resource-tabs" aria-label="{{ __('Product pages') }}">
        <a href="{{ route('admin.products.edit', $product) }}" @if (request()->routeIs('admin.products.edit')) aria-current="page" @endif>
            <x-icon name="box" />{{ __('Details') }}
        </a>
        <a href="{{ route('admin.products.resources.index', $product) }}" @if (request()->routeIs('admin.products.resources.*')) aria-current="page" @endif>
            <x-icon name="layers" />{{ __('Resource options') }} <span class="count">{{ $resourceOptionCount ?? 0 }}</span>
        </a>
    </nav>
@else
    <p class="muted" style="margin:0;font-size:.9rem">{{ __('Save the product first to add resource options.') }}</p>
@endif
