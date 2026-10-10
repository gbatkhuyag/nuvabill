<?php

namespace Database\Factories;

use App\Enums\MachineStatus;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Machine>
 */
class MachineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'DS-'.fake()->unique()->numberBetween(100, 9999),
            'location' => 'Rack room 1',
            'cpu' => 'Intel Xeon E-2388G',
            'cpu_cores' => 8,
            'ram_gb' => 64,
            'storage' => '2 × 960 GB NVMe',
            'port_speed' => '1 Gbps',
            'status' => MachineStatus::Available,
            'bmc_type' => 'none',
            'bmc_tls_mode' => 'pinned',
        ];
    }

    public function available(): static
    {
        return $this->state(['status' => MachineStatus::Available, 'service_id' => null]);
    }

    /**
     * A Redfish BMC that was tested and trusted: a pinned key, the system path, the reset target
     * and the reset types it reported.
     */
    public function redfish(string $host = '10.20.0.11'): static
    {
        return $this->state([
            'bmc_type' => 'redfish',
            'bmc_host' => $host,
            'bmc_port' => 443,
            'bmc_username' => 'nuvabill-operator',
            'bmc_password' => 'bmc-test-password',
            'bmc_tls_mode' => 'pinned',
            'bmc_tls_pin' => base64_encode(hash('sha256', 'test bmc key '.$host, true)),
            'bmc_tls_fingerprint' => implode(':', str_split(strtoupper(hash('sha256', 'test bmc certificate '.$host)), 2)),
            'bmc_system_path' => '/redfish/v1/Systems/System.Embedded.1',
            'bmc_reset_target' => '/redfish/v1/Systems/System.Embedded.1/Actions/ComputerSystem.Reset',
            'bmc_reset_types' => ['On', 'ForceOff', 'GracefulShutdown', 'GracefulRestart', 'ForceRestart', 'PushPowerButton'],
            'bmc_vendor' => 'Dell Inc.',
            'bmc_model' => 'PowerEdge R250',
            'bmc_checked_at' => now(),
        ]);
    }

    public function ipmi(string $host = '10.20.0.12'): static
    {
        return $this->state([
            'bmc_type' => 'ipmi',
            'bmc_host' => $host,
            'bmc_port' => 623,
            'bmc_username' => 'nuvabill-operator',
            'bmc_password' => 'bmc-test-password',
            'bmc_ipmi_cipher' => 17,
        ]);
    }

    /**
     * Reserved by an order that is not set up yet.
     */
    public function reservedFor(Service $service): static
    {
        return $this->state(['status' => MachineStatus::Reserved, 'service_id' => $service->id]);
    }

    /**
     * Set up and in use by the service.
     */
    public function activeFor(Service $service): static
    {
        return $this->state(['status' => MachineStatus::Active, 'service_id' => $service->id, 'provisioned_at' => now()]);
    }

    /**
     * Released by its last service and waiting for staff to wipe its disks.
     */
    public function wiping(): static
    {
        return $this->state(['status' => MachineStatus::Wiping, 'service_id' => null, 'released_at' => now(), 'power_check_at' => now()]);
    }

    /**
     * Sold as these products.
     */
    public function linkedTo(Product ...$products): static
    {
        return $this->afterCreating(fn (Machine $machine) => $machine->products()->syncWithoutDetaching(array_map(fn (Product $product): int => $product->id, $products)));
    }

    /**
     * IP addresses routed to the machine (documentation range 203.0.113.0/24).
     *
     * @param  list<string>  $extras
     * @param  list<string>  $included
     */
    public function withIps(string $primary = '203.0.113.10', array $extras = [], array $included = []): static
    {
        return $this->afterCreating(function (Machine $machine) use ($primary, $extras, $included): void {
            $lines = [[$primary, MachineIp::KIND_PRIMARY]];

            foreach ($included as $address) {
                $lines[] = [$address, MachineIp::KIND_INCLUDED];
            }

            foreach ($extras as $address) {
                $lines[] = [$address, MachineIp::KIND_EXTRA];
            }

            foreach ($lines as [$address, $kind]) {
                MachineIp::factory()->create([
                    'machine_id' => $machine->id,
                    'address' => $address,
                    'version' => str_contains($address, ':') ? 6 : 4,
                    'kind' => $kind,
                ]);
            }
        });
    }
}
