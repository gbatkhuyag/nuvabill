<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Provisioning\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;

/**
 * Admin → Products → a product → Resource options: the sliders clients can use to add RAM, disk,
 * IP addresses and other extras, with their step size, limits, stock and prices.
 *
 * Options are bound inside their product, so an option of another product gives 404.
 *
 * Skeleton: the routes and public signatures are final; the bodies come with the resource catalog.
 * A body may swap Request for its own form request class.
 */
class ResourceOptionController extends Controller
{
    /**
     * The product's options in display order, with a preview of what clients see.
     */
    public function index(Product $product): View
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * The form for a new option (?kind= picks the resource kind).
     */
    public function create(Request $request, Product $product): View
    {
        throw new LogicException('Not built yet.');
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    public function edit(Product $product, ResourceOption $resourceOption): View
    {
        throw new LogicException('Not built yet.');
    }

    public function update(Request $request, Product $product, ResourceOption $resourceOption): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Delete an option no service uses; a used option can only be hidden.
     */
    public function destroy(Product $product, ResourceOption $resourceOption): RedirectResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Save a new display order of the product's options.
     */
    public function order(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * What the plan itself includes per kind, read from the server through Provisioner, as JSON
     * {base: {kind: int|null}} or a message for staff.
     */
    public function base(Product $product, Provisioner $provisioner): JsonResponse
    {
        throw new LogicException('Not built yet.');
    }
}
