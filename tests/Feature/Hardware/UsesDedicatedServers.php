<?php

namespace Tests\Feature\Hardware;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Hardware\Tls\PinningSupport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePowerDriver;

/**
 * Shared setup of the dedicated server module tests: the client "Mer Las", a product sold from the
 * hardware inventory with the built-in "dedicated" module, and fake Redfish BMCs reached through
 * the test power driver (core ships none; see FakePowerDriver). Each fake BMC answers by host
 * with the power state set in $bmcStates (a host that is not listed does not answer), and a power
 * action changes that state the way a real server would.
 */
trait UsesDedicatedServers
{
    protected const SYSTEM = '/redfish/v1/Systems/System.Embedded.1';

    protected const RESET = '/redfish/v1/Systems/System.Embedded.1/Actions/ComputerSystem.Reset';

    /**
     * The power state each fake BMC reports (On or Off), by host.
     *
     * @var array<string, string>
     */
    protected array $bmcStates = [];

    protected Client $client;

    protected Product $product;

    /**
     * @param  array<string, string>  $config  The product's module settings.
     */
    protected function setUpDedicated(array $config = []): void
    {
        Mail::fake();
        $this->setSettings(['company.email' => 'staff@example.test', 'company.name' => 'RapidNet']);
        $this->app->instance(PinningSupport::class, new PinningSupport(fn (string $function): bool => true, ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']));
        FakePowerDriver::install();

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (! isset($this->bmcStates[$host])) {
                return Http::failedConnection()($request);
            }

            if ($request->method() === 'GET' && $path === self::SYSTEM) {
                return Http::response(['PowerState' => $this->bmcStates[$host]]);
            }

            if ($request->method() === 'POST' && $path === self::RESET) {
                $this->bmcStates[$host] = match ($request->data()['ResetType'] ?? null) {
                    'On', 'ForceRestart', 'GracefulRestart' => 'On',
                    'ForceOff', 'GracefulShutdown' => 'Off',
                    default => $this->bmcStates[$host],
                };

                return Http::response(null, 204);
            }

            // The next boot device.
            if ($request->method() === 'PATCH' && $path === self::SYSTEM) {
                return Http::response(null, 204);
            }

            return Http::response(['error' => ['message' => 'Not found']], 404);
        });

        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer.las@example.test', 'company_name' => null, 'currency' => 'USD']);
        $this->product = $this->dedicatedProduct($config);
    }

    /**
     * @param  array<string, string>  $config
     */
    protected function dedicatedProduct(array $config = [], string $name = 'Dedicated E-2388G'): Product
    {
        return Product::factory()->priced(9900, BillingCycle::Monthly)->withoutDomain()->create([
            'name' => $name,
            'server_module' => 'dedicated',
            'uses_machines' => true,
            'stock' => null,
            'module_config' => $config + ['login_name' => 'root', 'default_os' => 'Debian 12'],
        ]);
    }

    /**
     * A service of the client on the dedicated product.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function dedicatedService(ServiceStatus $status = ServiceStatus::Active, array $attributes = [], ?Product $product = null): Service
    {
        return Service::factory()->create($attributes + [
            'client_id' => $this->client->id,
            'product_id' => ($product ?? $this->product)->id,
            'server_id' => null,
            'status' => $status,
            'domain' => 'ds1.example.test',
            'username' => 'root',
        ]);
    }

    /**
     * A Redfish machine in use by the service, sold as the product, with its addresses. Its fake
     * BMC reports $state, or does not answer when it is null.
     *
     * @param  list<string>  $extras  Free extra IPv4 addresses on the machine.
     * @param  list<string>  $included  Addresses that come with every plan.
     */
    protected function machineFor(?Service $service, ?string $state = 'On', string $host = '10.20.0.11', array $extras = [], string $name = 'DS-101', string $primary = '203.0.113.10', array $included = ['203.0.113.20']): Machine
    {
        if ($state !== null) {
            $this->bmcStates[$host] = $state;
        }

        $factory = Machine::factory()->redfish($host)->linkedTo($this->product)->withIps($primary, $extras, $included);
        $machine = ($service !== null ? $factory->activeFor($service) : $factory)->create(['name' => $name, 'rack' => 'A1', 'rack_unit' => 'U7', 'notes' => 'Spare PSU in the box']);
        $machine->ips()->where('address', $primary)->update(['prefix' => 29, 'gateway' => '203.0.113.9']);

        return $machine;
    }

    /**
     * Emails sent to an address with the subject.
     *
     * @return list<TemplatedMessage>
     */
    protected function mailsTo(string $email, string $subject): array
    {
        return Mail::sent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($email) && $mail->subjectLine === $subject)->values()->all();
    }
}
