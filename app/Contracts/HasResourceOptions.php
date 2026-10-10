<?php

namespace App\Contracts;

use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;

/**
 * A server module that can sell extra resources with sliders: more CPU, RAM, disk, addresses and
 * so on, on new orders and on live services. Billing stays in core: the module only says what it
 * can change and sets the server to the product's base plus the extras core gives it. Its
 * create() and changePackage() must include $service->resourceExtras().
 */
interface HasResourceOptions
{
    /**
     * Kinds the module can sell for the product, with limits. May read the module's own cached
     * catalog. Called only from staff screens, saves and jobs, never from store or client pages
     * (those read the snapshot saved on each resource option).
     *
     * @return array<string, ResourceKind> kind => definition
     *
     * @throws \InvalidArgumentException With a message for staff when the product's module settings are wrong.
     */
    public function resourceKinds(Product $product): array;

    /**
     * What the product's plan gives without extras, in units per kind, or null where it is not
     * known. May call the server; staff screens only. $server is null for modules that use the
     * machine inventory.
     *
     * @return array<string, int|null>
     */
    public function baseResources(Product $product, ?Server $server): array;

    /**
     * Live limits for one existing service, in extra units per kind. Kinds not returned have no
     * live limit. May call the server; core caches it 60 seconds, and a throw pauses additions
     * (removals stay possible).
     *
     * @return array<string, ResourceLimit>
     */
    public function resourceLimits(Service $service): array;

    /**
     * Set the live server to the plan's base plus these extras, then check it. A kind at 0 goes
     * back to the base; a kind not in $extras is left alone. Running it again with the same extras
     * changes nothing. Result data may hold 'manual' => true (staff finish by hand), 'restart' =>
     * true (the client must restart) or 'pending' => true (still running; core checks again later
     * with checkResources()).
     */
    public function applyResources(Service $service, ResourceExtras $extras): ModuleResult;

    /**
     * Read the live server and compare it with base plus extras. Changes nothing.
     *
     * @throws \Throwable When the server does not answer.
     */
    public function checkResources(Service $service, ResourceExtras $extras): ResourceCheck;
}
