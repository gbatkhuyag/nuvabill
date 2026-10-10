<?php

namespace App\Contracts;

use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Models\Product;
use App\Models\Service;

/**
 * A server module with a tabbed client server panel: overview, power, console, graphs, reinstall
 * and more. Core does the routing, owner and status checks, the per-product switches, input
 * validation, throttles, read caching, the service lock, logging and emails; the module only
 * declares sections and answers reads and actions with the standard data shapes.
 */
interface HasPanelSections
{
    /**
     * Sections the module can offer for this product, in display order. Must not call any server:
     * the admin product form and every page load use it.
     *
     * @return list<PanelSection>
     */
    public function panelSections(Product $product): array;

    /**
     * Section keys the live server allows for this service now, or null for all declared ones.
     * May call the server; core caches it 60 seconds. If it throws, only "overview" and "power" show.
     *
     * @return list<string>|null
     */
    public function availablePanelSections(Service $service, ClientContext $context): ?array;

    /**
     * Read one section. Throw PanelException for a failure the client may see.
     *
     * @param  array<string, mixed>  $input  Validated against the section's readRules (only those keys).
     */
    public function readPanelSection(Service $service, string $section, array $input, ClientContext $context): PanelResult;

    /**
     * Run one action of a section. Refuse it again here when the read said it is not allowed.
     *
     * @param  array<string, mixed>  $input  Validated against the action's rules (only those keys).
     */
    public function runPanelAction(Service $service, string $section, string $action, array $input, ClientContext $context): PanelResult;
}
