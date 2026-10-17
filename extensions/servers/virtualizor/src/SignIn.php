<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Enums\ServiceStatus;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Panel\ClientContext;
use App\Models\Server;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The client's one-click sign-in to their own VPS in Virtualizor's end-user panel, and the staff
 * tools around it: who owns a VPS, the create-time guard, the "Test panel sign-in" checks and
 * linking a VPS that already exists. A sign-in link is made on every click, after checking again
 * that the Virtualizor user behind the VPS holds nothing of another client. It is never stored or
 * logged. Sign-in only ever uses the end-user port, never Virtualizor's admin panel.
 *
 * Who owns a VPS (ownership()): Virtualizor's end-user panel lists every VPS of the VPS's user.
 * Each of them must be a live service (active, suspended or pending) of this client on the same
 * Virtualizor master, or be linked to no service while the user's email is the client's confirmed
 * email. A VPS of another client, or one left over from an ended service, refuses the sign-in.
 */
final class SignIn
{
    /**
     * The most VPS of one Virtualizor user that a panel sign-in checks. A user with more is refused.
     */
    private const USER_VPS_LIMIT = 100;

    /**
     * Seconds the answer of exclusive() is kept.
     */
    private const EXCLUSIVE_SECONDS = 600;

    /**
     * Cache key: a client sign-in was refused in the last day because the site saw a Cloudflare
     * address instead of the client's own, so the staff check can say so.
     */
    private const UNTRUSTED_FLAG = 'nuvabill:vz:untrusted-ip';

    /**
     * The module data that belongs to one VPS: removed with the link to a VPS that was deleted.
     */
    private const VPS_KEYS = ['vpsid', 'uid', 'vps_uuid', 'ips'];

    /**
     * @param  string  $module  The module's slug, for finding its services.
     */
    public function __construct(private AdminApi $admin, private EnduserApi $enduser, private string $module) {}

