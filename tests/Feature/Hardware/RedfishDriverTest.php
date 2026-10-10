<?php

namespace Tests\Feature\Hardware;

use App\Enums\MachineStatus;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Machine;
use App\Provisioning\Hardware\BmcAddress;
use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\BmcTestResult;
use App\Provisioning\Hardware\Drivers\PowerDriver;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\Drivers\RedfishDriver;
use App\Provisioning\Hardware\PowerControl;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PeerCertificate;
use App\Provisioning\Hardware\Tls\PinnedCurlFactory;
use App\Provisioning\Hardware\Tls\PinningSupport;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fakes\FakeCertificateProbe;
use Tests\TestCase;
use Throwable;

/**
 * Power control through Redfish: nothing is sent before the BMC's certificate is trusted, the TLS
 * and DNS pins are always set, only /redfish/v1 paths on the configured BMC are called, redirects
 * are never followed, and the BMC login never shows up in a message or log.
 */
class RedfishDriverTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'bmc-test-password';

    private const SYSTEM = '/redfish/v1/Systems/System.Embedded.1';

    private const RESET = '/redfish/v1/Systems/System.Embedded.1/Actions/ComputerSystem.Reset';

    /**
     * Every request the fake BMC got, with its Guzzle options.
     *
     * @var list<array{request: Request, options: array<string, mixed>}>
     */
    private array $sent = [];

    /**
     * What the fake BMC answers, by "METHOD /path" or "/path".
     *
     * @var array<string, mixed>
     */
    private array $routes = [];

    private FakeCertificateProbe $probe;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake(fn (Request $request, array $options): mixed => $this->answer($request, $options));
        $this->probe = new FakeCertificateProbe(FakeCertificateProbe::certificate('A'));
        $this->app->instance(CertificateProbe::class, $this->probe);
        $this->app->instance(PinningSupport::class, new PinningSupport(fn (string $function): bool => true, ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']));
        $this->app->forgetInstance(PowerDrivers::class);
        $this->admin = Admin::factory()->create(['name' => 'Raz']);
    }

    protected function tearDown(): void
    {
        BmcAddress::lookupUsing(null);

        parent::tearDown();
    }

    public function test_no_credentials_are_sent_before_the_certificate_is_trusted(): void
    {
        $machine = $this->machine(['bmc_tls_pin' => null, 'bmc_tls_fingerprint' => null]);
        $power = app(PowerControl::class);

        $result = $power->test($machine);

        $this->assertTrue($result->needsTrust);
        $this->assertFalse($result->ok);
        $this->assertSame(FakeCertificateProbe::certificate('A')->fingerprint, $result->certificate?->fingerprint);
        $this->assertSame([['10.20.0.11', 443, '10.20.0.11']], $this->probe->calls);

        // Nothing else reaches the BMC either: no state, no actions.
        $this->assertSame(PowerState::Unknown, $power->state($machine, fresh: true));
        $this->assertSame([], $power->actions($machine));
        $this->assertFalse($power->run($machine, PowerAction::On, $this->admin)->ok);

        Http::assertNothingSent();
        $this->assertNull($machine->fresh()->bmc_tls_pin);
    }

    public function test_trusting_saves_the_pin_only_when_the_fingerprint_matches(): void
    {
        $machine = $this->machine(['bmc_tls_pin' => null, 'bmc_tls_fingerprint' => null]);
        $power = app(PowerControl::class);
        $seen = FakeCertificateProbe::certificate('A');

        $this->assertTrustRefused(fn () => $power->trust($machine, FakeCertificateProbe::certificate('B')->fingerprint, null, $this->admin), 'another certificate');
        $this->assertTrustRefused(fn () => $power->trust($machine, $seen->fingerprint, FakeCertificateProbe::certificate('C')->fingerprint, $this->admin), 'does not match');
        $this->assertTrustRefused(fn () => $power->trust($machine, $seen->fingerprint, 'not a fingerprint', $this->admin), 'not a SHA-256');

        // The BMC shows another certificate by the time staff press Trust.
        $this->probe->certificate = FakeCertificateProbe::certificate('B');
        $this->assertTrustRefused(fn () => $power->trust($machine, $seen->fingerprint, null, $this->admin), 'another certificate');
        $this->assertNull($machine->fresh()->bmc_tls_pin);

        // Upper or lower case, with or without colons, as the BMC page shows it.
        $this->probe->certificate = $seen;
        $power->trust($machine, $seen->fingerprint, strtolower(str_replace(':', '', $seen->fingerprint)), $this->admin);

        $machine->refresh();
        $this->assertSame($seen->pin, $machine->bmc_tls_pin);
        $this->assertSame($seen->fingerprint, $machine->bmc_tls_fingerprint);
        $log = ActivityLog::query()->where('action', 'machine.tls_trusted')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertStringContainsString(substr($seen->fingerprint, 0, 23), $log->description);
    }

    public function test_test_connection_discovers_the_system_reset_target_and_types(): void
    {
        $this->fakeBmc([
            '/redfish/v1/' => Http::response(['RedfishVersion' => '1.17.0', 'Systems' => ['@odata.id' => '/redfish/v1/Systems']]),
            '/redfish/v1/Systems' => Http::response(['Members' => [['@odata.id' => self::SYSTEM]], 'Members@odata.count' => 1]),
            self::SYSTEM => Http::response([
                'Manufacturer' => 'Dell Inc.',
                'Model' => 'PowerEdge R650',
                'PowerState' => 'On',
                'Actions' => ['#ComputerSystem.Reset' => [
                    'target' => self::RESET,
                    'ResetType@Redfish.AllowableValues' => ['On', 'ForceOff', 'ForceRestart', 'GracefulRestart', 'GracefulShutdown', 'PushPowerButton', 'Nmi', 'PowerCycle', '<script>'],
                ]],
            ]),
        ]);
        $machine = $this->machine(['bmc_system_path' => null, 'bmc_reset_target' => null, 'bmc_reset_types' => null, 'bmc_vendor' => null, 'bmc_model' => null]);

        $result = app(PowerControl::class)->test($machine);

        $this->assertTrue($result->ok, $result->message);
        $this->assertSame('Connected: Dell Inc. PowerEdge R650.', $result->message);
        $machine->refresh();
        $this->assertSame(self::SYSTEM, $machine->bmc_system_path);
        $this->assertSame(self::RESET, $machine->bmc_reset_target);
        $this->assertSame(['On', 'ForceOff', 'GracefulShutdown', 'GracefulRestart', 'ForceRestart', 'Nmi', 'PushPowerButton', 'PowerCycle'], $machine->bmc_reset_types);
        $this->assertSame('Dell Inc.', $machine->bmc_vendor);
        $this->assertSame('on', $machine->power_state);
        $this->assertSame([PowerAction::On, PowerAction::Shutdown, PowerAction::Restart, PowerAction::Reset, PowerAction::Off], app(PowerControl::class)->actions($machine));

        $this->assertCount(3, $this->sent);
        $this->assertFalse($this->sent[0]['request']->hasHeader('Authorization'), 'The service root is read without the login.');

        foreach ($this->sent as $index => ['request' => $request, 'options' => $options]) {
            $this->assertSame('10.20.0.11', parse_url($request->url(), PHP_URL_HOST));
            $this->assertSame('https', parse_url($request->url(), PHP_URL_SCHEME));
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['verify']);
            $this->assertSame('sha256//'.$machine->bmc_tls_pin, $options[PinnedCurlFactory::PIN_OPTION]);
            $this->assertSame(RedfishDriver::MAX_BODY, $options[PinnedCurlFactory::MAX_BODY_OPTION]);
            $this->assertArrayNotHasKey('curl', $options, 'An IP address needs no DNS pin.');
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);

            if ($index > 0) {
                $this->assertSame(['Basic '.base64_encode('nuvabill-operator:'.self::PASSWORD)], $request->header('Authorization'));
            }

            // The real curl handler takes these options as they are (Guzzle refuses some curl options).
            $factory = new PinnedCurlFactory;
            $factory->release($factory->create($request->toPsrRequest(), $options));
        }

        $log = ActivityLog::query()->where('action', 'machine.bmc_tested')->sole();
        $this->assertStringContainsString('connected (Dell Inc. PowerEdge R650)', $log->description);
    }

    public function test_reset_types_from_action_info_are_read(): void
    {
        $this->fakeBmc([
            '/redfish/v1/' => Http::response(['Systems' => ['@odata.id' => '/redfish/v1/Systems']]),
            '/redfish/v1/Systems' => Http::response(['Members' => [['@odata.id' => '/redfish/v1/Systems/1']]]),
            '/redfish/v1/Systems/1' => Http::response([
                'Manufacturer' => 'Supermicro',
                'Model' => 'X12STH-F',
                'PowerState' => 'Off',
                'Actions' => ['#ComputerSystem.Reset' => [
                    'target' => '/redfish/v1/Systems/1/Actions/ComputerSystem.Reset',
                    '@Redfish.ActionInfo' => '/redfish/v1/Systems/1/ResetActionInfo',
                ]],
            ]),
            '/redfish/v1/Systems/1/ResetActionInfo' => Http::response(['Parameters' => [
                ['Name' => 'ResetType', 'Required' => true, 'AllowableValues' => ['On', 'ForceOff', 'GracefulShutdown', 'ForceRestart', 'Nmi', 'ForceOn']],
            ]]),
        ]);
        $machine = $this->machine(['bmc_system_path' => null, 'bmc_reset_target' => null, 'bmc_reset_types' => null]);

        $result = app(PowerControl::class)->test($machine);

        $this->assertTrue($result->ok, $result->message);
        $machine->refresh();
        $this->assertSame('/redfish/v1/Systems/1/Actions/ComputerSystem.Reset', $machine->bmc_reset_target);
        $this->assertSame(['On', 'ForceOff', 'GracefulShutdown', 'ForceRestart', 'Nmi', 'ForceOn'], $machine->bmc_reset_types);
        $this->assertSame('off', $machine->power_state);
        // No GracefulRestart on this board, so no "Restart" button.
        $this->assertSame([PowerAction::On, PowerAction::Shutdown, PowerAction::Reset, PowerAction::Off], app(PowerControl::class)->actions($machine));
    }

    public function test_several_systems_need_a_staff_choice_before_power_actions(): void
    {
        $this->fakeBmc([
            '/redfish/v1/' => Http::response(['Systems' => ['@odata.id' => '/redfish/v1/Systems']]),
            '/redfish/v1/Systems' => Http::response(['Members' => [['@odata.id' => '/redfish/v1/Systems/Node1'], ['@odata.id' => '/redfish/v1/Systems/Node2']]]),
            '/redfish/v1/Systems/Node2' => Http::response(['PowerState' => 'On', 'Actions' => ['#ComputerSystem.Reset' => [
                'target' => '/redfish/v1/Systems/Node2/Actions/ComputerSystem.Reset',
                'ResetType@Redfish.AllowableValues' => ['On', 'ForceOff'],
            ]]]),
        ]);
        $machine = $this->machine();
        $power = app(PowerControl::class);

        $result = $power->test($machine);

        $this->assertTrue($result->needsSystemChoice());
        $this->assertSame(['/redfish/v1/Systems/Node1', '/redfish/v1/Systems/Node2'], $result->systems);
        $machine->refresh();
        $this->assertNull($machine->bmc_system_path);
        $this->assertNull($machine->bmc_reset_target);
        $this->assertSame([], $power->actions($machine));
        $this->assertSame('Not supported by this server.', $power->run($machine, PowerAction::Off, $this->admin)->message);

        // Staff picked a system from the list: the next test uses it.
        $machine->forceFill(['bmc_system_path' => '/redfish/v1/Systems/Node2'])->save();
        $this->assertTrue($power->test($machine)->ok);
        $this->assertSame('/redfish/v1/Systems/Node2/Actions/ComputerSystem.Reset', $machine->fresh()->bmc_reset_target);
        $this->assertSame([PowerAction::On, PowerAction::Off], $power->actions($machine->fresh()));
    }

    public function test_power_on_posts_the_reset_type_with_basic_auth(): void
    {
        $this->fakeBmc(['POST '.self::RESET => Http::response(null, 204)]);
        $machine = $this->machine();

        $result = app(PowerControl::class)->run($machine, PowerAction::On, $this->admin);

        $this->assertTrue($result->ok);
        $this->assertSame('The server is starting.', $result->message);
        $this->assertSame(PowerAction::On, $result->pending);
        $this->assertCount(1, $this->sent);
        ['request' => $request, 'options' => $options] = $this->sent[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame(self::RESET, parse_url($request->url(), PHP_URL_PATH));
        $this->assertSame(['ResetType' => 'On'], $request->data());
        $this->assertSame(['Basic '.base64_encode('nuvabill-operator:'.self::PASSWORD)], $request->header('Authorization'));
        $this->assertSame(20, $options['timeout']);

        $log = ActivityLog::query()->where('action', 'machine.power')->sole();
        $this->assertSame('admin', $log->actor_type);
        $this->assertStringContainsString('Staff power action "on"', $log->description);

        // A second action within the cool-down is refused without a request.
        $this->assertTrue(app(PowerControl::class)->run($machine, PowerAction::Off, $this->admin)->busy);
        $this->assertCount(1, $this->sent);
    }

    public function test_redirects_are_not_followed(): void
    {
        $this->fakeBmc(['/redfish/v1/' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);
        $machine = $this->machine();

        $result = app(PowerControl::class)->test($machine);

        $this->assertFalse($result->ok);
        $this->assertSame('The BMC tried to send us elsewhere. Check its address.', $result->message);
        $this->assertCount(1, $this->sent);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254'));
    }

    public function test_absolute_or_foreign_odata_paths_are_refused(): void
    {
        $machine = $this->machine();
        $power = app(PowerControl::class);

        $this->fakeBmc(['/redfish/v1/' => Http::response(['Systems' => ['@odata.id' => 'http://169.254.169.254/redfish/v1/Systems']])]);
        $this->assertSame('The BMC does not list its systems.', $power->test($machine)->message);

        $this->fakeBmc([
            '/redfish/v1/' => Http::response(['Systems' => ['@odata.id' => '/redfish/v1/Systems']]),
            '/redfish/v1/Systems' => Http::response(['Members' => [
                ['@odata.id' => 'https://evil.example.test/redfish/v1/Systems/1'],
                ['@odata.id' => '/redfish/v1/Systems/../../../etc/passwd'],
                ['@odata.id' => '/redfish/v1/Systems/%2e%2e/x'],
                ['@odata.id' => '//evil.example.test/redfish/v1/Systems/1'],
            ]]),
        ]);
        $this->assertSame('The BMC lists no systems.', $power->test($machine)->message);

        $this->fakeBmc([
            '/redfish/v1/' => Http::response(['Systems' => ['@odata.id' => '/redfish/v1/Systems']]),
            '/redfish/v1/Systems' => Http::response(['Members' => [['@odata.id' => self::SYSTEM]]]),
            self::SYSTEM => Http::response(['Actions' => ['#ComputerSystem.Reset' => ['target' => 'https://evil.example.test/reset']]]),
        ]);
        $this->assertSame('The BMC sent a link we do not follow.', $power->test($machine)->message);

        foreach ($this->sent as ['request' => $request]) {
            $this->assertSame('10.20.0.11', parse_url($request->url(), PHP_URL_HOST));
            $this->assertStringStartsWith('/redfish/v1', (string) parse_url($request->url(), PHP_URL_PATH));
        }
    }

    public function test_already_on_conflict_gives_a_friendly_message(): void
    {
        $this->fakeBmc(['POST '.self::RESET => Http::response(['error' => ['@Message.ExtendedInfo' => [['Message' => 'Server is already powered ON.']]]], 409)]);
        $machine = $this->machine();

        $result = app(PowerControl::class)->run($machine, PowerAction::On, $this->admin);

        $this->assertTrue($result->ok);
        $this->assertSame('The server is already on.', $result->message);
        $this->assertNull($result->pending);

        $this->travel(21)->seconds();
        $this->fakeBmc(['POST '.self::RESET => Http::response(['error' => ['message' => 'Server is already powered OFF.']], 400)]);
        $this->assertSame('The server is already off.', app(PowerControl::class)->run($machine, PowerAction::Off, $this->admin)->message);
    }

    public function test_wrong_login_message_does_not_contain_the_password(): void
    {
        Exceptions::fake();
        $machine = $this->machine();
        $power = app(PowerControl::class);

        $this->fakeBmc(['POST '.self::RESET => Http::response(['error' => ['@Message.ExtendedInfo' => [[
            'Message' => '<b>Login nuvabill-operator with '.self::PASSWORD.' ('.rawurlencode(self::PASSWORD).') was refused</b>',
        ]]]], 400)]);
        $result = $power->run($machine, PowerAction::Off, $this->admin);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('[hidden]', $result->message);
        $this->assertStringNotContainsString(self::PASSWORD, $result->message);
        $this->assertStringNotContainsString('<b>', $result->message);

        $this->travel(21)->seconds();
        $this->fakeBmc(['POST '.self::RESET => Http::response(['error' => ['message' => 'Unauthorized']], 401)]);
        $this->assertSame('The BMC refused the user name or password.', $power->run($machine, PowerAction::Off, $this->admin)->message);

        // A raw transport error that holds the login is never shown, logged or reported.
        $this->travel(21)->seconds();
        $this->fakeBmc(['POST '.self::RESET => fn (Request $request) => Http::failedConnection('cURL error 28: Operation timed out for https://nuvabill-operator:'.self::PASSWORD.'@10.20.0.11'.self::RESET)($request)]);
        $this->assertSame('The BMC did not answer in time.', $power->run($machine, PowerAction::Off, $this->admin)->message);

        // A client sees no BMC details at all.
        $this->travel(21)->seconds();
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
        $this->assertSame("The server's management port does not answer. Try again later or open a ticket.", $power->run($machine, PowerAction::Off, $client)->message);

        // A password with "<" in it is hidden whole, as sent and HTML-escaped.
        $this->travel(21)->seconds();
        $machine->forceFill(['bmc_password' => 'Ab<cd9!x'])->save();
        $this->fakeBmc(['POST '.self::RESET => Http::response(['error' => ['message' => 'Login with Ab<cd9!x or Ab&lt;cd9!x was refused']], 400)]);
        $this->assertSame('The BMC did not accept the request: Login with [hidden] or [hidden] was refused', $power->run($machine, PowerAction::Off, $this->admin)->message);
        $this->assertStringNotContainsString('Ab', (string) $machine->fresh()->bmc_last_error);

        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%'.self::PASSWORD.'%')->exists());
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%with Ab%')->exists());
        $this->assertStringNotContainsString(self::PASSWORD, (string) $machine->fresh()->bmc_last_error);
        $this->assertSame(5, ActivityLog::query()->where('action', 'machine.power_failed')->count());
        Exceptions::assertNothingReported();
    }

    public function test_unexpected_errors_reach_the_log_without_the_login(): void
    {
        Log::spy();
        $leak = 'Request for nuvabill-operator:'.self::PASSWORD.' failed';

        // A fault on this side, not a network error, while the scheduler reads the power state.
        $this->fakeBmc([self::SYSTEM => fn () => throw new RuntimeException($leak)]);
        $redfish = $this->machine(['name' => 'DS-101', 'status' => MachineStatus::Wiping, 'power_check_at' => now()->subMinutes(2)]);

        // A power driver that fails where nothing expects it, with the login in its message.
        app(PowerDrivers::class)->register(new class($leak) implements PowerDriver
        {
            public function __construct(private string $leak) {}

            public function type(): string
            {
                return 'ipmi';
            }

            public function label(): string
            {
                return 'Failing driver';
            }

            public function available(): bool
            {
                throw new RuntimeException($this->leak);
            }

            public function test(Machine $machine): BmcTestResult
            {
                return BmcTestResult::failed('Not used.');
            }

            public function powerState(Machine $machine): PowerState
            {
                return PowerState::Unknown;
            }

            public function supportedActions(Machine $machine): array
            {
                return [];
            }

            public function run(Machine $machine, PowerAction $action): void {}
        });
        $failing = Machine::factory()->ipmi()->wiping()->create(['name' => 'DS-102', 'power_check_at' => now()->subMinute()]);

        $this->assertSame(2, app(PowerControl::class)->enforce());

        // Only the class name of what went wrong is logged.
        Log::shouldHaveReceived('error')->with('A Redfish request failed: RuntimeException', Mockery::any())->once();
        Log::shouldHaveReceived('error')->with("Power check of machine #{$failing->id} failed: RuntimeException", Mockery::any())->once();

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, fn (mixed ...$arguments): bool => $this->holdsLogin($arguments));
        }

        $this->assertSame(1, $redfish->fresh()->bmc_failures);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $redfish->fresh()->bmc_last_error);
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%'.self::PASSWORD.'%')->exists());
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%nuvabill-operator%')->exists());
    }

    public function test_a_changed_certificate_stops_every_call_with_a_clear_message(): void
    {
        $this->fakeBmc([self::SYSTEM => fn (Request $request) => Create::rejectionFor(new ConnectException(
            'cURL error 90: SSL: public key does not match pinned public key (see https://curl.se/libcurl/c/libcurl-errors.html)',
            $request->toPsrRequest(),
        ))]);
        $machine = $this->machine();

        $this->assertSame(PowerState::Unknown, app(PowerControl::class)->state($machine, fresh: true));
        $this->assertSame("The BMC's certificate changed. Check it on the BMC and trust it again.", $machine->fresh()->bmc_last_error);
        $this->assertSame(1, $machine->fresh()->bmc_failures);
    }

    public function test_a_changed_certificate_can_be_checked_and_trusted_again(): void
    {
        $this->fakeBmc(['/redfish/v1/' => fn (Request $request) => $this->pinMismatch($request)]);
        $machine = $this->machine(['bmc_failures' => 4, 'bmc_last_error' => "The BMC's certificate changed. Check it on the BMC and trust it again."]);
        $power = app(PowerControl::class);
        $renewed = FakeCertificateProbe::certificate('B');
        $this->probe->certificate = $renewed;

        $result = $power->test($machine);

        $this->assertTrue($result->needsTrust);
        $this->assertFalse($result->ok);
        $this->assertSame('The BMC shows a new certificate. Check it below and trust it again only when its fingerprint matches the one on the BMC.', $result->message);
        $this->assertSame($renewed->fingerprint, $result->certificate?->fingerprint);
        $this->assertCount(1, $this->sent, 'Only the service root was asked for, and curl stopped at the handshake.');
        $this->assertFalse($this->sent[0]['request']->hasHeader('Authorization'));
        $this->assertNotSame($renewed->pin, $machine->fresh()->bmc_tls_pin, 'Nothing changes before staff trust it.');

        $power->trust($machine, $renewed->fingerprint, null, $this->admin);

        $machine->refresh();
        $this->assertSame($renewed->pin, $machine->bmc_tls_pin);
        $this->assertSame($renewed->fingerprint, $machine->bmc_tls_fingerprint);
        $this->assertSame(0, $machine->bmc_failures);
        $this->assertNull($machine->bmc_last_error);

        // A certificate read with the trusted key after all is nothing new to trust.
        $this->fakeBmc(['/redfish/v1/' => fn (Request $request) => $this->pinMismatch($request)]);
        $again = $power->test($machine);
        $this->assertFalse($again->needsTrust);
        $this->assertNull($again->certificate);
        $this->assertSame("The BMC's certificate changed. Check it on the BMC and trust it again.", $again->message);

        // Other failures of the service root stay plain failures.
        $this->fakeBmc(['/redfish/v1/' => fn (Request $request) => Http::failedConnection()($request)]);
        $this->assertFalse($power->test($machine)->needsTrust);
    }

    public function test_restart_is_hidden_when_graceful_restart_is_not_supported(): void
    {
        $machine = $this->machine(['bmc_reset_types' => ['On', 'ForceOff', 'GracefulShutdown', 'ForceRestart']]);
        $power = app(PowerControl::class);

        $this->assertNotContains(PowerAction::Restart, $power->actions($machine));
        $this->assertSame('Not supported by this server.', $power->run($machine, PowerAction::Restart, $this->admin)->message);
        Http::assertNothingSent();
    }

    public function test_shutdown_falls_back_to_the_power_button_only_when_on(): void
    {
        $machine = $this->machine(['bmc_reset_types' => ['On', 'ForceOff', 'ForceRestart', 'PushPowerButton']]);
        $power = app(PowerControl::class);

        $this->fakeBmc([
            self::SYSTEM => Http::response(['PowerState' => 'On']),
            'POST '.self::RESET => Http::response(null, 204),
        ]);
        $this->assertContains(PowerAction::Shutdown, $power->actions($machine));
        $this->assertTrue($power->run($machine, PowerAction::Shutdown, $this->admin)->ok);
        $this->assertSame(['ResetType' => 'PushPowerButton'], $this->sent[1]['request']->data());

        // On a server that is off, the power button would switch it on: nothing is sent.
        $this->travel(21)->seconds();
        $this->fakeBmc([self::SYSTEM => Http::response(['PowerState' => 'Off'])]);
        $result = $power->run($machine, PowerAction::Shutdown, $this->admin);
        $this->assertSame('The server is already off.', $result->message);
        $this->assertCount(1, $this->sent);
        $this->assertSame('GET', $this->sent[0]['request']->method());
    }

    public function test_power_state_is_cached_for_fifteen_seconds(): void
    {
        $this->fakeBmc([self::SYSTEM => Http::response(['PowerState' => 'On'])]);
        $machine = $this->machine(['power_state' => null]);
        $power = app(PowerControl::class);

        $this->assertSame(PowerState::On, $power->state($machine));
        $this->assertSame(PowerState::On, $power->state($machine));
        $this->assertCount(1, $this->sent);
        $this->assertSame('on', $machine->fresh()->power_state);

        $this->travel(16)->seconds();
        $this->assertSame(PowerState::On, $power->state($machine));
        $this->assertCount(2, $this->sent);

        $this->assertSame(PowerState::On, $power->state($machine, fresh: true));
        $this->assertCount(3, $this->sent);
    }

    public function test_three_failures_alert_staff_once_a_day(): void
    {
        $this->fakeBmc([self::SYSTEM => fn (Request $request) => Http::failedConnection()($request)]);
        $machine = $this->machine(['name' => 'DS-101']);
        $power = app(PowerControl::class);

        foreach (range(1, 5) as $try) {
            $this->assertSame(PowerState::Unknown, $power->state($machine, fresh: true));
        }

        $this->assertSame(5, $machine->fresh()->bmc_failures);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'DS-101: The BMC does not answer');
        Mail::assertSentCount(1);

        $this->travel(25)->hours();
        $power->state($machine, fresh: true);
        Mail::assertSentCount(2);

        // A good answer starts the count again.
        $this->fakeBmc([self::SYSTEM => Http::response(['PowerState' => 'Off'])]);
        $this->assertSame(PowerState::Off, $power->state($machine, fresh: true));
        $this->assertSame(0, $machine->fresh()->bmc_failures);
        $this->assertNull($machine->fresh()->bmc_last_error);
    }

    public function test_pinned_mode_is_refused_without_curl_exec(): void
    {
        $this->app->instance(PinningSupport::class, new PinningSupport(fn (string $function): bool => $function !== 'curl_exec', ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']));
        $this->app->forgetInstance(PowerDrivers::class);
        $power = app(PowerControl::class);

        $untrusted = $this->machine(['bmc_tls_pin' => null]);
        $result = $power->test($untrusted);
        $this->assertFalse($result->ok);
        $this->assertFalse($result->needsTrust);
        $this->assertSame(PinningSupport::unavailableMessage(), $result->message);
        $this->assertSame([], $this->probe->calls, 'Not even the certificate is read: a pin could not be checked anyway.');

        $trusted = $this->machine(['name' => 'DS-102']);
        $this->assertSame(PowerState::Unknown, $power->state($trusted, fresh: true));
        $this->assertSame([], $power->actions($trusted));
        $this->assertFalse($power->run($trusted, PowerAction::Off, $this->admin)->ok);
        Http::assertNothingSent();

        // A verified certificate needs no pin, so it still works.
        $this->fakeBmc([self::SYSTEM => Http::response(['PowerState' => 'On'])]);
        $verified = $this->machine(['name' => 'DS-103', 'bmc_tls_mode' => 'verified', 'bmc_tls_pin' => null]);
        $this->assertSame(PowerState::On, $power->state($verified, fresh: true));
        $this->assertTrue($this->sent[0]['options']['verify']);
        $this->assertFalse($this->sent[0]['options'][PinnedCurlFactory::PIN_OPTION]);
    }

    public function test_pinning_needs_curl_with_a_tls_library_that_checks_pins(): void
    {
        $all = fn (string $function): bool => true;

        $this->assertTrue((new PinningSupport($all, ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']))->available());
        $this->assertTrue((new PinningSupport($all, ['version' => '7.39.0', 'ssl_version' => 'GnuTLS/3.7.1']))->available());
        $this->assertFalse((new PinningSupport($all, ['version' => '7.38.0', 'ssl_version' => 'OpenSSL/1.0.2']))->available());
        $this->assertFalse((new PinningSupport($all, ['version' => '8.5.0', 'ssl_version' => 'NSS/3.44']))->available());
        $this->assertFalse((new PinningSupport(fn (string $function): bool => $function !== 'curl_exec', ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']))->available());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeHosts(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'cloud metadata' => ['169.254.169.254'],
            'IPv6 loopback' => ['::1'],
            'unspecified' => ['0.0.0.0'],
            'IPv6 link-local' => ['fe80::1'],
            'multicast' => ['224.0.0.1'],
            'mapped loopback' => ['::ffff:127.0.0.1'],
            'NAT64 metadata' => ['64:ff9b::a9fe:a9fe'],
            '6to4 loopback' => ['2002:7f00:1::1'],
            'scheme' => ['https://10.0.0.5'],
            'login' => ['admin@10.0.0.5'],
            'path' => ['10.0.0.5/redfish'],
            'port' => ['10.0.0.5:443'],
            'short IP form' => ['2130706433'],
            'localhost' => ['localhost'],
        ];
    }

    #[DataProvider('unsafeHosts')]
    public function test_unsafe_bmc_hosts_are_refused(string $host): void
    {
        $this->assertNotNull(BmcAddress::validateHost($host));

        // Also when such a value got into the database some other way.
        $machine = $this->machine(['bmc_host' => $host]);
        $this->assertSame(PowerState::Unknown, app(PowerControl::class)->state($machine, fresh: true));
        Http::assertNothingSent();
    }

    public function test_private_bmc_hosts_are_accepted_and_host_names_are_pinned_to_the_checked_address(): void
    {
        foreach (['10.20.0.11', '192.168.10.5', 'bmc1.mgmt.example.test', 'fd12:3456::11', '::ffff:10.0.0.5'] as $host) {
            $this->assertNull(BmcAddress::validateHost($host), $host);
        }

        $this->fakeBmc([self::SYSTEM => Http::response(['PowerState' => 'On'])]);
        BmcAddress::lookupUsing(fn (string $host): array => $host === 'bmc1.mgmt.example.test' ? ['10.20.0.50'] : []);
        $named = $this->machine(['bmc_host' => 'bmc1.mgmt.example.test']);

        $this->assertSame(PowerState::On, app(PowerControl::class)->state($named, fresh: true));
        $this->assertSame('bmc1.mgmt.example.test', parse_url($this->sent[0]['request']->url(), PHP_URL_HOST));
        $this->assertSame(['bmc1.mgmt.example.test:443:10.20.0.50'], $this->sent[0]['options']['curl'][CURLOPT_RESOLVE]);

        // The real curl handler takes these options as they are (Guzzle refuses some curl options).
        $factory = new PinnedCurlFactory;
        $factory->release($factory->create($this->sent[0]['request']->toPsrRequest(), $this->sent[0]['options']));

        // A name that also points at a refused address is refused as a whole (DNS rebinding).
        BmcAddress::lookupUsing(fn (string $host): array => ['10.20.0.50', '127.0.0.1']);

        try {
            BmcAddress::for($named, 443);
            $this->fail('The host name should be refused.');
        } catch (BmcException $exception) {
            $this->assertSame(BmcException::REFUSED, $exception->reason);
        }

        $ipv6 = new BmcAddress('fd12:3456::11', 'fd12:3456::11', 443);
        $this->assertSame('https://[fd12:3456::11]:443', $ipv6->baseUrl());
        $this->assertNull($ipv6->curlResolve());
        $this->assertSame('bmc6.example.test:443:[fd12:3456::11]', (new BmcAddress('bmc6.example.test', 'fd12:3456::11', 443))->curlResolve());
    }

    public function test_peer_certificate_pin_is_the_sha256_of_the_public_key(): void
    {
        [$key, $signed] = $this->selfSignedCertificate();
        $certificate = PeerCertificate::fromX509($signed);
        $publicKey = (string) openssl_pkey_get_details($key)['key'];
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $publicKey));

        $this->assertSame(base64_encode(hash('sha256', $der, true)), $certificate->pin);
        $this->assertSame(PeerCertificate::normalizeFingerprint((string) openssl_x509_fingerprint($signed, 'sha256')), $certificate->fingerprint);
        $this->assertSame('bmc1.mgmt.example.test', $certificate->subject);
        $this->assertNotNull($certificate->validTo);
        $this->assertArrayNotHasKey('pin', $certificate->toArray());
    }

    /**
     * Against a real TLS server on this computer: with a wrong pin curl stops after the handshake,
     * before the request (and its login) is sent; with the right pin, and peer checks off, the
     * request goes through. A request that does not say which pin applies is refused.
     */
    public function test_a_wrong_pin_stops_the_connection_before_the_request_is_sent(): void
    {
        if (! (new PinningSupport)->available()) {
            $this->markTestSkipped('This PHP cannot check pinned keys.');
        }

        [$key, $signed] = $this->selfSignedCertificate();
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuvabill-tls-'.bin2hex(random_bytes(6));
        mkdir($folder);
        openssl_x509_export_to_file($signed, $folder.'/certificate.pem');
        openssl_pkey_export_to_file($key, $folder.'/private.pem', null, ['config' => $this->opensslConfig($folder)]);
        file_put_contents($folder.'/server.php', <<<'PHP'
            <?php
            $context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2], 'verify_peer' => false]]);
            $server = stream_socket_server('tcp://127.0.0.1:0', $code, $text, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
            echo 'port '.substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1)."\n";
            $until = time() + 20;
            while (time() < $until) {
                $client = @stream_socket_accept($server, 1);
                if ($client === false) { continue; }
                stream_set_timeout($client, 5);
                if (! @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) { fclose($client); continue; }
                $head = '';
                while (! str_contains($head, "\r\n\r\n") && ($line = fgets($client)) !== false) { $head .= $line; }
                if ($head !== '') { echo 'request '.strtok($head, "\r\n")."\n"; }
                fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
                fclose($client);
            }
            PHP);

        $server = new Process([PHP_BINARY, $folder.'/server.php', $folder.'/certificate.pem', $folder.'/private.pem']);
        $server->setTimeout(60);
        $server->start();

        try {
            $server->waitUntil(fn (string $type, string $output): bool => str_contains($server->getOutput(), "\n"));
            $port = (int) substr(trim($server->getOutput()), 5);
            $client = new GuzzleClient(['handler' => HandlerStack::create(new CurlHandler(['handle_factory' => new PinnedCurlFactory]))]);
            $url = "https://127.0.0.1:{$port}/redfish/v1/";
            $options = ['verify' => false, 'timeout' => 10, 'headers' => ['Authorization' => 'Basic '.base64_encode('nuvabill-operator:'.self::PASSWORD)]];

            try {
                $client->get($url, $options + [PinnedCurlFactory::PIN_OPTION => 'sha256//'.base64_encode(hash('sha256', 'another key', true))]);
                $this->fail('A wrong pin must stop the connection.');
            } catch (TransferException $exception) {
                $this->assertStringContainsString('cURL error 90', $exception->getMessage());
            }

            try {
                $client->get($url, $options);
                $this->fail('A request without a pin option must be refused.');
            } catch (BmcException $exception) {
                $this->assertSame(BmcException::NOT_TRUSTED, $exception->reason);
            }

            $answer = $client->get($url, $options + [PinnedCurlFactory::PIN_OPTION => 'sha256//'.PeerCertificate::fromX509($signed)->pin]);
            $this->assertSame(200, $answer->getStatusCode());
        } finally {
            $server->stop(0);
            array_map('unlink', glob($folder.'/*') ?: []);
            rmdir($folder);
        }

        // Only the request with the right pin reached the server.
        $this->assertSame(1, substr_count($server->getOutput(), 'request '));
    }

    /**
     * A throwaway key and self-signed certificate for bmc1.mgmt.example.test.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: \OpenSSLCertificate}
     */
    private function selfSignedCertificate(): array
    {
        $folder = sys_get_temp_dir();
        $options = ['config' => $this->opensslConfig($folder), 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];

        try {
            $key = openssl_pkey_new($options);
            $request = $key === false ? false : openssl_csr_new(['commonName' => 'bmc1.mgmt.example.test'], $key, $options);
            $signed = $request === false || $request === true ? false : openssl_csr_sign($request, null, $key, 30, $options);
        } finally {
            @unlink($options['config']);
        }

        if ($key === false || $signed === false) {
            $this->markTestSkipped('This PHP cannot make a test certificate.');
        }

        return [$key, $signed];
    }

    /**
     * A minimal OpenSSL config, as some PHP builds (Windows) ship without one.
     */
    private function opensslConfig(string $folder): string
    {
        $file = $folder.DIRECTORY_SEPARATOR.'openssl-'.bin2hex(random_bytes(4)).'.cnf';
        file_put_contents($file, "[req]\ndistinguished_name = dn\n[dn]\n");

        return $file;
    }

    /**
     * A Redfish machine that was tested and trusted.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function machine(array $attributes = []): Machine
    {
        $machine = Machine::factory()->redfish()->create(['name' => $attributes['name'] ?? 'DS-100']);
        $machine->forceFill($attributes)->save();

        return $machine->fresh();
    }

    /**
     * From now on the fake BMC answers by "METHOD /path" or "/path"; anything else is a 404.
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakeBmc(array $routes): void
    {
        $this->sent = [];
        $this->routes = $routes;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function answer(Request $request, array $options): mixed
    {
        $this->sent[] = ['request' => $request, 'options' => $options];
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $answer = $this->routes[$request->method().' '.$path] ?? $this->routes[$path] ?? Http::response(['error' => ['message' => 'Not found']], 404);

        return $answer instanceof Closure ? $answer($request) : $answer;
    }

    /**
     * What curl does when the BMC shows another key than the pinned one: it stops at the handshake.
     */
    private function pinMismatch(Request $request): PromiseInterface
    {
        return Create::rejectionFor(new ConnectException(
            'cURL error 90: SSL: public key does not match pinned public key (see https://curl.se/libcurl/c/libcurl-errors.html)',
            $request->toPsrRequest(),
        ));
    }

    /**
     * Whether log call arguments hold the BMC login: in a message, a context value, or an
     * exception's message, trace or earlier exceptions.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function holdsLogin(array $values): bool
    {
        foreach ($values as $value) {
            $texts = match (true) {
                is_string($value) => [$value],
                $value instanceof Throwable => [$value->getMessage(), $value->getTraceAsString()],
                default => [],
            };

            foreach ($texts as $text) {
                if (str_contains($text, self::PASSWORD) || str_contains($text, 'nuvabill-operator')) {
                    return true;
                }
            }

            if (is_array($value) && $this->holdsLogin($value)) {
                return true;
            }

            if ($value instanceof Throwable && $value->getPrevious() !== null && $this->holdsLogin([$value->getPrevious()])) {
                return true;
            }
        }

        return false;
    }

    private function assertTrustRefused(Closure $trust, string $message): void
    {
        try {
            $trust();
            $this->fail('Trusting should be refused.');
        } catch (BmcException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
