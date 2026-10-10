<?php

namespace App\View\Composers;

use App\Contracts\ChecksClientAccess;
use App\Http\Controllers\Admin\ServiceAccessController;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\View\View;

/**
 * Admin → a service → the "Client access" card: whether the module can test the client's panel
 * sign-in and link an existing account, and the results of the last check of this service.
 *
 * Gives the view clientAccess: null when the module can do neither, else {module, check_url,
 * checks, summary, link_url, link_fields}. check_url and link_url are null when the module cannot
 * do that; checks is the list of {label, status, detail} rows of the check just run, or null;
 * summary counts them by status (ok, warn, fail, skip).
 */
class ServiceClientAccessComposer
{
    public function __construct(private Provisioner $provisioner) {}

    public function compose(View $view): void
    {
        $service = $view->getData()['service'] ?? null;

        if (! $service instanceof Service) {
            $view->with('clientAccess', null);

            return;
        }

        $service->loadMissing('product');
        $checks = $this->provisioner->productSupports($service->product, ChecksClientAccess::class);
        $fields = $this->provisioner->linkFields($service);

        if (! $checks && $fields === []) {
            $view->with('clientAccess', null);

            return;
        }

        $rows = $checks ? $this->lastChecks($service) : null;

        $view->with('clientAccess', [
            'module' => (string) $service->product->server_module,
            'check_url' => $checks ? route('admin.services.access-check', $service) : null,
            'checks' => $rows,
            'summary' => $rows === null ? null : array_merge(
                ['ok' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0],
                array_count_values(array_column($rows, 'status')),
            ),
            'link_url' => $fields !== [] ? route('admin.services.link', $service) : null,
            'link_fields' => $fields,
        ]);
    }

    /**
     * The rows of the check staff just ran on this service, from the flash.
     *
     * @return list<array{label: string, status: string, detail: string}>|null
     */
    private function lastChecks(Service $service): ?array
    {
        $flash = session(ServiceAccessController::FLASH);

        if (! is_array($flash) || ($flash['service'] ?? null) !== $service->id || ! is_array($flash['rows'] ?? null)) {
            return null;
        }

        $rows = [];

        foreach ($flash['rows'] as $row) {
            if (is_array($row) && is_string($row['label'] ?? null) && in_array($row['status'] ?? null, ['ok', 'warn', 'fail', 'skip'], true)) {
                $rows[] = ['label' => $row['label'], 'status' => $row['status'], 'detail' => is_string($row['detail'] ?? null) ? $row['detail'] : ''];
            }
        }

        return $rows;
    }
}
