<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelException;
use App\Extensions\Servers\Panel\StandardSections;
use App\Models\Server;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Virtualizor's end-user panel (port 4083 by default), for the client server panel, the console and
 * sign-in. Calls are signed with the admin API key and a salted hash of the API password (never the
 * password itself), go only to an allowed act with its allowed keys, and are pinned to the
 * service's own VPS: no client input ever picks the VPS, the act or a key.
 *
 * Every answer is cut down to the fields its act may give (project()), so a password, a key or
 * another user's data never leaves this class. URLs and answers are never logged.
 */
final class EnduserApi
{
    /**
     * Virtualizor's end-user panel port with SSL.
     */
    public const PORT = 4083;

    /**
     * Virtualizor's other panel ports: the admin panel (4084, 4085) and plain HTTP (4081, 4082).
     * Clients never reach them: a sign-in there could open the admin panel.
     */
    public const NOT_PANEL_PORTS = [4081, 4082, 4084, 4085];

    /**
     * Exception code: the end-user panel could not be reached (no connection or no TLS).
     */
    public const UNREACHABLE = 2;

    /**
     * The end-user acts the panel may call, by key: the act, the query and POST keys it may send,
     * and its scope ("vps": the service's VPS, pinned; "account": the Virtualizor user, which must
     * hold only this client's VPS; "owner": the ownership check). Any other act is never sent.
     *
     * Never allowed, on purpose: profile, userpassword, usersettings, apikey, twofactauth, users,
     * adduser, edituser, create, editvm, listvs with delvs or suspend, volume, cloudres, billing,
     * pdns, managezone, webuzo, processes, services, managevdf, load_balancer, setup_pending,
     * euiso and addiso (an ISO by link would make the Virtualizor host fetch any address) and
     * controlpanel.
     *
     * @var array<string, array{act: string, query: list<string>, post: list<string>, scope: 'vps'|'account'|'owner'}>
     */
    public const ACTS = [
        'vpsmanage' => ['act' => 'vpsmanage', 'query' => [], 'post' => [], 'scope' => 'vps'],
        'monitor' => ['act' => 'monitor', 'query' => ['show'], 'post' => [], 'scope' => 'vps'],
        'bandwidth' => ['act' => 'bandwidth', 'query' => ['show'], 'post' => [], 'scope' => 'vps'],
        'ctasks' => ['act' => 'ctasks', 'query' => ['page'], 'post' => [], 'scope' => 'vps'],
        'logs' => ['act' => 'logs', 'query' => [], 'post' => [], 'scope' => 'account'],
        'rdns' => ['act' => 'rdns', 'query' => ['delete'], 'post' => ['rdns', 'rdns_ip', 'rdns_domain'], 'scope' => 'account'],
        'firewallplan' => ['act' => 'firewallplan', 'query' => [], 'post' => ['changefirewallplan', 'change_fwid', 'delete_fwids'], 'scope' => 'account'],
        'addfirewallplan' => ['act' => 'addfirewallplan', 'query' => [], 'post' => ['addfirewallplan', 'fwp_name', 'default_policy', 'api_firewall_rules'], 'scope' => 'account'],
        'backup2' => ['act' => 'backup2', 'query' => [], 'post' => ['cbackup', 'bkid', 'restore', 'delete'], 'scope' => 'vps'],
        'rescue' => ['act' => 'rescue', 'query' => [], 'post' => ['enablerescue', 'disablerescue', 'password', 'conf_password'], 'scope' => 'vps'],
        'sshkeys' => ['act' => 'sshkeys', 'query' => [], 'post' => ['delete', 'ssh_keys', 'sshkey_add', 'addkeyvps'], 'scope' => 'account'],
        'addsshkey' => ['act' => 'addsshkey', 'query' => [], 'post' => ['addsshkey', 'name', 'value'], 'scope' => 'account'],
        'hvmsettings' => ['act' => 'hvmsettings', 'query' => [], 'post' => ['hvmsettings', 'boot_ord_val', 'isos', 'sec_iso', ...self::HVM_FIELDS], 'scope' => 'vps'],
        'vncpass' => ['act' => 'vncpass', 'query' => [], 'post' => ['vncpass', 'newpass', 'conf'], 'scope' => 'vps'],
        'listvs' => ['act' => 'listvs', 'query' => ['page', 'reslen'], 'post' => [], 'scope' => 'owner'],
        'sso' => ['act' => 'sso', 'query' => ['goto_cp'], 'post' => [], 'scope' => 'vps'],
    ];

