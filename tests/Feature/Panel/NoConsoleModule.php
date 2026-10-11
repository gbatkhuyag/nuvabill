<?php

namespace Tests\Feature\Panel;

use App\Contracts\HasPanelSections;
use App\Contracts\UsesMachineInventory;
use App\Extensions\ExtensionManifest;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelResult;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Tests\Fixtures\PanelFake\PanelFakeModule;

/**
 * A server module for the console tests: it has the panelfake module's sections, including
 * "console", but no browser console (it does not implement HasConsole).
 */
class NoConsoleModule extends Module implements HasPanelSections, UsesMachineInventory
{
    public function __construct(ExtensionManifest $manifest, private PanelFakeModule $sections)
    {
        parent::__construct($manifest);
    }

    public function defaultPort(): int
    {
        return 443;
    }

    public function testConnection(Server $server): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function create(Service $service): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function terminate(Service $service): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function panelSections(Product $product): array
    {
        return $this->sections->panelSections($product);
    }

    public function availablePanelSections(Service $service, ClientContext $context): ?array
    {
        return null;
    }

    public function readPanelSection(Service $service, string $section, array $input, ClientContext $context): PanelResult
    {
        return PanelResult::ok();
    }

    public function runPanelAction(Service $service, string $section, string $action, array $input, ClientContext $context): PanelResult
    {
        return PanelResult::ok();
    }
}
