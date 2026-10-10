<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ResourceOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceOption>
 */
class ResourceOptionFactory extends Factory
{
    /**
     * The unit of each built-in kind.
     */
    private const UNITS = ['cpu' => 'core', 'ram' => 'MB', 'disk' => 'GB', 'ipv4' => 'IP', 'ipv6' => 'IP', 'bandwidth' => 'GB', 'backups' => ''];

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'kind' => 'ram',
            'unit' => 'MB',
            'step_size' => 1024,
            'min_steps' => 0,
            'max_steps' => 8,
            'default_steps' => 0,
            'client_can_add' => true,
            'client_can_remove' => true,
            'is_active' => true,
        ];
    }

    /**
     * Add a price per step. Amounts are in minor units.
     */
    public function priced(int $price, string $currency = 'USD', BillingCycle $cycle = BillingCycle::Monthly, int $setup = 0): static
    {
        return $this->afterCreating(fn (ResourceOption $option) => $option->prices()->create([
            'currency' => $currency,
            'billing_cycle' => $cycle,
            'price' => $price,
            'setup_fee' => $setup,
        ]));
    }

    /**
     * Another kind with its step size, in units. Built-in kinds get their own unit; disk cannot shrink.
     */
    public function kind(string $kind, int $step): static
    {
        return $this->state([
            'kind' => $kind,
            'unit' => self::UNITS[$kind] ?? '',
            'step_size' => $step,
            'can_shrink' => $kind !== 'disk',
            'client_can_remove' => $kind !== 'disk',
            'max_steps' => $kind === 'backups' ? 1 : 8,
        ]);
    }

    /**
     * Hidden from clients; services that have it keep it.
     */
    public function retired(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A module's own kind whose steps are sizes (step 0 is the plan itself), such as server types.
     *
     * @param  list<string>  $labels
     */
    public function ladder(array $labels = ['CX23', 'CX33', 'CX43'], string $kind = 'hetzner_cloud_size'): static
    {
        return $this->state([
            'kind' => $kind,
            'unit' => '',
            'step_size' => 1,
            'max_steps' => max(1, count($labels) - 1),
            'ladder' => array_values($labels),
            'restarts' => true,
        ]);
    }
}