    /**
     * Settings of the VPS's "VPS configuration" page that a change of the ISO or boot order sends
     * back unchanged, so the rest of the page keeps its values.
     *
     * @var list<string>
     */
    public const HVM_FIELDS = ['acpi', 'apic', 'vnc', 'vnc_keymap', 'kvm_vga', 'acceleration', 'nic_type', 'virtio', 'timezone'];

    /**
     * Query keys every call gets last, so they always win over any other value.
     *
     * @var list<string>
     */
    private const PINNED = ['act', 'svs', 'api', 'adminapikey', 'apikey', 'SET_REMOTE_IP'];

    /**
     * The most rows of a list that leave this class, per act.
     *
     * @var array<string, int>
     */
    private const LIMITS = ['ctasks' => 50, 'logs' => 50, 'rdns' => 200, 'backup2' => 100, 'sshkeys' => 50, 'firewallplan' => 50, 'hvmsettings' => 200];

    /**
     * The most points of one monitor series that leave this class. A longer series is thinned by
     * averaging neighbouring points, so it still covers the whole month.
     */
    private const MONITOR_POINTS = 5000;

    public function __construct(private AdminApi $admin) {}

    /**
     * The end-user panel port for the service's product (Virtualizor's 4083 unless the product sets
     * another), or null when the product sets an invalid port, or a port of the admin panel or the
     * Admin API: a sign-in there could open Virtualizor's admin panel. Staff see why once a day.
     */
    public function port(Service $service): ?int
    {
        $value = $service->product?->module_config['panel_port'] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            $port = self::PORT;
        } else {
            $port = is_int($value) || is_string($value) ? filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) : false;

