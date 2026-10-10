<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Enums\ServiceStatus;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Panel\ClientContext;
use App\Models\Server;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The client's one-click sign-in to their own VPS in Virtualizor's end-user panel, and the staff
 * tools around it: who owns a VPS, the create-time guard, the "Test panel sign-in" checks and
 * linking a VPS that already exists. A sign-in link is made on every click, after checking again
 * that the Virtualizor user behind the VPS holds nothing of another client. It is never stored or
 * logged.
 */
final class SignIn
{
    /**
     * The most VPS of one Virtualizor user that a panel sign-in checks. A user with more is refused.
     */
    private const USER_VPS_LIMIT = 100;

    /**
     * @param  string  $module  The module's slug, for finding its services.
     */
    public function __construct(private AdminApi $admin, private EnduserApi $enduser, private string $module) {}

    /**
     * A one-time sign-in link to the end-user panel for the client's own VPS, or null when sign-in
     * is off, not safe or did not work (staff see why in the activity log). Without a context (the
     * old ServerModule::loginUrl() call) no IP can be tied to the session.
     */
    public function signInUrl(Service $service, ?ClientContext $context): ?string
    {
        $server = $service->server;

        if ($service->status !== ServiceStatus::Active || $server === null || ! $this->possible($service)) {
            return null;
        }

        $port = (int) $this->enduser->port($service);

        try {
            $vpsId = AdminApi::vpsId($service);

            if (! $this->ownedByClient($service, $vpsId)) {
                return null;
            }

            $response = $this->panelCall($server, $port, ['act' => 'sso', 'svs' => $vpsId]);
        } catch (RuntimeException $exception) {
            Activity::log('service.module_failed', "Panel sign-in for service #{$service->id} ({$service->label()}) failed: {$exception->getMessage()}", $service);

            return null;
        }

        $token = (string) ($response['token_key'] ?? '');
        $session = (string) ($response['sid'] ?? '');

        if (! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/', $token) || ! preg_match('/\A[A-Za-z0-9_-]{8,256}\z/', $session)) {
            Activity::log('service.module_failed', "Panel sign-in for service #{$service->id} ({$service->label()}) failed: Virtualizor did not return a sign-in session.", $service);

            return null;
        }

        Activity::log('service.panel_login', "Client opened the Virtualizor panel of service #{$service->id} ({$service->label()})", $service);

        return $this->admin->baseUrl($server, $port, true).'/'.$token.'/?'.http_build_query(['as' => $session, 'svs' => $vpsId], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Whether the client panel shows the "Open Virtualizor panel" button: sign-in is possible for
     * the product, and the VPS details (when they could be read) do not rule it out. A failed read
     * never hides the button; the click runs the full check.
     *
     * @param  array<string, mixed>  $info  The VPS from Virtualizor's list, or [] when it could not be read.
     */
    public function offersSignIn(Service $service, array $info): bool
    {
        if (! $this->possible($service)) {
            return false;
        }

        if ($info === []) {
            return true;
        }

        $stored = AdminApi::userId($service->module_data['uid'] ?? null);
        $uid = AdminApi::userId($info['uid'] ?? null);

        return $uid !== null
            && (int) ($info['suspended'] ?? 0) === 0
            && ($stored === null || $stored === $uid);
    }

    /**
     * Why a create must not send anything, or null to go on. It runs before any create request,
     * with the service's create note (null when there is none).
     *
     * @param  array<string, mixed>|null  $record  ResourceAddons::record() of the service.
     */
    public function guardCreate(Service $service, ?array $record): ?ModuleResult
    {
        return null;
    }

    /**
     * Staff "Test panel sign-in": each check with its result. Never makes a sign-in session, and no
     * detail holds a password, key or token.
     *
     * @return list<array{label: string, status: 'ok'|'warn'|'fail'|'skip', detail: string}>
     */
    public function checks(Service $service): array
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Fields of the staff "Link existing VPS" form.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool}>
     */
    public function linkFields(): array
    {
        return [];
    }

    /**
     * Link the service to a VPS that already exists in Virtualizor. On success data['module_data']
     * holds the values core adds to the service.
     *
     * @param  array<string, string>  $input
     */
    public function link(Service $service, array $input): ModuleResult
    {
        throw new LogicException('Not built yet.');
    }

    /**
     * Whether panel sign-in can work at all, before asking Virtualizor: it is on for the product,
     * the server uses SSL and has its API password, and the panel port is an end-user port.
     */
    private function possible(Service $service): bool
    {
        $server = $service->server;

        return (string) ($service->product?->module_config['client_login'] ?? '') === '1'
            && $server !== null
            && $server->use_ssl === true
            && filled($server->password)
            && $this->enduser->port($service) !== null;
    }

    /**
     * Whether the Virtualizor user behind the VPS has only this client's VPS on this server, so a
     * panel sign-in shows nothing else. Checked live on every click; a refusal is logged for staff.
     * Services from before 1.1.0 have no stored user yet: it is stored once the check passes.
     */
    private function ownedByClient(Service $service, string $vpsId): bool
    {
        $server = $service->server;
        $info = $this->admin->vpsInfo($server, $vpsId);
        $uid = AdminApi::userId($info['uid'] ?? null);
        $stored = AdminApi::userId($service->module_data['uid'] ?? null);
        $refuse = function (string $reason) use ($service): bool {
            Activity::log('service.panel_login_refused', "Panel sign-in for service #{$service->id} ({$service->label()}) refused: {$reason}", $service);

            return false;
        };

        if ($uid === null) {
            return $refuse('Virtualizor did not list the VPS with its user.');
        }

        if ((int) ($info['suspended'] ?? 0) !== 0) {
            return $refuse('the VPS is suspended in Virtualizor.');
        }

        if ($stored !== null && $stored !== $uid) {
            return $refuse("the VPS now belongs to Virtualizor user {$uid}, not {$stored}.");
        }

        $user = $this->admin->call($server, ['act' => 'users', 'uid' => $uid], ['uid' => $uid])['users'][$uid] ?? null;
        $count = is_array($user) ? filter_var($user['numvps'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if (! is_array($user) || AdminApi::userId($user['uid'] ?? null) !== $uid || (string) ($user['type'] ?? '') !== '0' || $count === false) {
            return $refuse("Virtualizor user {$uid} is not a normal end user with a known number of VPS.");
        }

        // The user's VPS, listed one by one: the list must hold this VPS and agree with the count.
        $listed = $this->userVps($server, $user, $uid);

        if ($listed === null || ! in_array($vpsId, $listed, true) || count($listed) !== $count) {
            return $refuse("Virtualizor's list of the VPS of user {$uid} could not be checked or does not match its VPS count.");
        }

        // Every VPS of that user must be one of this client's live VPS on this server.
        $own = Service::query()
            ->where('client_id', $service->client_id)
            ->where('server_id', $server->id)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereHas('product', fn ($query) => $query->where('server_module', $this->module))
            ->get()
            ->map(fn (Service $other): string => (string) ($other->module_data['vpsid'] ?? ''))
            ->filter(fn (string $id): bool => ctype_digit($id))
            ->push($vpsId)
            ->all();

        if (array_diff($listed, $own) !== []) {
            return $refuse("Virtualizor user {$uid} has VPS that are not this client's.");
        }

        if ($stored === null) {
            $service->forceFill(['module_data' => array_merge((array) $service->module_data, ['uid' => $uid])])->save();
        }

        return true;
    }

    /**
     * The IDs of all VPS of a Virtualizor user: searched by the user's email and, when that finds
     * none, by the user ID. Null when the list cannot be trusted: a VPS of another user or without
     * its user is in it (the search did not work), or it may be cut short.
     *
     * @param  array<string, mixed>  $user
     * @return list<string>|null
     */
    private function userVps(Server $server, array $user, string $uid): ?array
    {
        $email = is_string($user['email'] ?? null) && filter_var($user['email'], FILTER_VALIDATE_EMAIL) !== false ? $user['email'] : null;

        foreach (array_filter([$email, $uid]) as $search) {
            $rows = (array) ($this->admin->call($server, ['act' => 'vs', 'search' => 1, 'user' => $search, 'page' => 1, 'reslen' => self::USER_VPS_LIMIT])['vs'] ?? []);

            if ($rows === []) {
                continue;
            }

            if (count($rows) >= self::USER_VPS_LIMIT) {
                return null;
            }

            $ids = [];

            foreach ($rows as $key => $row) {
                $id = is_array($row) ? (string) ($row['vpsid'] ?? $key) : '';

                if (! ctype_digit($id) || AdminApi::userId($row['uid'] ?? null) !== $uid) {
                    return null;
                }

                $ids[] = $id;
            }

            return array_values(array_unique($ids));
        }

        return [];
    }

    /**
     * One call to the end-user panel, signed with the admin API password the way Virtualizor's SDK
     * does it for admins: a random prefix and a hash, so the password itself is never sent.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function panelCall(Server $server, int $port, array $query): array
    {
        $salt = strtolower(Str::random(8));
        $query += ['api' => 'json', 'apikey' => $salt.md5((string) $server->password.$salt)];

        try {
            $response = Http::timeout(20)->acceptJson()->withoutRedirecting()->get($this->admin->baseUrl($server, $port, true).'/index.php', $query);
        } catch (Throwable) {
            throw new RuntimeException(__('Could not connect to :host.', ['host' => $server->hostname]));
        }

        return $this->admin->answer($server, $response->json(), $response->status());
    }
}
