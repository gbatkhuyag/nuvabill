<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Extensions\Servers\Panel\ClientContext;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * A fake Virtualizor master for the Virtualizor 2.0 tests: the Admin API on port 4085 and the
 * end-user panel on port 4083, answering by the kind of request (the act, and for the Admin API's
 * "vs" act what it asks for). Each answer is an array, a response, or a closure given the query and
 * the posted data. Every request is kept in $api->calls as kind, port, query, data, method and URL.
 *
 * The default VPS is #77 of Virtualizor user 58, whose email is mer.las@example.test (CLIENT_EMAIL);
 * a test that needs the client to have that email gives it to the client.
 */
trait FakesVirtualizor
{
    protected const API_PASSWORD = 'S3cretApiPass99';

    protected const API_KEY = 'TESTTOKEN123';

    protected const VPS_UUID = '3f1c2a9e-77aa-4b1c-9d2e-0123456789ab';

    protected const CLIENT_EMAIL = 'mer.las@example.test';

    protected ?stdClass $api = null;

    /**
     * Fake the master with these answers on top of the defaults. A second call swaps the answers
     * and clears the list of calls.
     *
     * @param  array<string, mixed>  $answers
     */
    protected function fakeVirtualizor(array $answers = []): stdClass
    {
        $answers += [
            'vps' => fn (array $query): array => ['vs' => [$query['vpsid'] => $this->vpsRow((string) $query['vpsid'])]],
            'status' => fn (array $query): array => ['status' => [$query['vs_status'] => ['status' => 1, 'used_cpu' => 3.5, 'used_ram' => 512, 'ram' => 2048]]],
            'users' => fn (array $query, array $data): array => isset($query['uid'])
                ? ['users' => [$query['uid'] => ['uid' => $query['uid'], 'email' => self::CLIENT_EMAIL, 'type' => '0']]]
                : ['users' => []],
            'ostemplates' => ['ostemplates' => ['100' => ['osid' => 100, 'type' => 'kvm', 'name' => 'Ubuntu 24.04']]],
            'vnc' => ['info' => ['ip' => '198.51.100.2', 'port' => 5901, 'password' => 'Vnc7pass']],
            'vpsmanage' => $this->vpsmanage(),
            'listvs' => fn (array $query): array => ['vs' => [$query['svs'] => ['vpsid' => $query['svs'], 'uid' => '58']]],
        ];

        if ($this->api === null) {
            $this->api = new stdClass;

            Http::preventStrayRequests();
            Http::fake(function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $kind = $this->kindOf($query);
                $this->api->calls[] = [
                    'kind' => $kind,
                    'port' => (int) parse_url($request->url(), PHP_URL_PORT),
                    'query' => $query,
                    'data' => $request->data(),
                    'method' => $request->method(),
                    'url' => $request->url(),
                ];
                $answer = $this->api->answers[$kind] ?? ['error' => ['Unexpected request: '.$kind]];
                $answer = $answer instanceof Closure ? $answer($query, $request->method() === 'POST' ? $request->data() : []) : $answer;

                return is_array($answer) ? Http::response($answer) : $answer;
            });
        }

        $this->api->answers = $answers;
        $this->api->calls = [];

        return $this->api;
    }

    /**
     * An end-user vpsmanage answer for the pinned VPS, with every panel feature on unless the
     * flags or VPS values say otherwise. It holds the VPS's passwords, as Virtualizor's does.
     *
     * @param  array<string, mixed>  $flags
     * @param  array<string, mixed>  $vps
     */
    protected function vpsmanage(array $flags = [], array $vps = []): Closure
    {
        return fn (array $query): array => ['title' => 'VPS Management', 'info' => [
            'status' => 1,
            'virt' => 'kvm',
            'hostname' => 'vps1.example.com',
            'os' => ['name' => 'Ubuntu 24.04'],
            'vps' => $vps + [
                'vpsid' => $query['svs'],
                'uuid' => self::VPS_UUID,
                'vnc' => '1',
                'vncpass' => 'Vnc7pass',
                'suspended' => '0',
                'rescue' => '0',
                'admin_managed' => '0',
            ],
            'flags' => $flags + [
                'enable_rdns' => 1,
                'rescue_mode' => 1,
                'bpid' => 3,
                'hvmsettings' => 1,
                'vm_pass' => 'RootSecret99',
                'vm_username' => 'root',
            ],
        ]];
    }

    /**
     * A row of the Admin API's list of VPS.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function vpsRow(string $id, array $values = []): array
    {
        return $values + [
            'vpsid' => $id,
            'uid' => '58',
            'uuid' => self::VPS_UUID,
            'suspended' => '0',
            'hostname' => 'vps1.example.com',
            'os_name' => 'Ubuntu 24.04',
            'virt' => 'kvm',
            'cores' => '2',
            'ram' => '2048',
            'space' => '40',
            'ips' => ['2' => '203.0.113.7'],
            'ips6' => ['3' => '2001:db8:7::1'],
            'ips6_subnet' => ['4' => '2001:db8:77::/64'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function kindOf(array $query): string
    {
        $act = (string) ($query['act'] ?? '');

        return match (true) {
            $act !== 'vs' => $act,
            isset($query['vs_status']) => 'status',
            isset($query['action']) => 'power',
            isset($query['vpshostname']) => 'byname',
            isset($query['vpsid']) => 'vps',
            default => 'list',
        };
    }

    /**
     * The requests of one kind the fake got, in order.
     *
     * @return list<array<string, mixed>>
     */
    protected function sent(string $kind): array
    {
        return array_values(array_filter($this->api->calls ?? [], fn (array $call): bool => $call['kind'] === $kind));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function client(string $name = 'Mer Las', array $attributes = []): Client
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        return Client::factory()->create($attributes + ['first_name' => $first, 'last_name' => $last, 'company_name' => null]);
    }

    /**
     * A Virtualizor server record of the master vz.example.test.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function master(array $attributes = []): Server
    {
        return Server::factory()->create($attributes + [
            'module' => 'virtualizor',
            'hostname' => 'vz.example.test',
            'port' => 4085,
            'use_ssl' => true,
            'api_token' => self::API_KEY,
            'password' => self::API_PASSWORD,
        ]);
    }

    /**
     * An active Virtualizor service with VPS 77, by default of a new client Mer Las on a new
     * server record of the master.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $attributes
     */
    protected function vpsService(array $config = [], array $attributes = [], ?Server $server = null, ?Client $client = null): Service
    {
        $server ??= $this->master();
        $client ??= $this->client('Mer Las');
        $product = Product::factory()->create(['server_module' => 'virtualizor', 'server_id' => $server->id, 'module_config' => $config + ['virt' => 'kvm', 'os_id' => '100']]);

        return Service::factory()->create($attributes + [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'status' => ServiceStatus::Active,
            'domain' => 'vps1.example.com',
            'username' => 'root',
            'module_data' => ['vpsid' => '77', 'uid' => '58'],
        ]);
    }

    /**
     * The context of the service's client on a trusted IP, or the one given.
     */
    protected function context(Service $service, string $ip = '198.51.100.20', bool $trusted = true): ClientContext
    {
        return new ClientContext((int) $service->client_id, $ip, $trusted, route('client.services.show', $service), 'en');
    }
}