    /**
     * A one-time sign-in link to the end-user panel for the client's own VPS, or null when sign-in
     * is off, not safe or did not work (staff see why in the activity log). Without a context (the
     * old ServerModule::loginUrl() call) no IP can be tied to the session, so there is no link.
     */
    public function signInUrl(Service $service, ?ClientContext $context): ?string
    {
        $server = $service->server;

        if ($context === null || $service->status !== ServiceStatus::Active || $server === null || ! $this->possible($service)) {
            return null;
        }

        // Behind Cloudflare without trusted proxies the IP is Cloudflare's: the session would be tied to it.
        if (! $context->ipTrusted) {
            Cache::put(self::UNTRUSTED_FLAG, true, 86400);

            if (Cache::add('nuvabill:vz:untrusted-ip-log:'.$service->id, true, 86400)) {
                Activity::log('service.panel_login_refused', "Panel sign-in for service #{$service->id} refused: set NUVABILL_TRUSTED_PROXIES=cloudflare in .env. Clients reach this site through Cloudflare, so without it Nuvabill cannot see their real IP and the sign-in cannot work.", $service);
            }

            return null;
        }

        $port = (int) $this->enduser->port($service);
        $owner = $this->ownership($service, $context);

        if (! $owner['ok']) {
            $reason = self::say($owner['reason'], 'en');

            $owner['error']
                ? Activity::log('service.module_failed', "Panel sign-in for service #{$service->id} ({$service->label()}) failed: {$reason}", $service)
                : Activity::log('service.panel_login_refused', "Panel sign-in for service #{$service->id} ({$service->label()}) refused: {$reason}", $service);

            return null;
        }

        if ($owner['unlinked'] !== [] && Cache::add('nuvabill:vz:unlinked:'.$service->id, true, 86400)) {
            Activity::log('service.panel_login_unlinked', "Panel sign-in for service #{$service->id} allowed Virtualizor user {$owner['uid']} with VPS that no service is linked to, as the user's email is the client's confirmed email. Link or delete these VPS: #".implode(', #', $owner['unlinked']), $service);
        }

        $vpsId = AdminApi::vpsId($service);

        try {
            $response = $this->enduser->call($server, $port, $vpsId, $context, 'sso', ['goto_cp' => $context->returnUrl], timeout: 20);
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

        return $this->admin->baseUrl($server, $port, true).'/'.$token.'/?'.http_build_query(['as' => $session, 'goto_cp' => $context->returnUrl, 'svs' => $vpsId], '', '&', PHP_QUERY_RFC3986);
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
     * A service already linked to a VPS is never given a second one while Virtualizor still lists
     * that VPS. A link to a VPS that Virtualizor no longer lists (deleted when the service ended,
     * before staff set the service back to Pending) is removed, with the values that belong to that
     * VPS, so the service gets a new VPS. A new VPS is filed under the Virtualizor user with the
     * client's email, so before one is sent that user is looked up: an admin or cloud user, a user
     * while the client has not confirmed that email, or a user that is not already linked to
     * another service of this client (or is linked to another client's), stops the create. Staff
     * link a VPS of such a user by hand ("Link existing VPS") first. A create with a note
     * was sent before and only looks for its VPS, so it is not checked again.
     *
     * @param  array<string, mixed>|null  $record  ResourceAddons::record() of the service.
     */
    public function guardCreate(Service $service, ?array $record): ?ModuleResult
    {
        if ($record !== null) {
            return null;
        }

        $linked = (string) ($service->module_data['vpsid'] ?? '');

        if ($linked !== '' && ctype_digit($linked)) {
            $refusal = $this->dropDeletedLink($service, $linked);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $email = strtolower(trim((string) $service->client?->email));

        if ($email === '' || $service->server === null) {
            return null;
        }

        try {
            $users = (array) ($this->admin->call($service->server, ['act' => 'users', 'email' => $email], ['email' => $email])['users'] ?? []);
        } catch (RuntimeException $exception) {
            return ModuleResult::fail(__('Could not check Virtualizor for a user with this client\'s email, so no VPS was made. Try again. (:error)', ['error' => $exception->getMessage()]));
        }

        $matches = array_filter($users, fn (mixed $user): bool => is_array($user) && is_string($user['email'] ?? null) && strtolower(trim($user['email'])) === $email);

        if ($matches === []) {
            return null;
        }

        foreach ($matches as $user) {
            if ((string) ($user['type'] ?? '') !== '0') {
                return ModuleResult::fail(__('A Virtualizor admin or cloud user has this client\'s email. Change the client\'s email or that user in Virtualizor. No VPS was made.'));
            }
        }

        if ($service->client?->email_verified_at === null) {
            return ModuleResult::fail(__('A Virtualizor user with this client\'s email already exists, and the client has not confirmed this email yet, so the VPS is not added to that user. Ask the client to confirm their email, then try again. No VPS was made.'));
        }

        // An email alone does not show whose user it is: someone may have made it with an address
        // they did not own. The user must already be linked to this client, and to no other client.
        foreach ($matches as $user) {
            $uid = AdminApi::userId($user['uid'] ?? null);

            if ($uid === null || ! $this->userLinkedOnlyTo($service, $uid)) {
                return ModuleResult::fail(__('A Virtualizor user with this client\'s email exists that Nuvabill has not linked to this client. Link one of the client\'s VPS to it, or change that user in Virtualizor. No VPS was made.'));
            }
        }

        return null;
    }

    /**
     * Whether the Virtualizor user is stored on another service of this client on the same master,
     * and on no service of another client there.
     */
    private function userLinkedOnlyTo(Service $service, string $uid): bool
    {
        $owners = Service::query()
            ->whereIn('server_id', $this->masters($service->server))
            ->whereKeyNot($service->id)
            ->whereIn('module_data->uid', [$uid, (int) $uid])
            ->get(['id', 'client_id', 'module_data'])
            ->filter(fn (Service $linked): bool => AdminApi::userId($linked->module_data['uid'] ?? null) === $uid);

        return $owners->isNotEmpty() && $owners->every(fn (Service $linked): bool => (int) $linked->client_id === (int) $service->client_id);
    }

    /**
     * Staff "Test panel sign-in": each check with its result. Never makes a sign-in session, and no
     * detail holds a password, key or token.
     *
     * @return list<array{label: string, status: 'ok'|'warn'|'fail'|'skip', detail: string}>
     */
    public function checks(Service $service): array
    {
        $server = $service->server;
        $rows = [];
        $row = function (string $label, string $status, string $detail) use (&$rows): void {
            $rows[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };
        $skipped = __('Skipped until the checks above pass.');

        $on = (string) ($service->product?->module_config['client_login'] ?? '') === '1';
        $row(__('Client panel sign-in'), $on ? 'ok' : 'fail', $on ? __('On for this product.') : __('Off. Turn on "Client panel sign-in" in the module settings of the product.'));

        if ($server === null) {
            $row(__('Server'), 'fail', __('No server is assigned to this service.'));

            return $rows;
        }

        $serverProblems = array_filter([
            $server->use_ssl !== true ? __('Turn on "Connect with HTTPS" on the server.') : null,
            blank($server->password) ? __('Set the API password on the server.') : null,
        ]);
        $row(__('Server settings'), $serverProblems === [] ? 'ok' : 'fail', $serverProblems === [] ? __('"Connect with HTTPS" is on and the API password is set.') : implode(' ', $serverProblems));

        $port = $this->enduser->port($service);
        $row(__('Client panel port'), $port !== null ? 'ok' : 'fail', $port !== null
            ? __('Port :port.', ['port' => $port])
            : __('The client panel port of the product is not valid, or it is an admin or API port of Virtualizor. Use 4083.'));

        $host = rtrim(strtolower(trim((string) $server->hostname)), '.');
        $row(__('Server hostname'), ...match (true) {
            filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => ['fail', __('An IPv6 address. The browser console cannot connect to it. Use a hostname with a valid SSL certificate.')],
            filter_var($host, FILTER_VALIDATE_IP) !== false => ['warn', __('An IP address. The console and sign-in need a valid SSL certificate for this address, which is rare. Use a hostname with a valid SSL certificate.')],
            default => ['ok', __('A hostname: :host.', ['host' => $host])],
        });

        $vpsId = (string) ($service->module_data['vpsid'] ?? '');

        if (! ctype_digit($vpsId)) {
            $row(__('Service'), 'fail', __('The service has no VPS ID. Link its VPS below.'));

            return [...$rows, ...$this->siteChecks($host)];
        }

        $row(__('Service'), $service->status === ServiceStatus::Active ? 'ok' : 'fail', $service->status === ServiceStatus::Active
            ? __('Active, VPS #:id.', ['id' => $vpsId])
            : __('The service is :status. Clients can only sign in to an active service.', ['status' => $service->status->label()]));

        if ($serverProblems !== [] || $port === null) {
            foreach ([__('VPS in Virtualizor'), __('Virtualizor user'), __('End-user port'), __('Ownership'), __('Console')] as $label) {
                $row($label, 'skip', $skipped);
            }

            return [...$rows, ...$this->siteChecks($host)];
        }

        $owner = $this->ownership($service, null);
        $reason = $owner['ok'] ? null : self::say($owner['reason'], null);
        $stages = ['vps' => __('VPS in Virtualizor'), 'user' => __('Virtualizor user'), 'list' => __('End-user port'), 'owner' => __('Ownership')];
        $passed = [
            'vps' => __('VPS #:id, Virtualizor user :uid, not suspended.', ['id' => $vpsId, 'uid' => (string) $owner['uid']]),
            'user' => __('A normal end user.'),
            'list' => __('Port :port answers with a valid certificate, and the API key is accepted.', ['port' => $port]),
            'owner' => $owner['unlinked'] === []
                ? __('Every VPS of this Virtualizor user belongs to this client.')
                : __('Allowed: VPS #:ids of this Virtualizor user are linked to no service, and the user\'s email is the client\'s confirmed email. Link or delete them.', ['ids' => implode(', #', $owner['unlinked'])]),
        ];
        $failedAt = $owner['ok'] ? null : $owner['stage'];
        $reached = true;

        foreach ($stages as $stage => $label) {
            if (! $reached) {
                $row($label, 'skip', $skipped);
            } elseif ($stage === $failedAt) {
                $row($label, 'fail', (string) $reason);
                $reached = false;
            } else {
                $row($label, 'ok', $passed[$stage]);
            }
        }

        $row(__('Console'), ...$this->consoleCheck($service, $server, (int) $port, $vpsId, $failedAt === null || $failedAt === 'owner'));

        return [...$rows, ...$this->siteChecks($host)];
    }

    /**
     * Fields of the staff "Link existing VPS" form.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool}>
     */
    public function linkFields(): array
    {
        return [
            'vpsid' => [
                'label' => __('VPS ID'),
                'type' => 'text',
                'required' => true,
                'help' => __('For a VPS made by hand in Virtualizor or moved from another billing system: Virtualizor → Virtual Servers → List VS.'),
            ],
            'confirm_email_mismatch' => [
                'label' => __('Link it even if the Virtualizor user\'s email is not the client\'s email'),
                'type' => 'checkbox',
            ],
        ];
    }

    /**
     * Link the service to a VPS that already exists in Virtualizor. On success data['module_data']
     * holds the values core adds to the service. The service status does not change.
     *
     * @param  array<string, string>  $input
     */
    public function link(Service $service, array $input): ModuleResult
    {
        $server = $service->server;
        $vpsId = trim((string) ($input['vpsid'] ?? ''));

        if ($server === null) {
            return ModuleResult::fail(__('This service has no server. Choose its Virtualizor server first.'));
        }

        if (preg_match('/^[1-9]\d{0,9}\z/', $vpsId) !== 1) {
            return ModuleResult::fail(__('Enter the VPS ID as a number.'));
        }

        $record = ResourceAddons::record($service);

        if ($record !== null && in_array($record['state'] ?? null, [ResourceAddons::REQUESTED, ResourceAddons::CREATED], true)) {
            return ModuleResult::fail(__('Finish or cancel the open create first.'));
        }

        try {
            $info = $this->admin->vpsInfo($server, $vpsId);

            if ($info === []) {
                return ModuleResult::fail(__('Virtualizor has no VPS #:id.', ['id' => $vpsId]));
            }

            $other = $this->linkedServices($this->masters($server), [$vpsId])->first(fn (Service $linked): bool => $linked->id !== $service->id);

            if ($other !== null) {
                return ModuleResult::fail(__('VPS #:id is already linked to service #:service.', ['id' => $vpsId, 'service' => $other->id]));
            }

            $uid = AdminApi::userId($info['uid'] ?? null);

            if ($uid === null) {
                return ModuleResult::fail(__('VPS #:id has no Virtualizor user. Give it a user in Virtualizor first.', ['id' => $vpsId]));
            }

            $user = $this->user($server, $uid);

            if ($user === null) {
                return ModuleResult::fail(__('Virtualizor user :uid was not found.', ['uid' => $uid]));
            }

            if ((string) ($user['type'] ?? '') !== '0') {
                return ModuleResult::fail(__('Virtualizor user :uid is an admin or cloud user. Only VPS of normal end users can be linked.', ['uid' => $uid]));
            }
        } catch (RuntimeException $exception) {
            return ModuleResult::fail($exception->getMessage());
        }

        $userEmail = strtolower(trim(is_string($user['email'] ?? null) ? $user['email'] : ''));

        if ($userEmail !== strtolower(trim((string) $service->client?->email)) && ($input['confirm_email_mismatch'] ?? '') !== '1') {
            return ModuleResult::fail(__('The email of Virtualizor user :uid is not this client\'s email. Tick the box to link the VPS anyway.', ['uid' => $uid]));
        }

        if ($record !== null && ($record['state'] ?? null) === ResourceAddons::VERIFIED) {
            ResourceAddons::remember($service, ['vpsid' => $vpsId] + $record);
        }

        $uuid = is_string($info['uuid'] ?? null) && preg_match('/^[A-Za-z0-9-]{8,64}\z/', $info['uuid']) === 1 ? strtolower($info['uuid']) : null;

        return ModuleResult::ok(__('Linked to VPS #:id of Virtualizor user :uid.', ['id' => $vpsId, 'uid' => $uid]), [
            'module_data' => ['vpsid' => $vpsId, 'uid' => $uid, 'ips' => AdminApi::addresses($info), 'vps_uuid' => $uuid],
        ]);
    }

    /**
     * Whether the Virtualizor user behind the service's VPS holds only this client's VPS, so the
     * panel may show things of the whole user: SSH keys, firewall plans and logs. Kept 10 minutes
     * for showing tabs and reads; when it is not, staff see why once a day.
     *
     * @param  bool  $fresh  Check live and keep the new answer: for an action that changes the
     *                       whole user, as staff may have put another client's VPS under it since.
     */
    public function exclusive(Service $service, bool $fresh = false): bool
    {
        $uid = AdminApi::userId($service->module_data['uid'] ?? null) ?? 'none';
        $key = "nuvabill:vz:exclusive:{$service->id}:{$uid}";
        $cached = $fresh ? null : Cache::get($key);

        if (is_bool($cached)) {
            return $cached;
        }

        $owner = $this->ownership($service, null);

        // A failed call is asked again soon; an answer is kept.
        Cache::put($key, $owner['ok'], $owner['error'] ? 60 : self::EXCLUSIVE_SECONDS);

        if (! $owner['ok'] && ! $owner['error'] && Cache::add('nuvabill:vz:not-exclusive:'.$service->id, true, 86400)) {
            Activity::log('service.module_failed', "The client panel of service #{$service->id} hides SSH keys, firewall plans and logs. Give each client their own Virtualizor user. ".self::say($owner['reason'], 'en'), $service);
        }

        return $owner['ok'];
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
     * Before a create for a service that is still linked to a VPS: a refusal while Virtualizor
     * lists that VPS or cannot clearly say it is gone, or null after the link to a VPS that
     * Virtualizor no longer lists was removed. Its user ID, UUID and IP addresses go with it, so the
     * new VPS's own are learned and the panel does not refuse the new VPS as "changed".
     *
     * Virtualizor's search by ID lists the IDs that contain it (177 for 77), as AdminApi::vpsInfo()
     * reads it. Only an answer whose rows all match that search and fill less than the page shows
     * the VPS is gone; any other answer could hide it, and a second VPS is never made on a guess.
     */
    private function dropDeletedLink(Service $service, string $vpsId): ?ModuleResult
    {
        $unclear = fn (string $error): ModuleResult => ModuleResult::fail(__('This service is linked to VPS #:id, and Virtualizor could not say whether that VPS still exists, so no VPS was made. Try again. (:error)', ['id' => $vpsId, 'error' => $error]));
        $reslen = 50;

        try {
            $listed = $this->admin->call($service->server, ['act' => 'vs', 'search' => 1, 'vpsid' => $vpsId, 'page' => 1, 'reslen' => $reslen])['vs'] ?? [];
        } catch (RuntimeException $exception) {
            return $unclear($exception->getMessage());
        }

        $ids = [];

        foreach (is_array($listed) ? $listed : [null] as $key => $row) {
            $ids[] = is_array($row) ? (string) ($row['vpsid'] ?? $key) : '';
        }

        if (in_array($vpsId, $ids, true)) {
            return ModuleResult::fail(__('This service is already linked to VPS #:id. Set its status to Active instead of creating a new VPS.', ['id' => $vpsId]));
        }

        if (count($ids) >= $reslen || array_filter($ids, fn (string $id): bool => ! ctype_digit($id) || ! str_contains($id, $vpsId)) !== []) {
            return $unclear(__('Its answer did not clearly show the VPS is gone.'));
        }

        $service->forceFill(['module_data' => Arr::except((array) $service->module_data, self::VPS_KEYS)])->save();
        Activity::log('service.vps_unlinked', "Service #{$service->id} ({$service->label()}) was unlinked from VPS #{$vpsId}, which Virtualizor no longer lists, so a new VPS can be made for it.", $service);

        return null;
    }

    /**
     * Who owns the service's VPS, checked live (see the class description). Never throws for a
     * failed call: it is a refusal with error set. The user ID is stored on the service once known.
     *
     * stage names the first check that did not pass: "vps" (the VPS in the Admin API), "user" (its
     * Virtualizor user), "list" (the end-user port's list of the user's VPS) or "owner" (whose those
     * VPS are). unlinked lists the user's VPS that no service is linked to but are allowed.
     *
     * @return array{ok: bool, error: bool, stage: string|null, reason: array{0: string, 1: array<string, string|int>}|null, uid: string|null, unlinked: list<string>}
     */
    private function ownership(Service $service, ?ClientContext $context): array
    {
        $refuse = fn (string $stage, string $text, array $values = [], bool $error = false, ?string $uid = null): array => [
            'ok' => false, 'error' => $error, 'stage' => $stage, 'reason' => [$text, $values], 'uid' => $uid, 'unlinked' => [],
        ];
        $server = $service->server;
        $vpsId = (string) ($service->module_data['vpsid'] ?? '');

        if ($server === null || ! ctype_digit($vpsId)) {
            return $refuse('vps', 'The service has no VPS ID.');
        }

        try {
            $info = $this->admin->vpsInfo($server, $vpsId);
        } catch (RuntimeException $exception) {
            return $refuse('vps', 'Virtualizor did not answer: :error', ['error' => $exception->getMessage()], true);
        }

        $uid = AdminApi::userId($info['uid'] ?? null);
        $stored = AdminApi::userId($service->module_data['uid'] ?? null);

        if ($info === []) {
            return $refuse('vps', 'Virtualizor does not list VPS #:vps.', ['vps' => $vpsId]);
        }

        if ($uid === null) {
            return $refuse('vps', 'VPS #:vps has no Virtualizor user.', ['vps' => $vpsId]);
        }

        if ((int) ($info['suspended'] ?? 0) !== 0) {
            return $refuse('vps', 'VPS #:vps is suspended in Virtualizor.', ['vps' => $vpsId], uid: $uid);
        }

        if ($stored !== null && $stored !== $uid) {
            return $refuse('vps', 'VPS #:vps now belongs to Virtualizor user :uid, not :stored.', ['vps' => $vpsId, 'uid' => $uid, 'stored' => $stored], uid: $uid);
        }

        try {
            $user = $this->user($server, $uid);
        } catch (RuntimeException $exception) {
            return $refuse('user', 'Virtualizor did not answer: :error', ['error' => $exception->getMessage()], true, $uid);
        }

        if ($user === null) {
            return $refuse('user', 'Virtualizor user :uid was not found.', ['uid' => $uid], uid: $uid);
        }

        if ((string) ($user['type'] ?? '') !== '0') {
            return $refuse('user', 'Virtualizor user :uid is an admin or cloud user. Only normal end users can sign in.', ['uid' => $uid], uid: $uid);
        }

        $port = $this->enduser->port($service);

        if ($port === null) {
            return $refuse('list', 'The client panel port of the product is not valid.', uid: $uid);
        }

        try {
            $list = $this->enduser->call($server, $port, $vpsId, $context, 'listvs', ['page' => 1, 'reslen' => self::USER_VPS_LIMIT]);
        } catch (RuntimeException $exception) {
            return $refuse('list', 'Virtualizor\'s end-user port :port did not list the VPS of user :uid: :error', ['port' => $port, 'uid' => $uid, 'error' => $exception->getMessage()], true, $uid);
        }

        if ($list['count'] >= self::USER_VPS_LIMIT) {
            return $refuse('list', 'Virtualizor user :uid has :count VPS or more, too many to check.', ['uid' => $uid, 'count' => self::USER_VPS_LIMIT], uid: $uid);
        }

        foreach ($list['vps'] as $listed => $owner) {
            if ($owner !== null && $owner !== $uid) {
                return $refuse('list', 'Virtualizor\'s list for user :uid holds VPS #:vps of another user.', ['uid' => $uid, 'vps' => $listed], uid: $uid);
            }
        }

        if ($list['invalid'] || ! array_key_exists($vpsId, $list['vps'])) {
            return $refuse('list', 'VPS #:vps is not in Virtualizor\'s list of the VPS of user :uid.', ['vps' => $vpsId, 'uid' => $uid], uid: $uid);
        }

        $ids = array_map('strval', array_keys($list['vps']));
        $linked = $this->linkedServices($this->masters($server), $ids);
        $live = [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending];
        $email = is_string($user['email'] ?? null) ? strtolower(trim($user['email'])) : '';
        $client = $service->client;
        $confirmed = $client !== null && $email !== '' && strtolower(trim((string) $client->email)) === $email && $client->email_verified_at !== null;
        $unlinked = [];

        foreach ($ids as $id) {
            $services = $linked->filter(fn (Service $other): bool => (string) ($other->module_data['vpsid'] ?? '') === $id);
            $foreign = $services->first(fn (Service $other): bool => (int) $other->client_id !== (int) $service->client_id);

            if ($foreign !== null) {
                return $refuse('owner', 'VPS #:vps belongs to service #:service of another client.', ['vps' => $id, 'service' => $foreign->id], uid: $uid);
            }

            if ($services->contains(fn (Service $own): bool => in_array($own->status, $live, true))) {
                continue;
            }

            if ($services->isNotEmpty()) {
                return $refuse('owner', 'VPS #:vps of ended service #:service still exists in Virtualizor. Delete it, or link it again.', ['vps' => $id, 'service' => $services->first()->id], uid: $uid);
            }

            if (! $confirmed) {
                return $refuse('owner', 'VPS #:vps of Virtualizor user :uid is linked to no service, and the user\'s email is not this client\'s confirmed email.', ['vps' => $id, 'uid' => $uid], uid: $uid);
            }

            $unlinked[] = $id;
        }

        if ($stored === null) {
            $this->rememberUser($service, $vpsId, $uid);
        }

        return ['ok' => true, 'error' => false, 'stage' => null, 'reason' => null, 'uid' => $uid, 'unlinked' => $unlinked];
    }

    /**
     * Keep the user ID of the service's VPS once ownership() found it. Only the uid key is added,
     * to the service row as it is now: the checks before it take up to a minute, and other requests
     * may have changed the module data meanwhile (a staff link, the reinstall count, the VPS's
     * UUID). Nothing is saved when the service was linked to another VPS or got a user ID in that
     * time.
     */
    private function rememberUser(Service $service, string $vpsId, string $uid): void
    {
        $data = DB::transaction(function () use ($service, $vpsId, $uid): ?array {
            $fresh = Service::query()->lockForUpdate()->find($service->id, ['id', 'module_data']);
            $data = (array) ($fresh?->module_data ?? []);

            if ($fresh === null || (string) ($data['vpsid'] ?? '') !== $vpsId || AdminApi::userId($data['uid'] ?? null) !== null) {
                return null;
            }

            $data['uid'] = $uid;
            $fresh->forceFill(['module_data' => $data])->save();

            return $data;
        });

        if ($data !== null) {
            $service->module_data = $data;
            $service->syncOriginalAttribute('module_data');
        }
    }

    /**
     * A Virtualizor user by ID from the Admin API, or null when it is not listed.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException When Virtualizor does not answer.
     */
    private function user(Server $server, string $uid): ?array
    {
        $user = $this->admin->call($server, ['act' => 'users', 'uid' => $uid], ['uid' => $uid])['users'][$uid] ?? null;

        return is_array($user) && AdminApi::userId($user['uid'] ?? null) === $uid ? $user : null;
    }

    /**
     * The IDs of the Virtualizor server records for the same master: the same hostname, without
     * case or a final dot.
     *
     * @return list<int>
     */
    private function masters(Server $server): array
    {
        $host = self::host((string) $server->hostname);

        return Server::query()
            ->where('module', $this->module)
            ->get(['id', 'hostname'])
            ->filter(fn (Server $other): bool => self::host((string) $other->hostname) === $host)
            ->map(fn (Server $other): int => (int) $other->id)
            ->push((int) $server->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every service of any status and client on those servers that is linked to one of the VPS.
     *
     * @param  list<int>  $serverIds
     * @param  list<string>  $vpsIds
     * @return Collection<int, Service>
     */
    private function linkedServices(array $serverIds, array $vpsIds): Collection
    {
        if ($vpsIds === []) {
            return collect();
        }

        // The ID may be stored as a number or as text.
        $values = array_merge($vpsIds, array_map('intval', $vpsIds));

        return Service::query()
            ->whereIn('server_id', $serverIds)
            ->whereIn('module_data->vpsid', $values)
            ->get(['id', 'client_id', 'status', 'server_id', 'module_data'])
            ->filter(fn (Service $linked): bool => in_array((string) ($linked->module_data['vpsid'] ?? ''), $vpsIds, true))
            ->values();
    }

    /**
     * The console check: VNC is on for the VPS, from the end-user panel.
     *
     * @return array{0: string, 1: string}
     */
    private function consoleCheck(Service $service, Server $server, int $port, string $vpsId, bool $reachable): array
    {
        if (! $reachable) {
            return ['skip', __('Skipped until the checks above pass.')];
        }

        try {
            $vnc = $this->enduser->call($server, $port, $vpsId, null, 'vpsmanage')['vps']['vnc'];
        } catch (RuntimeException $exception) {
            return ['warn', __('Could not read the VPS settings: :error', ['error' => $exception->getMessage()])];
        }

        return match ($vnc) {
            true => ['ok', __('VNC is on for this VPS.')],
            false => ['warn', __('VNC is off for this VPS, so the console cannot open. Turn it on in Virtualizor.')],
            default => ['warn', __('Virtualizor did not say whether VNC is on for this VPS.')],
        };
    }

    /**
     * Checks of the site and the master's name that do not need the VPS: whether a client sign-in
     * was refused for a Cloudflare address, and whether the master has an IPv6 address.
     *
     * @return list<array{label: string, status: 'ok'|'warn'|'fail'|'skip', detail: string}>
     */
    private function siteChecks(string $host): array
    {
        $rows = [];

        $rows[] = Cache::get(self::UNTRUSTED_FLAG) === true
            ? ['label' => __('Client IP address'), 'status' => 'fail', 'detail' => __('A client was refused in the last day because this site saw a Cloudflare address instead of the client\'s. Set NUVABILL_TRUSTED_PROXIES=cloudflare in .env.')]
            : ['label' => __('Client IP address'), 'status' => 'ok', 'detail' => __('No client was refused for a proxy address in the last day. Behind Cloudflare, set NUVABILL_TRUSTED_PROXIES=cloudflare in .env.')];

        $ipv6 = $this->hasIpv6($host);
        $rows[] = match ($ipv6) {
            true => ['label' => __('IPv6'), 'status' => 'ok', 'detail' => __(':host has an IPv6 address.', ['host' => $host])],
            false => ['label' => __('IPv6'), 'status' => 'warn', 'detail' => __(':host has no IPv6 address. Clients who use IPv6 may land on Virtualizor\'s sign-in page.', ['host' => $host])],
            default => ['label' => __('IPv6'), 'status' => 'skip', 'detail' => __('Not checked for this name.')],
        };

        return $rows;
    }

    /**
     * Whether the master's name has an IPv6 address (AAAA), kept an hour; null when it was not
     * looked up: an IP address, or a name that is reserved for tests and never resolves.
     */
    private function hasIpv6(string $host): ?bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/(^|\.)(test|example|invalid|localhost|local)\z/', $host) === 1 || ! function_exists('dns_get_record')) {
            return null;
        }

        return Cache::remember('nuvabill:vz:aaaa:'.sha1($host), 3600, function () use ($host): bool {
            try {
                $records = @dns_get_record($host, DNS_AAAA);
            } catch (Throwable) {
                $records = false;
            }

            return is_array($records) && $records !== [];
        });
    }

    /**
     * A hostname without case, spaces or a final dot.
     */
    private static function host(string $hostname): string
    {
        return rtrim(strtolower(trim($hostname)), '.');
    }

    /**
     * A reason in the given language (null: the current one). Activity log lines stay English.
     *
     * @param  array{0: string, 1: array<string, string|int>}|null  $reason
     */
    private static function say(?array $reason, ?string $locale): string
    {
        return $reason === null ? '' : __($reason[0], $reason[1], $locale);
    }
}
