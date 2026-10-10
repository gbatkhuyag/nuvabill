<?php

namespace App\Provisioning;

use App\Contracts\ServerModule;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Models\Product;
use App\Models\Service;
use LogicException;

/**
 * The checks every client server panel read and action passes, in this order: owner, status,
 * module and section (with the product's switches), live availability, action declared, input
 * validation, throttles, the read cache, the service lock for changing actions, the module call,
 * the secret scrubber, then the log, cache version and email after a change. Modules never see a
 * request that did not pass them. Provisioner hands the panel work to this class.
 *
 * Skeleton: the public signatures are final; the bodies come with the panel gate unit.
 */
final class PanelGate
{
    /**
     * The serverPanel array for the client's service page (view, data, actions, state, login,
     * console, power, sections, status_url, resources_url), or null when the service has no panel.
     *
     * @return array<string, mixed>|null
     */
    public function serverPanel(Service $service, ServerModule $module, ClientContext $context): ?array
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Read one section for the client, after every check.
     *
     * @param  array<string, mixed>  $query  The raw query; only keys with read rules reach the module.
     */
    public function read(Service $service, ServerModule $module, string $section, array $query, ClientContext $context): PanelResult
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Run one action for the client, after every check.
     *
     * @param  array<string, mixed>  $input  The raw input; only keys with rules reach the module.
     * @param  bool  $canReveal  Whether the answer can show a secret (the browser asked for JSON).
     */
    public function action(Service $service, ServerModule $module, string $section, string $action, array $input, ClientContext $context, bool $canReveal): PanelResult
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * The sections the module declares for the product that staff left on, in display order.
     *
     * @return list<PanelSection>
     */
    public function enabledSections(Product $product, ServerModule $module): array
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * The server state from the cached overview read (running, stopped, suspended or unknown), or
     * null when none is cached.
     */
    public function overviewState(Service $service, ServerModule $module): ?string
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Open a console for the client: the gate checks for the "console" section, then a single-use
     * ticket. On success data holds 'ticket' (the handle) and 'source' (wss://host:port).
     */
    public function openConsole(Service $service, ServerModule $module, ClientContext $context): PanelResult
    {
        throw new LogicException('Not built yet.');
    }
}
