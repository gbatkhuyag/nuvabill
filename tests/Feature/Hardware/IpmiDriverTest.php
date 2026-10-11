<?php

namespace Tests\Feature\Hardware;

use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Machine;
use App\Provisioning\Hardware\Drivers\IpmiDriver;
use App\Provisioning\Hardware\Drivers\IpmiTool;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\PowerControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Power control through ipmitool: an argument list without a shell, the checked IP address, cipher
 * suite 17 or 3 only, and the password in the environment, never on the command line. ipmitool's
 * own error output is never shown.
 */
class IpmiDriverTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'bmc-test-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSettings(['hardware.allow_ipmi' => true]);
        $this->installIpmiTool('/usr/bin/ipmitool');
    }

    public function test_status_is_parsed_and_the_password_is_not_in_the_command(): void
    {
        Process::fake(['*' => Process::result("Chassis Power is on\n")]);
        $machine = Machine::factory()->ipmi('10.20.0.12')->create(['name' => 'DS-201']);
        $power = app(PowerControl::class);

        $result = $power->test($machine);

        $this->assertTrue($result->ok);
        $this->assertSame('Connected. The server is on.', $result->message);
        $this->assertSame('on', $machine->fresh()->power_state);

        Process::assertRan(function (PendingProcess $process): bool {
            $this->assertIsArray($process->command, 'An argument list, so no shell reads it.');
            $this->assertSame([
                '/usr/bin/ipmitool', '-I', 'lanplus', '-H', '10.20.0.12', '-p', '623', '-U', 'nuvabill-operator', '-E',
                '-C', '17', '-L', 'OPERATOR', '-N', '5', '-R', '1', 'chassis', 'power', 'status',
            ], $process->command);
            $this->assertNotContains(self::PASSWORD, $process->command);
            $this->assertSame(['IPMI_PASSWORD' => self::PASSWORD], $process->environment);
            $this->assertSame(IpmiDriver::TIMEOUT_SECONDS, $process->timeout);

            return true;
        });

        $this->assertSame([PowerAction::On, PowerAction::Shutdown, PowerAction::Reset, PowerAction::Off], $power->actions($machine));
        $this->assertTrue($power->run($machine, PowerAction::Shutdown, Admin::factory()->create(['name' => 'Raz']))->ok);
        Process::assertRan(fn (PendingProcess $process): bool => array_slice($process->command, -3) === ['chassis', 'power', 'soft']);
    }

    public function test_every_call_asks_for_an_operator_session_so_an_operator_user_works(): void
    {
        Process::fake(['*' => Process::result('Chassis Power is on')]);
        $machine = Machine::factory()->ipmi()->create(['name' => 'DS-204']);
        $power = app(PowerControl::class);
        $admin = Admin::factory()->create(['name' => 'Raz']);

        $power->state($machine, fresh: true);

        foreach ([PowerAction::On, PowerAction::Shutdown, PowerAction::Reset, PowerAction::Off] as $action) {
            $this->travel(PowerControl::COOLDOWN_SECONDS + 1)->seconds();
            $this->assertTrue($power->run($machine->fresh(), $action, $admin)->ok, "{$action->value} ran.");
        }

        // Without -L, ipmitool asks for an Administrator session, which the BMC refuses an Operator user.
        $operator = function (array $command): bool {
            $at = array_search('-L', $command, true);

            return $at !== false && ($command[$at + 1] ?? null) === 'OPERATOR' && ! in_array('ADMINISTRATOR', $command, true);
        };

        foreach (['status', 'on', 'soft', 'reset', 'off'] as $verb) {
            Process::assertRan(fn (PendingProcess $process): bool => end($process->command) === $verb && $operator($process->command));
        }

        Process::assertDidntRun(fn (PendingProcess $process): bool => ! $operator($process->command));
    }

    public function test_cipher_zero_is_never_used(): void
    {
        Process::fake(['*' => Process::result('Chassis Power is off')]);
        $power = app(PowerControl::class);

        foreach ([0 => '17', 3 => '3', 17 => '17', 99 => '17'] as $saved => $used) {
            $machine = Machine::factory()->ipmi()->create(['name' => "DS-3{$saved}"]);
            $machine->forceFill(['bmc_ipmi_cipher' => $saved])->save();

            $this->assertSame(PowerState::Off, $power->state($machine, fresh: true));
            Process::assertRan(fn (PendingProcess $process): bool => $process->command[11] === $used && $process->command[10] === '-C');
        }

        Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('0', $process->command, true));
    }

    public function test_unknown_output_is_unknown_state(): void
    {
        $machine = Machine::factory()->ipmi()->create(['name' => 'DS-202']);
        $power = app(PowerControl::class);

        Process::fake(['*' => Process::result('Chassis Power Control: Up/On')]);
        $this->assertSame(PowerState::Unknown, $power->state($machine, fresh: true));

        // ipmitool's own words are never shown or logged.
        $stderr = "Error: Unable to establish IPMI v2 / RMCP+ session\nRAKP 2 message indicates an error : unauthorized name ".self::PASSWORD;
        Process::fake(['*' => Process::result('', $stderr, 1)]);
        $result = $power->test($machine);
        $this->assertFalse($result->ok);
        $this->assertSame('The BMC refused the user name or password.', $result->message);

        Process::fake(['*' => Process::result('', 'Error: Unable to establish IPMI v2 / RMCP+ session', 1)]);
        $this->assertSame('We could not reach the BMC. Check its address and that this site can reach it.', $power->test($machine)->message);

        Process::fake(['*' => Process::result('', 'Something odd', 1)]);
        $this->assertSame('The BMC did not accept the command.', $power->test($machine)->message);

        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%RAKP%')->orWhere('description', 'like', '%'.self::PASSWORD.'%')->exists());
        $this->assertStringNotContainsString('RAKP', (string) $machine->fresh()->bmc_last_error);
    }

    public function test_ipmi_is_only_offered_when_switched_on_and_ipmitool_exists(): void
    {
        Process::fake();
        $machine = Machine::factory()->ipmi()->create(['name' => 'DS-203']);

        $this->assertArrayHasKey('ipmi', app(PowerDrivers::class)->available());

        $this->setSettings(['hardware.allow_ipmi' => false]);
        $this->assertArrayNotHasKey('ipmi', app(PowerDrivers::class)->available());
        $this->assertSame([], app(PowerControl::class)->actions($machine));
        $this->assertFalse(app(PowerControl::class)->run($machine, PowerAction::Off, Admin::factory()->create(['name' => 'Raz']))->ok);

        $this->setSettings(['hardware.allow_ipmi' => true]);
        $this->installIpmiTool(null);
        $this->assertArrayNotHasKey('ipmi', app(PowerDrivers::class)->available());
        $this->assertSame('This power control type is not available on this site.', app(PowerControl::class)->test($machine)->message);

        Process::assertNothingRan();
    }

    /**
     * Pretend ipmitool is installed at this path (or not at all).
     */
    private function installIpmiTool(?string $path): void
    {
        $this->app->instance(IpmiTool::class, new class($path) extends IpmiTool
        {
            public function __construct(private readonly ?string $fakePath) {}

            public function path(): ?string
            {
                return $this->fakePath;
            }

            public function canRun(): bool
            {
                return true;
            }
        });
        $this->app->forgetInstance(PowerDrivers::class);
    }
}
