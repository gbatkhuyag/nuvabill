<x-layouts.admin :title="$addon->exists ? $addon->name : __('New add-on')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.product-addons.index') }}">{{ __('Add-ons') }}</a></p>
            <h1>{{ $addon->exists ? $addon->name : __('New add-on') }}</h1>
        </div>
        @if ($addon->exists)
            <form method="POST" action="{{ route('admin.product-addons.destroy', $addon) }}" onsubmit="return confirm(@js(__('Delete this add-on? Clients who have it keep it.')))">
                @csrf
                @method('DELETE')
                <button class="btn btn-ghost" type="submit"><x-icon name="trash" />{{ __('Delete') }}</button>
            </form>
        @endif
    </div>

    @php $selectedProducts = array_map('intval', (array) old('product_ids', $addon->product_ids ?? [])); @endphp

    <form method="POST" action="{{ $addon->exists ? route('admin.product-addons.update', $addon) : route('admin.product-addons.store') }}" style="display:grid;gap:14px;max-width:860px">
        @csrf
        @if ($addon->exists) @method('PUT') @endif

        <section class="card" style="display:grid;gap:1rem">
            <div class="form-grid">
                <x-input name="name" :label="__('Name')" :value="$addon->name" required maxlength="120" placeholder="{{ __('Daily backups') }}" />
                <x-input name="sort_order" type="number" min="0" :label="__('Order in the list')" :value="$addon->sort_order ?? 0" />
            </div>
            <x-textarea name="description" :label="__('Short description')" :value="$addon->description" rows="2" />
            <div style="display:flex;gap:24px;flex-wrap:wrap">
                <x-checkbox name="is_visible" :label="__('Offer it in the store')" :checked="(bool) $addon->is_visible" />
                <x-checkbox name="is_popular" :label="__('Mark as popular')" :checked="(bool) $addon->is_popular" />
            </div>
        </section>

        <section class="card" style="display:grid;gap:1rem">
            <h2 style="font-size:1.05rem">{{ __('Pricing') }}</h2>
            <p class="muted" style="margin:0">{{ __('Give a price for each billing cycle. The add-on is offered with plans that use one of these cycles.') }}</p>
            <x-price-grid name="prices" :currencies="$currencies" :cycles="$cycles" :values="$priceValues" :base="$currencies[0]" :rates="$rates" id="addon-prices" />
        </section>

        <section class="card" style="display:grid;gap:1rem">
            <h2 style="font-size:1.05rem">{{ __('Offered with') }} <span class="muted" style="font-weight:400;font-size:.9rem">{{ __('leave all unticked for every product') }}</span></h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px">
                @foreach ($products as $id => $name)
                    <label class="check"><input type="checkbox" name="product_ids[]" value="{{ $id }}" @checked(in_array($id, $selectedProducts, true))> {{ $name }}</label>
                @endforeach
            </div>
        </section>

        <div class="form-actions">
            <a class="btn" href="{{ route('admin.product-addons.index') }}">{{ __('Cancel') }}</a>
            <button class="btn btn-primary" type="submit">{{ __('Save add-on') }}</button>
        </div>
    </form>
</x-layouts.admin>
