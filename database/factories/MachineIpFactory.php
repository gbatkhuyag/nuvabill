<?php

namespace Database\Factories;

use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineIp>
 */
class MachineIpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'machine_id' => Machine::factory(),
            // Documentation range 198.51.100.0/24, unique per test.
            'address' => '198.51.100.'.fake()->unique()->numberBetween(1, 254),
            'version' => 4,
            'prefix' => 32,
            'kind' => MachineIp::KIND_INCLUDED,
        ];
    }

    /**
     * An extra address the service rents.
     */
    public function rentedBy(Service $service): static
    {
        return $this->state(['kind' => MachineIp::KIND_EXTRA, 'service_id' => $service->id, 'assigned_at' => now()]);
    }
}
