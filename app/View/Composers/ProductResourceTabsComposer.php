<?php

namespace App\View\Composers;

use App\Models\Product;
use App\Models\ResourceOption;
use Illuminate\View\View;

/**
 * Resource option counts for the admin product screens: the "Resource options (n)" tab at the head
 * of a product's pages, and the small count next to each product in the products list.
 */
class ProductResourceTabsComposer
{
    public function compose(View $view): void
    {
        if ($view->name() === 'admin.products.index') {
            $view->with('resourceOptionCounts', ResourceOption::query()
                ->selectRaw('product_id, count(*) as aggregate')
                ->groupBy('product_id')
                ->pluck('aggregate', 'product_id')
                ->map(fn (mixed $count): int => (int) $count)
                ->all());

            return;
        }

        $product = $view->getData()['product'] ?? null;

        $view->with('resourceOptionCount', $product instanceof Product && $product->exists
            ? ($product->relationLoaded('resourceOptions') ? $product->resourceOptions->count() : ResourceOption::query()->where('product_id', $product->id)->count())
            : 0);
    }
}
