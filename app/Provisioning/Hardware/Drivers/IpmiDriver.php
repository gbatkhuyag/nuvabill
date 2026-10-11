<?php

namespace App\Provisioning\Hardware\Drivers;

use App\Enums\BmcType;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Models\Machine;
use App\Provisioning\Hardware\BmcAddress;
use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\BmcTestResult;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Power control through IPMI with the ipmitool program, for older BMCs without Redfish.
 *
 * It is offered only when staff switch it on (hardware.allow_ipmi), ipmitool is installed and PHP
 * may start programs. ipmitool runs with an argument list, never through a shell; the host is the
 * checked IP address, the port and cipher suite are numbers (cipher 17 or 3, never 0), and the
 * password goes in the environment (-E), never on the command line. ipmitool's own error output
 * is never shown or logged.
 */
final class IpmiDriver implements PowerDriver
{
    public const TIMEOUT_SECONDS = 20;

    public const DEFAULT_PORT = 623;

    /**
     * Cipher suites allowed: 17 (SHA-256, the default) or 3 (SHA-1) for older BMCs.
     *
     * @var list<int>
     */
    public const CIPHERS = [17, 3];

    public function __construct(private readonly IpmiTool $tool) {}

    public function type(): string
    {
        return BmcType::Ipmi->value;
    }

    public function label(): string
    {
        return BmcType::Ipmi->label();
    }

    public function available(): bool
    {
        return (bool) setting('hardware.allow_ipmi') && $this->tool->path() !== null && $this->tool->canRun();
    }

    public function test(Machine $machine): BmcTestResult
    {
        try {
            $state = $this->powerState($machine);
        } catch (BmcException $exception) {
            return BmcTestResult::failed($exception->getMessage());
        }

        return new BmcTestResult(
            ok: true,
            message: match ($state) {
                PowerState::On => __('Connected. The server is on.'),
                PowerState::Off => __('Connected. The server is off.'),
                default => __('Connected, but the BMC did not say whether the server is on.'),
            },
            powerState: $state,
        );
    }

    public function powerState(Machine $machine): PowerState
    {
        $output = $this->ipmitool($machine, 'status');

        if (preg_match('/^Chassis Power is (on|off)\r?$/m', $output, $match) !== 1) {
            return PowerState::Unknown;
        }

        return $match[1] === 'on' ? PowerState::On : PowerState::Off;
    }

    public function supportedActions(Machine $machine): array
    {
        if (! $machine->hasBmc() || ! $this->available()) {
            return [];
        }

        return array_values(array_filter(PowerAction::cases(), fn (PowerAction $action): bool => $action->ipmiVerb() !== null));
    }

    public function run(Machine $machine, PowerAction $action): void
    {
        $verb = $action->ipmiVerb();

        if ($verb === null || ! in_array($action, $this->supportedActions($machine), true)) {
            throw BmcException::unsupported();
        }

        $this->ipmitool($machine, $verb);
    }

    /**
     * Run "ipmitool chassis power {verb}" against the machine's BMC and return what it printed.
     *
     * @throws BmcException
     */
    private function ipmitool(Machine $machine, string $verb): string
    {
        $path = $this->tool->path();

        if (! $this->available() || $path === null) {
            throw BmcException::unsupported(__('IPMI is not available on this site. Use Redfish, or turn IPMI on under Hardware settings.'));
        }

        $address = BmcAddress::for($machine, self::DEFAULT_PORT);
        $username = (string) $machine->bmc_username;
        $password = (string) $machine->bmc_password;

        if ($username === '' || $password === '') {
            throw BmcException::refused(__('Enter the BMC user name and password first.'));
        }

        $cipher = in_array((int) $machine->bmc_ipmi_cipher, self::CIPHERS, true) ? (int) $machine->bmc_ipmi_cipher : self::CIPHERS[0];

        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)
                ->env(['IPMI_PASSWORD' => $password])
                ->run([
                    $path, '-I', 'lanplus', '-H', $address->ip, '-p', (string) $address->port, '-U', $username, '-E',
                    '-C', (string) $cipher, '-N', '5', '-R', '1', 'chassis', 'power', $verb,
                ]);
        } catch (Throwable) {
            throw BmcException::unreachable(__('The BMC did not answer in time.'));
        }

        if (! $result->successful()) {
            throw $this->failure($result->errorOutput()."\n".$result->output());
        }

        return $result->output();
    }

    /**
     * Our own message for a failed ipmitool run, from the known texts it prints.
     */
    private function failure(string $output): BmcException
    {
        return match (true) {
            preg_match('/unauthorized name|invalid user ?name|rakp 2 hmac is invalid|password|insufficient privilege|unauthorized role/i', $output) === 1 => BmcException::auth(),
            preg_match('/unable to establish|no response|timed? ?out|connection refused|address lookup|could not open socket|unable to connect|no route/i', $output) === 1 => BmcException::unreachable(),
            default => BmcException::refused(__('The BMC did not accept the command.')),
        };
    }
}
