<?php

namespace Tests\Fakes;

use App\Enums\BmcType;
use App\Enums\BootDevice;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Models\Machine;
use App\Provisioning\Hardware\BmcAddress;
use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\BmcTestResult;
use App\Provisioning\Hardware\Drivers\ChoosesBootDevice;
use App\Provisioning\Hardware\Drivers\PowerDriver;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\Drivers\ReadsHealth;
use App\Provisioning\Hardware\Drivers\ReadsPowerUsage;
use App\Provisioning\Hardware\HealthReading;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A power driver for core tests, standing in for an add-on's driver (core ships none). It serves
 * the "redfish" type and talks a small part of Redfish over Http, so tests fake the BMC with
 * Http::fake(): it reads PowerState from the machine's system path, posts the ResetType to its
 * reset target, and picks actions from the saved reset types, as the Dedicated Server Power
 * add-on does. Health and power use are set on the object.
 */
final class FakePowerDriver implements ChoosesBootDevice, PowerDriver, ReadsHealth, ReadsPowerUsage
{
    /**
     * @var list<HealthReading>
     */
    public array $readings = [];

    public ?int $watts = null;

    /**
     * Register a new one as the only power driver of the app.
     */
    public static function install(): self
    {
        app()->forgetInstance(PowerDrivers::class);
        $driver = new self;
        app(PowerDrivers::class)->register($driver);

        return $driver;
    }

    public function type(): string
    {
        return BmcType::Redfish->value;
    }

    public function label(): string
    {
        return BmcType::Redfish->label();
    }

    public function available(): bool
    {
        return true;
    }

    public function test(Machine $machine): BmcTestResult
    {
        if ($machine->bmc_tls_mode !== 'verified' && blank($machine->bmc_tls_pin)) {
            $address = BmcAddress::for($machine, 443);

            return BmcTestResult::trustFirst(app(CertificateProbe::class)->fetch($address->ip, $address->port, $address->host));
        }

        try {
            $state = $this->powerState($machine);
        } catch (BmcException $exception) {
            return BmcTestResult::failed($exception->getMessage());
        }

        return new BmcTestResult(ok: true, message: __('Connected.'), systemPath: $machine->bmc_system_path, resetTarget: $machine->bmc_reset_target,
            resetTypes: (array) $machine->bmc_reset_types, powerState: $state);
    }

    public function powerState(Machine $machine): PowerState
    {
        $value = $this->send($machine, 'GET', (string) $machine->bmc_system_path)->json('PowerState');

        return match ($value) {
            'On' => PowerState::On,
            'Off' => PowerState::Off,
            'PoweringOn', 'PoweringOff' => PowerState::Changing,
            default => PowerState::Unknown,
        };
    }

    public function supportedActions(Machine $machine): array
    {
        if (blank($machine->bmc_system_path) || blank($machine->bmc_reset_target) || ($machine->bmc_tls_mode !== 'verified' && blank($machine->bmc_tls_pin))) {
            return [];
        }

        $types = (array) $machine->bmc_reset_types;
        $offered = [
            'on' => in_array('On', $types, true),
            'shutdown' => in_array('GracefulShutdown', $types, true) || in_array('PushPowerButton', $types, true),
            'restart' => in_array('GracefulRestart', $types, true),
            'reset' => in_array('ForceRestart', $types, true),
            'off' => in_array('ForceOff', $types, true),
        ];

        return array_values(array_map(fn (string $action): PowerAction => PowerAction::from($action), array_keys(array_filter($offered))));
    }

    public function run(Machine $machine, PowerAction $action): void
    {
        if (! in_array($action, $this->supportedActions($machine), true)) {
            throw BmcException::unsupported();
        }

        $type = match ($action) {
            PowerAction::On => 'On',
            PowerAction::Shutdown => 'GracefulShutdown',
            PowerAction::Restart => 'GracefulRestart',
            PowerAction::Reset => 'ForceRestart',
            PowerAction::Off => 'ForceOff',
        };

        if (! in_array($type, (array) $machine->bmc_reset_types, true)) {
            if ($this->powerState($machine) === PowerState::Off) {
                throw BmcException::conflict(__('The server is already off.'));
            }

            $type = 'PushPowerButton';
        }

        $this->send($machine, 'POST', (string) $machine->bmc_reset_target, ['ResetType' => $type]);
    }

    public function bootDevices(Machine $machine): array
    {
        return $this->supportedActions($machine) === [] ? [] : BootDevice::cases();
    }

    public function setBootDevice(Machine $machine, BootDevice $device): void
    {
        $this->send($machine, 'PATCH', (string) $machine->bmc_system_path, ['Boot' => [
            'BootSourceOverrideEnabled' => 'Once',
            'BootSourceOverrideTarget' => match ($device) {
                BootDevice::Disk => 'Hdd',
                BootDevice::Pxe => 'Pxe',
                BootDevice::Bios => 'BiosSetup',
            },
        ]]);
    }

    public function health(Machine $machine): array
    {
        return $this->readings;
    }

    public function powerUsage(Machine $machine): ?int
    {
        return $this->watts;
    }

    /**
     * @param  array<string, mixed>|null  $body
     *
     * @throws BmcException
     */
    private function send(Machine $machine, string $method, string $path, ?array $body = null): Response
    {
        $url = BmcAddress::for($machine, 443)->baseUrl().$path;

        try {
            $request = Http::withBasicAuth((string) $machine->bmc_username, (string) $machine->bmc_password)->acceptJson()->timeout(5);
            $response = match ($method) {
                'POST' => $request->post($url, $body ?? []),
                'PATCH' => $request->patch($url, $body ?? []),
                default => $request->get($url),
            };
        } catch (Throwable) {
            throw BmcException::unreachable();
        }

        return match (true) {
            $response->successful() => $response,
            in_array($response->status(), [401, 403], true) => throw BmcException::auth(),
            $response->status() === 409 => throw BmcException::conflict(__('The BMC says this is not possible right now.')),
            default => throw BmcException::refused(__('The BMC did not accept the request.')),
        };
    }
}
