<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceChange>
 */
class ResourceChangeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'client_id' => fn (array $attributes): int => (int) Service::query()->whereKey($attributes['service_id'])->value('client_id'),
            'billing_cycle' => BillingCycle::Monthly,
            'currency' => 'USD',
            'old_amount' => 0,
            'new_amount' => 0,
            'difference' => 0,
            'mode' => ResourceChange::MODE_INVOICE,
            'status' => ResourceChange::STATUS_PENDING,
            'sets_server' => true,
            'server_state' => ResourceChange::SERVER_NONE,
        ];
    }

    /**
     * Waits for the renewal date.
     */
    public function atRenewal(): static
    {
        return $this->state(['mode' => ResourceChange::MODE_RENEWAL, 'apply_on' => today()->addDays(20)]);
    }

    public function applied(): static
    {
        return $this->state(['status' => ResourceChange::STATUS_APPLIED, 'applied_at' => now()]);
    }

    /**
     * Add one kind to the change. Amounts are the row's renewal price before and after, in minor units.
     */
    public function withItem(ResourceOption $option, int $fromSteps, int $toSteps, int $oldAmount = 0, int $newAmount = 0): static
    {
        return $this->afterCreating(fn (ResourceChange $change) => $change->items()->create([
            'resource_option_id' => $option->id,
            'kind' => $option->kind,
            'unit' => $option->unit,
            'step_size' => $option->step_size,
            'from_steps' => $fromSteps,
            'to_steps' => $toSteps,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
        ]));
    }
}
