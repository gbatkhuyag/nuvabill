<?php

namespace Tests\Fixtures\DemoPower;

use App\Extensions\Addons\Addon;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use Tests\Fakes\FakePowerDriver;

/**
 * Stands in for Dedicated Server Power on the demo: it registers a Redfish power driver and asks
 * for demo settings.
 */
class DemoPower extends Addon
{
    public function settingsFields(): array
    {
        return [
            'allow_ipmi' => ['label' => 'Offer IPMI', 'type' => 'select', 'options' => ['0' => 'No', '1' => 'Yes']],
            'health_alerts' => ['label' => 'Email staff', 'type' => 'select', 'options' => ['1' => 'Yes', '0' => 'No']],
        ];
    }

    public function demoSettings(): array
    {
        return ['allow_ipmi' => '0', 'health_alerts' => '0'];
    }

    public function boot(): void
    {
        app(PowerDrivers::class)->register(new FakePowerDriver);
    }
}
