<?php

namespace App\View\Composers;

use App\Billing\ResourceChanges;
use App\Billing\ResourceOptions;
use App\Contracts\HasResourceOptions;
use App\Models\ResourceChange;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Provisioning\Provisioner;
use Illuminate\View\View;

/**
 * The "Resources" card on the admin service page: the service's extras, the waiting change, the
 * server state of the last one, the staff change form and the server tools. The card is left out
 * ($resourceCard null) for a service whose product has no options and that has no extras.
 */
class AdminServiceResourcesComposer
{
    public function __construct(
        private ResourceChanges $changes,
        private ResourceOptions $options,
        private Provisioner $provisioner,
    ) {}

    public function compose(View $view): void
    {
        $service = $view->getData()['service'] ?? null;

        if (! $service instanceof Service || ! $service->exists) {
            $view->with('resourceCard', null);

            return;
        }

        $service->loadMissing(['product', 'client', 'resources.option']);
        $rows = $service->resources
            ->filter(fn (ServiceResourceOption $row): bool => $row->isActive())
            ->sortBy([
                fn (ServiceResourceOption $a, ServiceResourceOption $b): int => ($a->option?->sort_order ?? PHP_INT_MAX) <=> ($b->option?->sort_order ?? PHP_INT_MAX),
                fn (ServiceResourceOption $a, ServiceResourceOption $b): int => $a->id <=> $b->id,
            ])
            ->values();
        $hasOptions = ResourceOption::query()->where('product_id', $service->product_id)->exists();
        $pending = $this->changes->pendingFor($service);
        $latest = $this->changes->latestApplied($service);

        if ($rows->isEmpty() && ! $hasOptions && $pending === null && $latest === null) {
            $view->with('resourceCard', null);

            return;
        }

        $blocked = $this->changes->blockedReason($service, staff: true);

        $view->with('resourceCard', [
            'rows' => $rows,
            'pending' => $pending,
            'latest' => $latest,
            'serverOpen' => $latest !== null && in_array($latest->server_state, [ResourceChange::SERVER_QUEUED, ResourceChange::SERVER_APPLYING, ResourceChange::SERVER_CHECKING, ResourceChange::SERVER_FAILED, ResourceChange::SERVER_MANUAL], true),
            'blocked' => $blocked,
            'sliders' => $blocked === null ? $this->options->forService($service, staff: true) : [],
            'supportsModule' => filled($service->product->server_module) && $this->provisioner->productSupports($service->product, HasResourceOptions::class),
            'check' => session('resource_check'),
        ]);
    }
}
