<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Virtualizor's Admin API (port 4085 by default), used for staff tasks: create, suspend, power,
 * reinstall and reads of a VPS. Every part of this extension calls the Admin API through here, so
 * the key and password never reach a message, a log or the browser.
 *
 * call(), answer(), clean() and baseUrl() are the module's request code from 1.1.0, unchanged. The
 * reads below them (one VPS, its live figures, the OS templates) and the small static helpers are
 * shared by the module and its other helpers.
 */
final class AdminApi
{
    /**
     * Exception code: Virtualizor answered and refused the request, so it did nothing. Any other
     * failure (no connection, no clear answer) leaves it open whether the request ran.
     */
    public const REFUSED = 1;

    /**
     * Virtualizor's Admin API port with SSL.
     */
    public const DEFAULT_PORT = 4085;

    /**
     * One Admin API call. The key and password go in the query string, as Virtualizor's own SDK
     * sends them, so they never go into a message: a failed connection names only the host, and
     * an error from Virtualizor is cleaned of them. Plain HTTP only when the server has SSL off.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    public function call(?Server $server, array $query, array $post = [], int $timeout = 30): array
    {
        if ($server === null) {
            throw new RuntimeException(__('No server is assigned.'));
        }

        $url = $this->baseUrl($server, (int) ($server->port ?: self::DEFAULT_PORT), $server->use_ssl !== false).'/index.php';
        $query += ['api' => 'json', 'adminapikey' => (string) $server->api_token, 'adminapipass' => (string) $server->password];

        try {
            $request = Http::timeout($timeout)->acceptJson()->asForm()->withoutRedirecting();
            $response = $post === [] ? $request->get($url, $query) : $request->post($url.'?'.http_build_query($query), $post);
        } catch (Throwable) {
            throw new RuntimeException(__('Could not connect to :host.', ['host' => $server->hostname]));
        }

        return $this->answer($server, $response->json(), $response->status());
    }

    /**
     * Virtualizor's answer as an array. An error from Virtualizor throws with the REFUSED code and
     * a message cleaned of the key and password.
     *
     * @return array<string, mixed>
     */
    public function answer(Server $server, mixed $data, int $status): array
    {
        if (! is_array($data)) {
            throw new RuntimeException(__('Virtualizor returned HTTP :status. Check the API key, password and allowed IPs.', ['status' => $status]));
        }

        if (! empty($data['error'])) {
            $errors = array_filter(Arr::flatten((array) $data['error']), fn (mixed $error): bool => is_scalar($error));

            throw new RuntimeException('Virtualizor: '.$this->clean($server, implode(' ', array_map('strval', $errors))), self::REFUSED);
        }

        return $data;
    }

    /**
     * A message from Virtualizor, without the API key or password, tags or line breaks.
     */
    public function clean(Server $server, string $message): string
    {
        foreach (array_filter([(string) $server->api_token, (string) $server->password], fn (string $secret): bool => strlen($secret) >= 4) as $secret) {
            $message = str_ireplace([$secret, rawurlencode($secret), urlencode($secret)], '[hidden]', $message);
        }

        $message = (string) preg_replace('/\b(adminapikey|adminapipass|apikey|apipass)=[^&\s]*/i', '$1=[hidden]', $message);

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($message))), 300);
    }

    /**
     * The address of the server's API or end-user panel, from the server's own hostname only.
     */
    public function baseUrl(Server $server, int $port, bool $https): string
    {
        $host = strtolower(trim((string) $server->hostname));
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! $ip && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new RuntimeException(__('Check the hostname of the Virtualizor server.'));
        }

        return ($https ? 'https' : 'http').'://'.($ip && str_contains($host, ':') ? '['.$host.']' : $host).':'.$port;
    }

    /**
     * One VPS from Virtualizor's list, found by its exact ID, or an empty array when it is not
     * listed. Virtualizor's search by ID also lists IDs that contain it (177 for 77), so up to 50
     * rows are read and only the row with the exact ID is used.
     *
     * @return array<string, mixed>
     */
    public function vpsInfo(?Server $server, string $vpsId): array
    {
        $info = $this->call($server, ['act' => 'vs', 'search' => 1, 'vpsid' => $vpsId, 'page' => 1, 'reslen' => 50])['vs'][$vpsId] ?? null;

        return is_array($info) && (string) ($info['vpsid'] ?? $vpsId) === $vpsId ? $info : [];
    }

    /**
     * The live figures of one VPS (status, CPU, RAM, disk, bandwidth and network), or an empty
     * array when Virtualizor gave none.
     *
     * @return array<string, mixed>
     */
    public function vpsStatus(?Server $server, string $vpsId): array
    {
        return (array) ($this->call($server, ['act' => 'vs', 'vs_status' => $vpsId])['status'][$vpsId] ?? []);
    }

    /**
     * OS templates for the virtualization type, as ID => name, sorted by name. Kept for an hour.
     *
     * @return array<string, string>
     */
    public function templates(Server $server, string $virt): array
    {
        return Cache::remember('nuvabill.virtualizor.templates.'.$server->id.'.'.$virt, 3600, function () use ($server, $virt): array {
            $templates = [];

            foreach ((array) ($this->call($server, ['act' => 'ostemplates'])['ostemplates'] ?? []) as $id => $template) {
                $template = (array) $template;

                if (($template['type'] ?? $virt) === $virt) {
                    $templates[(string) ($template['osid'] ?? $id)] = (string) ($template['name'] ?? $template['filename'] ?? $id);
                }
            }

            asort($templates);

            return $templates;
        });
    }

    /**
     * The service's VPS ID.
     *
     * @throws RuntimeException When the service has no VPS yet.
     */
    public static function vpsId(Service $service): string
    {
        $id = (string) ($service->module_data['vpsid'] ?? '');

        if (! ctype_digit($id)) {
            throw new RuntimeException(__('This service has no Virtualizor VPS ID yet.'));
        }

        return $id;
    }

    /**
     * The VPS's IP addresses, IPv4 first, or only one kind with a FILTER_FLAG_IPV4/IPV6 flag.
     *
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    public static function addresses(array $info, int $flag = 0): array
    {
        $all = array_filter([...array_values((array) ($info['ips'] ?? [])), ...array_values((array) ($info['ips6'] ?? []))], 'is_string');
        $v4 = array_filter($all, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
        $v6 = array_filter($all, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);

        return array_values(array_unique(match ($flag) {
            FILTER_FLAG_IPV4 => $v4,
            FILTER_FLAG_IPV6 => $v6,
            default => [...$v4, ...$v6],
        }));
    }

    /**
     * The panel state for Virtualizor's status number: running, stopped, suspended or unknown.
     */
    public static function state(mixed $status): string
    {
        return match (is_numeric($status) ? (int) $status : null) {
            1 => 'running',
            0 => 'stopped',
            2 => 'suspended',
            default => 'unknown',
        };
    }

    /**
     * A Virtualizor user ID, or null when the value is not one.
     */
    public static function userId(mixed $value): ?string
    {
        return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $value) ? (string) $value : null;
    }
}
