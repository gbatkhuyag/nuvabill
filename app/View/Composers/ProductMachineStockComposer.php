<?php

namespace App\View\Composers;

use App\Models\Admin;
use App\Models\Product;
use App\Provisioning\Hardware\MachineInventory;
use Illuminate\View\View;

/**
 * The product form's stock hint for products sold from Admin → Hardware: how many of the machines
 * linked to the product are free. Counted from the database only; no BMC is called.
 */
class ProductMachineStockComposer
{
    public function __construct(private MachineInventory $inventory) {}

    public function compose(View $view): void
    {
        $product = $view->getData()['product'] ?? null;
        $saved = $product instanceof Product && $product->exists;
        /** @var Admin|null $admin */
        $admin = auth('admin')->user();

        $view->with('machineStock', [
            'saved' => $saved,
            'available' => $saved ? $this->inventory->availableCount($product) : 0,
            'total' => $saved ? $product->machines()->count() : 0,
            'url' => $admin?->hasPermission('hardware.power')
                ? route('admin.machines.index', $saved ? ['product' => $product->getKey()] : [])
                : null,
        ]);
    }
}
