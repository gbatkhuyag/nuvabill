<?php

namespace Database\Factories;

use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceResourceOption>
 */
class ServiceResourceOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'resource_option_id' => ResourceOption::factory(),
            // The kind, unit and step size are copies of the option's.
            'kind' => fn (array $attributes): string => $this->option($attributes)?->kind ?? 'ram',
            'unit' => fn (array $attributes): string => $this->option($attributes)?->unit ?? 'MB',
            'step_size' => fn (array $attributes): int => $this->option($attributes)?->step_size ?? 1024,
            'steps' => 1,
            'recurring_amount' => 0,
            'status' => ServiceResourceOption::STATUS_ACTIVE,
        ];
    }

    /**
     * Steps went to 0; the row stays for its invoice lines.
     */
    public function cancelled(): static
    {
        return $this->state(['steps' => 0, 'recurring_amount' => 0, 'status' => ServiceResourceOption::STATUS_CANCELLED]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function option(array $attributes): ?ResourceOption
    {
        $id = $attributes['resource_option_id'] ?? null;

        return $id instanceof ResourceOption ? $id : ($id !== null ? ResourceOption::query()->find($id) : null);
    }
}
