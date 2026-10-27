<?php

namespace App\Provisioning\Hardware;

use App\Models\Machine;
use App\Models\Service;
use Throwable;

/**
 * The add-ons that can install dedicated servers by themselves (see MachineInstaller). Core
 * registers none: without one, staff install every server by hand.
 */
final class MachineInstallers
{
    /**
     * @var array<string, MachineInstaller>
     */
    private array $installers = [];

    public function register(MachineInstaller $installer): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $installer->key()) === 1) {
            $this->installers[$installer->key()] = $installer;
        }
    }

    public function get(string $key): ?MachineInstaller
    {
        return $this->installers[$key] ?? null;
    }

    /**
     * The first installer that takes this machine for this service. One that fails to answer is
     * reported and skipped.
     */
    public function for(Machine $machine, Service $service): ?MachineInstaller
    {
        foreach ($this->installers as $installer) {
            try {
                if ($installer->takes($machine, $service)) {
                    return $installer;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return null;
    }
}