            if ($port === false) {
                return null;
            }
        }

        if (in_array($port, self::NOT_PANEL_PORTS, true) || $port === (int) ($service->server?->port ?: AdminApi::DEFAULT_PORT)) {
            if (Cache::add('nuvabill.virtualizor.admin-panel-port.'.$service->product_id.'.'.$port, true, 86400)) {
                Activity::log('service.module_failed', "Client panel sign-in is off for the product of service #{$service->id} ({$service->label()}): its client panel port {$port} is an admin or API port of Virtualizor. Set Virtualizor's end-user port (4083) as the client panel port.", $service);
            }

            return null;
        }

        return $port;
    }

    /**
     * One signed call to the end-user panel for one act of ACTS. Only that act's query and POST
     * keys are sent; svs (the service's VPS), api, the signature and SET_REMOTE_IP (only for a
     * trusted client IP) are added last, so they always win. The Admin API password is never sent.
     * A failed connection names only the host; an error from Virtualizor is cleaned of secrets.
     *
     * @param  string  $key  A key of ACTS.
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     * @return array<string, mixed> The answer, projected to the fields the act may give.
     *
     * @throws RuntimeException When the panel does not answer or refuses (code AdminApi::REFUSED when it refused).
     * @throws PanelException When the answer is about another VPS.
     */
    public function call(Server $server, int $port, string $vpsId, ?ClientContext $context, string $key, array $query = [], array $post = [], int $timeout = 8): array
    {
        [$status, $json, $apikey] = $this->request($server, $port, $vpsId, $context, $key, $query, $post, $timeout);

        try {
            $data = $this->admin->answer($server, $json, $status);
        } catch (RuntimeException $exception) {
            // The salted key is a working signature: it never goes into a message.
            throw new RuntimeException(str_replace($apikey, '[hidden]', $exception->getMessage()), $exception->getCode());
        }

        $vps = Arr::get($data, 'info.vps', Arr::get($data, 'vps'));
        $answeredFor = is_array($vps) && is_scalar($vps['vpsid'] ?? null) ? (string) $vps['vpsid'] : null;

        if ($answeredFor !== null && $vpsId !== '0' && $answeredFor !== $vpsId) {
            throw new PanelException(__('This server changed. Contact support.'), "Virtualizor's end-user panel answered for VPS #{$answeredFor} instead of VPS #{$vpsId}.");
        }

        return $this->project($key, $data);
    }

    /**
     * A line for the server's "Test connection" about the end-user port (reachable, certificate,
     * signature), or null when there is nothing to add.
     */
    public function connectionNote(Server $server): ?string
    {
        if (blank($server->password)) {
            return null;
        }

        $port = self::PORT;

        try {
            [, $json] = $this->request($server, $port, '0', null, 'listvs', ['page' => 1, 'reslen' => 1], [], 10);
        } catch (RuntimeException) {
            return __('End-user port :port: could not connect. Allow port :port from this server and install a valid SSL certificate. Without it clients get only basic panel features, no sign-in and no console.', ['port' => $port]);
        }

        $errors = is_array($json) && ! empty($json['error']) ? implode(' ', array_map('strval', array_filter(Arr::flatten((array) $json['error']), 'is_scalar'))) : '';

        if (! is_array($json) || preg_match('/api ?key|auth|login|permission|not allowed|denied|unauthori[sz]ed|signature/i', $errors) === 1) {
            return __('End-user port :port: the API key was refused. Use the key from Server Info, or allow listvs and sso for this key.', ['port' => $port]);
        }

        return __('End-user port :port: reachable, certificate valid.', ['port' => $port]);
    }

    /**
     * Send one allowed call, signed and pinned. Gives the HTTP status, the decoded JSON (null when
     * the answer was not JSON) and the salted key it used, so callers can hide it.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     * @return array{0: int, 1: mixed, 2: string}
     *
     * @throws LogicException For an act or a key that is not allowed: a coding error, never input.
     * @throws RuntimeException When the panel cannot be reached.
     */
    private function request(Server $server, int $port, string $vpsId, ?ClientContext $context, string $key, array $query, array $post, int $timeout): array
    {
        $entry = self::ACTS[$key] ?? throw new LogicException("The end-user act [{$key}] is not allowed.");

        foreach (array_keys($post) as $field) {
            if (! in_array($field, $entry['post'], true)) {
                throw new LogicException("The end-user act [{$key}] may not post [{$field}].");
            }
        }

        if (! ctype_digit($vpsId)) {
            throw new LogicException('The end-user panel needs a VPS ID.');
        }

        $salt = Str::lower(Str::random(8));
        $apikey = $salt.md5((string) $server->password.$salt);
        $allowed = Arr::except(Arr::only($query, $entry['query']), self::PINNED);
        $signed = ['act' => $entry['act']] + array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $allowed) + array_filter([
            'svs' => $vpsId,
            'api' => 'json',
            'adminapikey' => (string) $server->api_token,
            'apikey' => $apikey,
            'SET_REMOTE_IP' => $context !== null && $context->ipTrusted ? $context->ip : '',
        ], fn (string $value): bool => $value !== '');

        $url = $this->admin->baseUrl($server, $port, true).'/index.php';

        try {
            // A closed port fails fast, so a panel page never waits long for it.
            $request = Http::timeout($timeout)->connectTimeout(min($timeout, 5))->acceptJson()->asForm()->withoutRedirecting();
            $response = $post === [] ? $request->get($url, $signed) : $request->post($url.'?'.http_build_query($signed, '', '&', PHP_QUERY_RFC3986), $post);
        } catch (Throwable) {
            throw new RuntimeException(__('Could not connect to :host.', ['host' => $server->hostname]), self::UNREACHABLE);
        }

        return [$response->status(), $response->json(), $apikey];
    }

    /**
     * The fields of an answer that may leave this class, by act.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function project(string $key, array $data): array
    {
        $info = is_array($data['info'] ?? null) ? $data['info'] : [];
        $get = fn (string $path): mixed => Arr::get($info, $path, Arr::get($data, $path));
        $done = ! empty($data['done']);

        return match ($key) {
            'vpsmanage' => $this->manage($get),
            'monitor' => ['series' => $this->monitor($get('monthly_data')), 'created' => self::time($get('vps_create_date'))],
            'bandwidth' => $this->bandwidth($get('bandwidth')),
            'ctasks' => ['tasks' => $this->tasks($get('tasks'))],
            'logs' => ['logs' => $this->logs($get('logs'))],
            'rdns' => ['done' => $done, 'records' => $this->records($get('rdns') ?? $get('records'))],
            'firewallplan' => ['done' => $done, 'current' => self::text($get('current_firewall_plan'), 20), 'plans' => $this->plans($get('firewall_plans') ?? $get('plans'))],
            'addfirewallplan', 'addsshkey' => ['done' => $done],
            'backup2' => $this->backups($get, $done),
            'rescue' => ['done' => $done, 'enabled' => self::flag($get('rescue_enabled')), 'blocked' => $this->blocked($get('cant_rescue'))],
            'sshkeys' => ['done' => $done, 'keys' => $this->keys($get('ssh_keys') ?? $get('sshkeys') ?? $get('keys'))],
            'hvmsettings' => $this->hvm($get, $done),
            'vncpass' => ['done' => $done, 'onboot' => self::flag($get('onboot'))],
            'listvs' => $this->owned($get('vs')),
            'sso' => ['token_key' => self::text($get('token_key'), 128) ?? '', 'sid' => self::text($get('sid'), 256) ?? ''],
            default => [],
        };
    }

    /**
     * vpsmanage: the VPS's state and the panel switches (Virtualizor's flags), without its
     * passwords or login.
     *
     * @param  callable(string): mixed  $get
     * @return array{status: int|null, virt: string|null, hostname: string|null, os: string|null, vps: array{vpsid: string|null, uuid: string|null, vnc: bool|null, suspended: bool, rescue: bool, admin_managed: bool, setup_pending: bool}, nw_suspended: array{on: bool, reason: string|null}, flags: array<string, int>}
     */
    private function manage(callable $get): array
    {
        $vps = is_array($get('vps')) ? $get('vps') : [];
        $flags = [];

        foreach ((array) $get('flags') as $name => $value) {
            if (! is_string($name) || preg_match('/^[a-z0-9_]{1,64}\z/', $name) !== 1 || in_array($name, ['vm_pass', 'vm_username'], true)) {
                continue;
            }

            // A name that looks like a secret keeps only an on/off value, never a number that could be one.
            $secretName = preg_match(StandardSections::SCRUB_PATTERN, $name) === 1 || str_contains($name, 'pass');
            $allowed = $secretName ? '/^[01]\z/' : '/^\d{1,10}\z/';

            if (is_bool($value) || ((is_int($value) || is_string($value)) && preg_match($allowed, (string) $value) === 1)) {
                $flags[$name] = (int) $value;
            }
        }

        $network = $get('nw_suspended');
        $reason = is_array($network) ? ($network['reason'] ?? null) : null;

        return [
            'status' => is_numeric($get('status')) ? (int) $get('status') : null,
            'virt' => self::text($get('virt') ?? ($vps['virt'] ?? null), 20),
            'hostname' => self::text($get('hostname') ?? ($vps['hostname'] ?? null), 253),
            'os' => self::text($get('os.name') ?? ($vps['os_name'] ?? null), 100),
            'vps' => [
                'vpsid' => is_scalar($vps['vpsid'] ?? null) ? (string) $vps['vpsid'] : null,
                'uuid' => is_string($vps['uuid'] ?? null) && preg_match('/^[A-Za-z0-9-]{8,64}\z/', $vps['uuid']) === 1 ? strtolower($vps['uuid']) : null,
                'vnc' => array_key_exists('vnc', $vps) ? self::flag($vps['vnc']) : null,
                'suspended' => self::flag($vps['suspended'] ?? null),
                'rescue' => self::flag($vps['rescue'] ?? null),
                'admin_managed' => self::flag($vps['admin_managed'] ?? null),
                'setup_pending' => self::flag(Arr::get($vps, 'data.setup_pending')),
            ],
            'nw_suspended' => [
                'on' => is_array($network) ? $network !== [] : self::flag($network),
                'reason' => self::text($reason ?? $get('unsupend_time_msg'), 300),
            ],
            'flags' => $flags,
        ];
    }

    /**
     * monitor: each series as [unix time, value] rows in time order, from either shape Virtualizor
     * may use: rows by time with a field per series, or a list of times per series. A series of more
     * than MONITOR_POINTS rows (a month at 5-minute or 1-minute steps) is thinned, never cut short.
     *
     * @return array<string, list<array{0: int, 1: float|null}>>
     */
    private function monitor(mixed $data): array
    {
        $names = ['cpu', 'ram', 'disk', 'net_in', 'net_out', 'io_read', 'io_write'];
        $series = array_fill_keys($names, []);

        if (! is_array($data)) {
            return $series;
        }

        if (array_filter(array_intersect_key($data, $series), 'is_array') !== []) {
            // A list of times per series: {"cpu": {"1760000000": 12.5, …}, …}.
            foreach ($names as $name) {
                foreach ((array) ($data[$name] ?? []) as $time => $value) {
                    $at = self::time($time);

                    if ($at !== null) {
                        $series[$name][] = [$at, is_numeric($value) ? (float) $value : null];
                    }
                }
            }
        } else {
            // Rows by time with a field per series: {"1760000000": {"cpu": 12.5, …}, …}.
            foreach ($data as $time => $row) {
                $at = is_array($row) ? self::time($row['time'] ?? $time) : null;

                if ($at === null) {
                    continue;
                }

                foreach ($names as $name) {
                    if (array_key_exists($name, $row)) {
                        $series[$name][] = [$at, is_numeric($row[$name]) ? (float) $row[$name] : null];
                    }
                }
            }
        }

        foreach ($series as $name => $points) {
            usort($points, fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $series[$name] = self::thin($points, self::MONITOR_POINTS);
        }

        return $series;
    }

    /**
     * At most $limit points in time order: neighbouring points are averaged in equal groups, each
     * at the time of its first point. Empty values are left out of an average; a group of only
     * empty values stays empty.
     *
     * @param  list<array{0: int, 1: float|null}>  $points  Sorted by time.
     * @return list<array{0: int, 1: float|null}>
     */
    private static function thin(array $points, int $limit): array
    {
        if (count($points) <= $limit) {
            return $points;
        }

        $thinned = [];

        foreach (array_chunk($points, (int) ceil(count($points) / $limit)) as $group) {
            $values = array_filter(array_column($group, 1), fn (?float $value): bool => $value !== null);
            $thinned[] = [$group[0][0], $values === [] ? null : array_sum($values) / count($values)];
        }

        return $thinned;
    }

    /**
     * bandwidth: the month's limit and use in GB, and the daily traffic in MB by day of the month.
     *
     * @return array{limit_gb: float|null, used_gb: float|null, days: array<int, array{0: float|null, 1: float|null}>}
     */
    private function bandwidth(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $days = [];
        $in = (array) ($data['in'] ?? []);
        $out = (array) ($data['out'] ?? []);

        foreach (array_unique([...array_keys($in), ...array_keys($out)]) as $day) {
            if (is_int($day) || ctype_digit((string) $day)) {
                $number = (int) $day;

                if ($number >= 1 && $number <= 31) {
                    $days[$number] = [is_numeric($in[$day] ?? null) ? (float) $in[$day] : null, is_numeric($out[$day] ?? null) ? (float) $out[$day] : null];
                }
            }
        }

        ksort($days);

        return [
            'limit_gb' => is_numeric($data['limit_gb'] ?? null) ? (float) $data['limit_gb'] : null,
            'used_gb' => is_numeric($data['used_gb'] ?? null) ? (float) $data['used_gb'] : null,
            'days' => $days,
        ];
    }

    /**
     * @return list<array{id: string, action: string, status: string, progress: float|null, started_at: int|null, updated_at: int|null, ended_at: int|null}>
     */
    private function tasks(mixed $rows): array
    {
        $tasks = [];

        foreach (array_slice(is_array($rows) ? $rows : [], 0, self::LIMITS['ctasks'], true) as $id => $row) {
            if (! is_array($row)) {
                continue;
            }

            $progress = $row['progress_num'] ?? $row['progress'] ?? null;

            $tasks[] = [
                'id' => (string) (is_scalar($row['actid'] ?? null) ? $row['actid'] : $id),
                'action' => self::text($row['action_txt'] ?? $row['action'] ?? null, 100) ?? '',
                'status' => self::text($row['status_txt'] ?? $row['status'] ?? null, 60) ?? '',
                'progress' => is_numeric($progress) ? max(0.0, min(100.0, (float) $progress)) : null,
                'started_at' => self::time($row['started'] ?? null),
                'updated_at' => self::time($row['updated'] ?? null),
                'ended_at' => self::time($row['ended'] ?? null),
            ];
        }

        return $tasks;
    }

    /**
     * The account's log lines, without the IP address they came from.
     *
     * @return list<array{at: int|null, text: string, status: string}>
     */
    private function logs(mixed $rows): array
    {
        $logs = [];

        foreach (array_slice(is_array($rows) ? $rows : [], 0, self::LIMITS['logs']) as $row) {
            if (is_array($row)) {
                $logs[] = [
                    'at' => self::time($row['time'] ?? null),
                    'text' => self::text($row['action_text'] ?? $row['action'] ?? null, 200) ?? '',
                    'status' => self::text($row['status'] ?? null, 40) ?? '',
                ];
            }
        }

        return $logs;
    }

    /**
     * @return list<array{id: string, ip: string, content: string}>
     */
    private function records(mixed $rows): array
    {
        $records = [];

        foreach (array_slice(is_array($rows) ? $rows : [], 0, self::LIMITS['rdns'], true) as $id => $row) {
            if (! is_array($row)) {
                continue;
            }

            $recordId = (string) (is_scalar($row['id'] ?? null) ? $row['id'] : $id);
            $ip = self::text($row['ip'] ?? $row['rdns_ip'] ?? null, 64);
            $content = self::text($row['content'] ?? $row['domain'] ?? $row['rdns_domain'] ?? null, 253);

            if (ctype_digit($recordId) && $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false && $content !== null) {
                $records[] = ['id' => $recordId, 'ip' => $ip, 'content' => $content];
            }
        }

        return $records;
    }

    /**
     * @return list<array{id: string, name: string, default_policy: string, uid: string|null, rules: list<array{direction: string, protocol: string, port: string|null, source: string|null, action: string}>}>
     */
    private function plans(mixed $rows): array
    {
        $plans = [];

        foreach (array_slice(is_array($rows) ? $rows : [], 0, self::LIMITS['firewallplan'], true) as $id => $row) {
            if (! is_array($row)) {
                continue;
            }

            $planId = (string) (is_scalar($row['fwid'] ?? $row['id'] ?? null) ? ($row['fwid'] ?? $row['id']) : $id);

            if (! ctype_digit($planId)) {
                continue;
            }

            $rules = [];

            foreach (array_slice((array) ($row['rules'] ?? []), 0, 100) as $rule) {
                if (is_array($rule)) {
                    $rules[] = [
                        'direction' => strtolower((string) self::text($rule['direction'] ?? $rule['type'] ?? null, 10)) === 'out' ? 'out' : 'in',
                        'protocol' => in_array($protocol = strtolower((string) self::text($rule['protocol'] ?? null, 10)), ['tcp', 'udp', 'icmp'], true) ? $protocol : 'all',
                        'port' => self::text($rule['port'] ?? $rule['dport'] ?? null, 11),
                        'source' => self::text($rule['source'] ?? $rule['ip'] ?? null, 64),
                        'action' => strtolower((string) self::text($rule['action'] ?? null, 10)) === 'drop' ? 'drop' : 'accept',
                    ];
                }
            }

            $plans[] = [
                'id' => $planId,
                'name' => self::text($row['fwp_name'] ?? $row['name'] ?? null, 50) ?? '#'.$planId,
                'default_policy' => strtolower((string) self::text($row['default_policy'] ?? null, 10)) === 'drop' ? 'drop' : 'accept',
                'uid' => AdminApi::userId($row['uid'] ?? null),
                'rules' => $rules,
            ];
        }

        return $plans;
    }

    /**
     * @param  callable(string): mixed  $get
     * @return array{done: bool, items: list<array{id: string, created_at: int|null, size_bytes: int|null}>, limit: int|null, restores_used: int|null, restores_limit: int|null}
     */
    private function backups(callable $get, bool $done): array
    {
        $items = [];

        foreach (array_slice(is_array($get('backup_file_list')) ? $get('backup_file_list') : [], 0, self::LIMITS['backup2'], true) as $id => $row) {
            $name = is_array($row) ? ($row['name'] ?? $row['id'] ?? $row['bkid'] ?? $id) : $id;
            $name = is_scalar($name) ? (string) $name : '';

            if (preg_match('/^[A-Za-z0-9_.-]{1,64}\z/', $name) !== 1) {
                continue;
            }

            $size = is_array($row) ? ($row['size'] ?? null) : null;

            $items[] = [
                'id' => $name,
                'created_at' => self::time(is_array($row) ? ($row['time'] ?? $row['date'] ?? $row['created'] ?? null) : null),
                'size_bytes' => is_numeric($size) && (float) $size >= 0 ? (int) $size : null,
            ];
        }

        $number = fn (string $path): ?int => is_numeric($get($path)) && (int) $get($path) >= 0 ? (int) $get($path) : null;

        return [
            'done' => $done,
            'items' => $items,
            'limit' => $number('backup_limit'),
            'restores_used' => $number('restores_used'),
            'restores_limit' => $number('restore_limit'),
        ];
    }

    /**
     * Why rescue mode cannot be turned on now, or null when it can.
     */
    private function blocked(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '' && trim($value) !== '0') {
            return self::text($value, 300);
        }

        return self::flag($value) ? __('Rescue mode cannot be turned on for this server right now.') : null;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function keys(mixed $rows): array
    {
        $keys = [];

        foreach (array_slice(is_array($rows) ? $rows : [], 0, self::LIMITS['sshkeys'], true) as $id => $row) {
            $keyId = (string) (is_array($row) && is_scalar($row['keyid'] ?? null) ? $row['keyid'] : $id);

            if (ctype_digit($keyId) && is_array($row)) {
                $keys[] = ['id' => $keyId, 'name' => self::text($row['name'] ?? null, 50) ?? '#'.$keyId];
            }
        }

        return $keys;
    }

    /**
     * hvmsettings: the ISO images, the one mounted now, the boot order and the page's other
     * settings (sent back unchanged with a change).
     *
     * @param  callable(string): mixed  $get
     * @return array{done: bool, isos: list<array{id: string, name: string}>, iso: string|null, boot: string|null, settings: array<string, string>}
     */
    private function hvm(callable $get, bool $done): array
    {
        $vps = is_array($get('vps')) ? $get('vps') : [];
        $isos = [];

        foreach (array_slice(is_array($get('isos')) ? $get('isos') : [], 0, self::LIMITS['hvmsettings'], true) as $id => $row) {
            $isoId = is_array($row) ? ($row['isoid'] ?? $row['id'] ?? $id) : $id;
            $isoId = is_scalar($isoId) ? (string) $isoId : '';
            $name = is_array($row) ? self::text($row['name'] ?? $row['filename'] ?? null, 255) : self::text($row, 255);

            if (preg_match('/^[A-Za-z0-9_.-]{1,255}\z/', $isoId) === 1 && $name !== null) {
                $isos[] = ['id' => $isoId, 'name' => $name];
            }
        }

        $settings = [];

        foreach (self::HVM_FIELDS as $field) {
            $value = $vps[$field] ?? $get($field);

            if (is_scalar($value)) {
                $settings[$field] = Str::limit((string) $value, 100, '');
            }
        }

        $iso = $vps['iso'] ?? $get('iso');

        return [
            'done' => $done,
            'isos' => $isos,
            'iso' => is_scalar($iso) && (string) $iso !== '' && (string) $iso !== '0' ? (string) $iso : null,
            'boot' => self::text($vps['boot'] ?? $get('boot_ord_val') ?? $get('boot'), 10),
            'settings' => $settings,
        ];
    }

    /**
     * listvs: the Virtualizor user's VPS as ID => user ID (null when the row has no user), with
     * the row count and whether any row had an ID that is not a number.
     *
     * @return array{vps: array<string, string|null>, count: int, invalid: bool}
     */
    private function owned(mixed $rows): array
    {
        $rows = is_array($rows) ? $rows : [];
        $vps = [];
        $invalid = false;

        foreach ($rows as $id => $row) {
            $vpsId = is_array($row) && is_scalar($row['vpsid'] ?? null) ? (string) $row['vpsid'] : (string) $id;

            if (! ctype_digit($vpsId)) {
                $invalid = true;

                continue;
            }

            $vps[$vpsId] = is_array($row) && array_key_exists('uid', $row) ? (AdminApi::userId($row['uid']) ?? '0') : null;
        }

        return ['vps' => $vps, 'count' => count($rows), 'invalid' => $invalid];
    }

    /**
     * A switch from Virtualizor: true for true, 1, "1" or any other number above 0.
     */
    private static function flag(mixed $value): bool
    {
        return $value === true || (is_numeric($value) && (float) $value > 0);
    }

    /**
     * A text from Virtualizor for the panel: one line, no tags, at most $max characters, or null.
     */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', strip_tags((string) $value)));

        return $text === '' ? null : Str::limit($text, $max, '');
    }

    /**
     * A time from Virtualizor as Unix seconds: a number, or a date it can read.
     */
    private static function time(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $time = (int) $value;

            return $time > 0 ? $time : null;
        }

        if (is_string($value) && trim($value) !== '') {
            $time = strtotime($value);

            return $time === false ? null : $time;
        }

        return null;
    }
}
