<?php

namespace App\Provisioning\Hardware;

use App\Models\Admin;
use App\Models\Machine;
use Closure;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Extra cards on a machine's page (Admin → Hardware → a machine), added by add-ons, for example
 * the switch and port of Switch Port Control. Register in the add-on's boot():
 *
 *     app(MachineSections::class)->add(fn (Machine $machine, Admin $admin): ?array => [
 *         'view' => 'ext-my-addon::machine', 'data' => ['port' => ...],
 *     ]);
 *
 * A resolver returns null to show nothing. Only staff who may see the machine page reach it; the
 * add-on checks any further permission itself ($admin->hasPermission('hardware.manage')). The view
 * gets $machine and the data, and must come from the add-on ("ext-{slug}::name"). A resolver or view
 * that fails is reported and left out, so it never breaks the page.
 */
final class MachineSections
{
    /**
     * @var list<Closure(Machine, Admin): (array{view: string, data?: array<string, mixed>}|null)>
     */
    private array $resolvers = [];

    /**
     * @param  Closure(Machine, Admin): (array{view: string, data?: array<string, mixed>}|null)  $resolver
     */
    public function add(Closure $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * The rendered cards for the machine, in the order they were added.
     *
     * @return list<string>
     */
    public function render(Machine $machine, Admin $admin): array
    {
        $cards = [];

        foreach ($this->resolvers as $resolver) {
            try {
                $section = $resolver($machine, $admin);

                if (! is_array($section) || ! is_string($section['view'] ?? null) || preg_match('/^ext-[a-z0-9][a-z0-9_-]*::[A-Za-z0-9_.-]{1,100}\z/', $section['view']) !== 1 || ! View::exists($section['view'])) {
                    continue;
                }

                $cards[] = View::make($section['view'], ['machine' => $machine] + (array) ($section['data'] ?? []))->render();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $cards;
    }
}
