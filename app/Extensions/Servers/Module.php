<?php

namespace App\Extensions\Servers;

use App\Contracts\ServerModule;
use App\Extensions\ExtensionManifest;
use App\Models\Service;

/**
 * Base class for server module extensions.
 */
abstract class Module implements ServerModule
{
    public function __construct(protected ExtensionManifest $manifest) {}

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    public function name(): string
    {
        return $this->manifest->name;
    }

    public function productFields(): array
    {
        return [];
    }

    public function serverHelp(): string
    {
        return '';
    }

    public function changePackage(Service $service): ModuleResult
    {
        return ModuleResult::fail(__('This module cannot change packages.'));
    }

    public function loginUrl(Service $service): ?string
    {
        return null;
    }

    /**
     * Whether clients see an "Open control panel" button. Modules without a panel login turn it off.
     */
    public function hasLoginLink(): bool
    {
        return true;
    }

    /**
     * A value this module saved on the service when it was created, for example the VPS ID.
     */
    protected function moduleValue(Service $service, string $key, mixed $default = null): mixed
    {
        return $service->module_data[$key] ?? $default;
    }

    /**
     * A product setting for the service's product, for example the package name. A setting marked
     * "secret" in productFields() is stored encrypted and comes back plain (see ModuleConfig).
     */
    protected function productSetting(Service $service, string $key, mixed $default = null): mixed
    {
        return ModuleConfig::read($service->product->module_config[$key] ?? null) ?? $default;
    }

    /**
     * What the client entered in one of this module's order form fields (HasOrderFields), for
     * example in create(). SSH keys come one per line. Since Nuvabill 0.7.0.
     */
    protected function orderField(Service $service, string $key, ?string $default = null): ?string
    {
        $value = ((array) $service->order_fields)[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
