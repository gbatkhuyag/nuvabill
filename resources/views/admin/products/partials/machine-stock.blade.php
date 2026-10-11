{{--
    Product form: the stock of a product sold from Admin → Hardware is its free linked machines.
    Shown instead of the Stock field when the chosen module uses machines. A new product gets the
    "Dedicated server" type when such a module is picked.
    Values: $product, $selectedModule, $inventoryModules, and $machineStock from ProductMachineStockComposer.
--}}
@php
    $stock = $machineStock ?? ['saved' => false, 'available' => 0, 'total' => 0, 'url' => null];
    $isInventory = in_array($selectedModule ?? '', $inventoryModules ?? [], true);
@endphp
<div class="field" x-show="inventory.includes(module)" @unless ($isInventory) style="display:none" @endunless
     @unless ($product->exists) x-init="$watch('module', (value) => { const type = document.getElementById('f-type'); if (type && inventory.includes(value)) { type.value = 'dedicated'; } })" @endunless>
    <span class="label">{{ __('Stock') }}</span>
    <p style="margin:0">
        @if ($stock['saved'])
            {{ __('Stock comes from free machines under Hardware. :available of :total linked machines are free.', ['available' => $stock['available'], 'total' => $stock['total']]) }}
        @else
            {{ __('Stock comes from free machines under Hardware. Link machines to this product after you save it.') }}
        @endif
    </p>
    @if ($stock['url'])
        <p class="help"><a href="{{ $stock['url'] }}">{{ $stock['saved'] ? __('See the machines of this product') : __('Open Hardware') }}</a></p>
    @endif
</div>
