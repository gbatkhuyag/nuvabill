<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin → a service → Client access: test whether the client can sign in to their panel and use
 * the server panel, and link a service to an account that already exists on the server.
 *
 * The check results go back in the flash "access_checks" as {service, rows}; each row is
 * {label, status (ok, warn, fail or skip), detail}. Modules never put a token or password in them.
 */
class ServiceAccessController extends Controller
{
    /**
     * Session flash key of the last check's results.
     */
    public const FLASH = 'access_checks';

    /**
     * Run the module's sign-in checks and show each result, with core's own check of the proxy in
     * front of this site (a module cannot see the request).
     */
    public function check(Request $request, Service $service, Provisioner $provisioner): RedirectResponse
    {
        $service->loadMissing('product');
        $back = redirect()->to(route('admin.services.show', $service).'#client-access');
        $rows = $provisioner->checkClientAccess($service);

        if ($rows === []) {
            return $back->with('error', __('This server module has no sign-in checks.'));
        }

        $proxy = $this->untrustedProxyRow($request);

        if ($proxy !== null) {
            $rows[] = $proxy;
        }

        $count = fn (string $status): int => count(array_filter($rows, fn (array $row): bool => $row['status'] === $status));

        Activity::log(
            'service.access_checked',
            "Panel sign-in checked for service #{$service->id} ({$service->label()}): ".count($rows).' checks, '
                .$count('ok').' passed, '.$count('warn').' warnings, '.$count('fail').' failed',
            $service,
        );

        return $back
            ->with('status', __('The check is done. See the results under Client access.'))
            ->with(self::FLASH, ['service' => $service->id, 'rows' => $rows]);
    }

    /**
     * Link the service to an existing account on the server, with the fields the module asks for.
     * A field of the password type is never sent back to the form.
     */
    public function link(Request $request, Service $service, Provisioner $provisioner): RedirectResponse
    {
        $service->loadMissing('product');
        $back = redirect()->to(route('admin.services.show', $service).'#client-access');
        $fields = $provisioner->linkFields($service);

        if ($fields === []) {
            return $back->with('error', __('This server module cannot link an existing account.'));
        }

        $rules = [];

        foreach ($fields as $name => $field) {
            $rules[$name] = $field['type'] === 'checkbox'
                ? ['nullable', 'boolean']
                : [($field['required'] ?? false) ? 'required' : 'nullable', 'string', 'max:255'];
        }

        $validator = Validator::make($request->only(array_keys($fields)), $rules, [], array_map(fn (array $field): string => $field['label'], $fields));

        if ($validator->fails()) {
            $shown = array_keys(array_filter($fields, fn (array $field): bool => $field['type'] !== 'password'));

            return $back->withErrors($validator)->withInput($request->only($shown));
        }

        $validated = $validator->validated();
        $input = [];

        foreach ($fields as $name => $field) {
            if ($field['type'] === 'checkbox') {
                $input[$name] = $request->boolean($name) ? '1' : '';
            } elseif (is_string($validated[$name] ?? null)) {
                $input[$name] = $validated[$name];
            }
        }

        $result = $provisioner->linkAccount($service, $input);

        return $back->with($result->success ? 'status' : 'error', $result->message !== '' ? $result->message : __('Done.'));
    }

    /**
     * A warning when staff reach this site through a proxy that is not trusted: the request names a
     * forwarded visitor address, but the IP Nuvabill uses is still the proxy's own. Clients come the
     * same way, so a panel sign-in tied to the client's IP is tied to the proxy instead. The headers
     * are only looked at; their values never reach the result.
     *
     * @return array{label: string, status: 'warn', detail: string}|null
     */
    private function untrustedProxyRow(Request $request): ?array
    {
        $forwarded = $request->headers->has('X-Forwarded-For') || $request->headers->has('X-Real-IP') || $request->headers->has('Forwarded');

        if (! $forwarded || $request->ip() !== $request->server('REMOTE_ADDR')) {
            return null;
        }

        return [
            'label' => __('Proxy in front of this site'),
            'status' => 'warn',
            'detail' => __('This site is behind a proxy that is not trusted, so the panel sign-in sees the proxy\'s address, not the client\'s. Set NUVABILL_TRUSTED_PROXIES in .env.'),
        ];
    }
}
