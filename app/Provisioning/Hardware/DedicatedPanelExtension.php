<?php

namespace App\Provisioning\Hardware;

use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelException;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Models\Machine;
use App\Models\Product;
use App\Models\Service;

/**
 * Extra tabs an add-on adds to the client server panel of dedicated servers (the built-in
 * "Dedicated servers" module), for example "Install a new system" of Automatic OS Install. Register
 * one in the add-on's boot(): app(DedicatedPanelExtensions::class)->add(new MyTabs).
 *
 * The tabs work like a server module's own (HasPanelSections): core checks the owner, the active
 * service, the product's switches, the input rules and the throttles first, then the dedicated
 * module checks that the service holds a machine in use and passes it. A tab is never offered while
 * the service has no machine in use, and keys of the module's own tabs (overview, network,
 * reinstall, power) cannot be taken. A section's own view must come from the add-on
 * ("ext-{slug}::name"). Never send anything about the BMC, the switch or the machine's name to the
 * client.
 */
interface DedicatedPanelExtension
{
    /**
     * The tabs for a product, from its settings only. Never asks a server.
     *
     * @return list<PanelSection>
     */
    public function sections(Product $product): array;

    /**
     * The keys of this add-on's tabs the service can use now, from the database only.
     *
     * @return list<string>
     */
    public function availableSections(Service $service, Machine $machine): array;

    /**
     * @param  array<string, mixed>  $input  Only the keys of the section's read rules, validated.
     *
     * @throws PanelException
     */
    public function readSection(Service $service, Machine $machine, string $section, array $input, ClientContext $context): PanelResult;

    /**
     * @param  array<string, mixed>  $input  Only the keys of the action's rules, validated.
     *
     * @throws PanelException
     */
    public function runAction(Service $service, Machine $machine, string $section, string $action, array $input, ClientContext $context): PanelResult;
}
