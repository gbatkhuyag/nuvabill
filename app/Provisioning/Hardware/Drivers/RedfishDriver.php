<?php

namespace App\Provisioning\Hardware\Drivers;

use App\Enums\BmcType;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Models\Machine;
use App\Provisioning\Hardware\BmcAddress;
use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\BmcTestResult;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PinnedCurlFactory;
use App\Provisioning\Hardware\Tls\PinningSupport;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Power control through Redfish, the HTTPS JSON API of iDRAC, iLO, Supermicro and most other BMCs.
 *
 * Every request goes to https://{checked BMC address}:{port} with a path under /redfish/v1 that
 * passes a strict pattern, so a BMC can never steer a request to another host (links in its
 * answers that are absolute, or leave /redfish/v1, are refused). Redirects are not followed, the
 * answer may be at most 1 MB, and only a few known fields are read.
 *
 * TLS: in pinned mode curl checks the BMC key against the pin staff trusted before any data is
 * sent, and no login is ever sent before a pin exists; in verified mode the certificate must come
 * from a trusted CA. The login goes as HTTP Basic auth, so no Redfish session is ever left open.
 * Errors are mapped to short messages of our own, with the login hidden.
 */
final class RedfishDriver implements PowerDriver
{
    public const MAX_BODY = 1_048_576;

    public const CONNECT_TIMEOUT = 5;

    public const READ_TIMEOUT = 15;

    public const ACTION_TIMEOUT = 20;

    /**
     * Paths the driver may call: only below /redfish/v1, made of plain path characters.
     */
    private const PATH_PATTERN = "#^/redfish/v1(/[A-Za-z0-9._~%:@!$&'()*+,;=-]+)*/?$#D";

    private const MAX_SYSTEMS = 16;

    /**
     * The Redfish ResetType values the driver keeps from a BMC's answer.
     *
     * @var list<string>
     */
    private const RESET_TYPES = ['On', 'ForceOff', 'GracefulShutdown', 'GracefulRestart', 'ForceRestart', 'Nmi', 'ForceOn', 'PushPowerButton', 'PowerCycle', 'FullPowerCycle', 'Suspend', 'Pause', 'Resume'];

    /**
     * Redfish says a BMC that lists no allowed values accepts every reset type; only the ones
     * Nuvabill uses are assumed then.
     *
     * @var list<string>
     */
    private const ASSUMED_RESET_TYPES = ['On', 'ForceOff', 'GracefulShutdown', 'GracefulRestart', 'ForceRestart'];

    public function __construct(
        private readonly CertificateProbe $probe,
        private readonly PinningSupport $pinning,
    ) {}

    public function type(): string
    {
        return BmcType::Redfish->value;
    }

    public function label(): string
    {
        return BmcType::Redfish->label();
    }

    /**
     * Redfish needs curl: the TLS pin and the DNS pin are curl options.
     */
    public function available(): bool
    {
        return function_exists('curl_exec') && function_exists('curl_init');
    }

    public function test(Machine $machine): BmcTestResult
    {
        try {
            return $this->discover($machine);
        } catch (BmcException $exception) {
            return BmcTestResult::failed($exception->getMessage());
        }
    }

    public function powerState(Machine $machine): PowerState
    {
        $address = BmcAddress::for($machine, 443);
        $system = $this->get($machine, $address, $this->systemPath($machine));

        return PowerState::fromRedfish(is_string($system['PowerState'] ?? null) ? $system['PowerState'] : null);
    }

    public function supportedActions(Machine $machine): array
    {
        if (! $this->ready($machine)) {
            return [];
        }

        $types = $this->savedResetTypes($machine);
        $offered = [
            PowerAction::On->value => in_array('On', $types, true),
            PowerAction::Shutdown->value => in_array('GracefulShutdown', $types, true) || in_array('PushPowerButton', $types, true),
            PowerAction::Restart->value => in_array('GracefulRestart', $types, true),
            PowerAction::Reset->value => in_array('ForceRestart', $types, true),
            PowerAction::Off->value => in_array('ForceOff', $types, true),
        ];

        return array_values(array_map(fn (string $action): PowerAction => PowerAction::from($action), array_keys(array_filter($offered))));
    }

    public function run(Machine $machine, PowerAction $action): void
    {
        if (! in_array($action, $this->supportedActions($machine), true)) {
            throw BmcException::unsupported();
        }

        $types = $this->savedResetTypes($machine);
        $type = $action->redfishResetType();

        // Without GracefulShutdown, pressing the power button shuts most systems down, but only
        // while they are on: on a server that is off it would switch it on.
        if (! in_array($type, $types, true)) {
            if ($action !== PowerAction::Shutdown || ! in_array('PushPowerButton', $types, true)) {
                throw BmcException::unsupported();
            }

            $state = $this->powerState($machine);

            if ($state === PowerState::Off) {
                throw BmcException::conflict(__('The server is already off.'));
            }

            if ($state !== PowerState::On) {
                throw BmcException::unsupported(__('The server is turning on or off. Try again in a minute.'));
            }

            $type = 'PushPowerButton';
        }

        $this->send($machine, BmcAddress::for($machine, 443), 'POST', (string) $machine->bmc_reset_target, auth: true, body: ['ResetType' => $type]);
    }

    /**
     * The options of every request to the machine's BMC. The key pin and the size limit are set on
     * the curl handle by PinnedCurlFactory; a host name is pinned to the checked address.
     *
     * @return array<string, mixed>
     */
    private function transportOptions(Machine $machine, BmcAddress $address): array
    {
        $options = [
            'verify' => ! $this->pinned($machine),
            PinnedCurlFactory::PIN_OPTION => $this->pinned($machine) ? 'sha256//'.$machine->bmc_tls_pin : false,
            PinnedCurlFactory::MAX_BODY_OPTION => self::MAX_BODY,
            'on_headers' => function (ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BODY) {
                    throw BmcException::refused(__('The BMC sent too much data.'));
                }
            },
        ];

        if (($resolve = $address->curlResolve()) !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$resolve]];
        }

        return $options;
    }

    /**
     * Read the service root, the systems and the chosen system, as Redfish describes them.
     *
     * @throws BmcException
     */
    private function discover(Machine $machine): BmcTestResult
    {
        $address = BmcAddress::for($machine, 443);

        if ($this->pinned($machine)) {
            if (! $this->pinning->available()) {
                throw BmcException::unsupported(PinningSupport::unavailableMessage());
            }

            // No pin yet: only the certificate is read, and nothing is sent until staff trust it.
            if (! $this->hasValidPin($machine)) {
                return BmcTestResult::trustFirst($this->probe->fetch($address->ip, $address->port, $address->host));
            }
        }

        $root = $this->get($machine, $address, '/redfish/v1/', auth: false);
        $collection = $this->requirePath(is_array($root['Systems'] ?? null) ? ($root['Systems']['@odata.id'] ?? null) : null, __('The BMC does not list its systems.'));
        $listed = $this->get($machine, $address, $collection)['Members'] ?? null;
        $members = [];

        foreach (array_slice(is_array($listed) ? array_values($listed) : [], 0, self::MAX_SYSTEMS) as $member) {
            $path = is_array($member) ? $this->path($member['@odata.id'] ?? null) : null;

            if ($path !== null) {
                $members[] = $path;
            }
        }

        $members = array_values(array_unique($members));

        if ($members === []) {
            throw BmcException::refused(__('The BMC lists no systems.'));
        }

        $chosen = count($members) === 1 ? $members[0] : (in_array($machine->bmc_system_path, $members, true) ? (string) $machine->bmc_system_path : null);

        if ($chosen === null) {
            return BmcTestResult::chooseSystem($members);
        }

        $system = $this->get($machine, $address, $chosen);
        $reset = is_array($system['Actions'] ?? null) && is_array($system['Actions']['#ComputerSystem.Reset'] ?? null) ? $system['Actions']['#ComputerSystem.Reset'] : [];
        $target = isset($reset['target']) ? $this->requirePath($reset['target'], __('The BMC sent a link we do not follow.')) : null;
        $types = $this->resetTypes($reset['ResetType@Redfish.AllowableValues'] ?? null);

        if ($target !== null && $types === null && ($info = $this->path($reset['@Redfish.ActionInfo'] ?? null)) !== null) {
            foreach ((array) ($this->get($machine, $address, $info)['Parameters'] ?? []) as $parameter) {
                if (is_array($parameter) && ($parameter['Name'] ?? null) === 'ResetType') {
                    $types = $this->resetTypes($parameter['AllowableValues'] ?? null);
                }
            }
        }

        $vendor = $this->text($machine, $system['Manufacturer'] ?? null, 100);
        $model = $this->text($machine, $system['Model'] ?? null, 100);
        $name = trim($vendor.' '.$model);

        return new BmcTestResult(
            ok: true,
            message: match (true) {
                $target === null => __('Connected, but the BMC offers no power actions for this system.'),
                $name !== '' => __('Connected: :name.', ['name' => $name]),
                default => __('Connected.'),
            },
            systems: $members,
            systemPath: $chosen,
            resetTarget: $target,
            resetTypes: $target === null ? [] : ($types ?? self::ASSUMED_RESET_TYPES),
            vendor: $vendor !== '' ? $vendor : null,
            model: $model !== '' ? $model : null,
            powerState: PowerState::fromRedfish(is_string($system['PowerState'] ?? null) ? $system['PowerState'] : null),
        );
    }

    /**
     * GET a Redfish resource and read its JSON.
     *
     * @return array<string, mixed>
     *
     * @throws BmcException
     */
    private function get(Machine $machine, BmcAddress $address, string $path, bool $auth = true): array
    {
        $response = $this->send($machine, $address, 'GET', $path, $auth);
        $body = $response->body();

        if (strlen($body) > self::MAX_BODY) {
            throw BmcException::refused(__('The BMC sent too much data.'));
        }

        $data = json_decode($body, true, 32);

        if (! is_array($data)) {
            throw BmcException::refused(__('The BMC sent an answer we cannot read.'));
        }

        return $data;
    }

    /**
     * Send one request to the BMC and check its status.
     *
     * @param  array<string, mixed>|null  $body
     *
     * @throws BmcException
     */
    private function send(Machine $machine, BmcAddress $address, string $method, string $path, bool $auth, ?array $body = null): Response
    {
        $path = $this->requirePath($path, __('The BMC sent a link we do not follow.'));
        $this->assertTrusted($machine);

        $request = Http::withOptions($this->transportOptions($machine, $address))
            // Always curl, with the key pin set on each handle: PHP streams would ignore both pins.
            ->setHandler(new CurlHandler(['handle_factory' => new PinnedCurlFactory]))
            ->withoutRedirecting()
            ->acceptJson()
            ->withHeaders(['OData-Version' => '4.0'])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($method === 'POST' ? self::ACTION_TIMEOUT : self::READ_TIMEOUT);

        if ($auth) {
            $request = $request->withBasicAuth(...$this->login($machine));
        }

        $url = $address->baseUrl().$path;

        try {
            $response = $method === 'POST' ? $request->post($url, $body ?? []) : $request->get($url);
        } catch (Throwable $exception) {
            throw $this->transportError($exception);
        }

        $this->checkStatus($machine, $response);

        return $response;
    }

    /**
     * @throws BmcException for any answer but 2xx.
     */
    private function checkStatus(Machine $machine, Response $response): void
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status >= 300 && $status < 400) {
            throw BmcException::refused(__('The BMC tried to send us elsewhere. Check its address.'));
        }

        if (in_array($status, [401, 403], true)) {
            throw BmcException::auth();
        }

        if ($status === 404) {
            throw BmcException::refused(__('The BMC does not know this system. Run Test connection again.'));
        }

        $reason = $this->errorReason($machine, $response);

        if ($status === 409 || ($status === 400 && $reason !== '' && preg_match('/\balready\b/i', $reason) === 1)) {
            throw BmcException::conflict($reason !== '' ? $reason : __('The BMC says this is not possible right now.'));
        }

        throw BmcException::refused($reason !== ''
            ? __('The BMC did not accept the request: :reason', ['reason' => $reason])
            : __('The BMC did not accept the request.'));
    }

    /**
     * The cleaned reason from a Redfish error answer, or ''.
     */
    private function errorReason(Machine $machine, Response $response): string
    {
        $body = $response->body();
        $data = strlen($body) <= self::MAX_BODY ? json_decode($body, true, 32) : null;
        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
        $extended = is_array($error['@Message.ExtendedInfo'] ?? null) ? array_values($error['@Message.ExtendedInfo']) : [];
        $message = is_array($extended[0] ?? null) ? ($extended[0]['Message'] ?? null) : null;

        return $this->text($machine, is_string($message) ? $message : ($error['message'] ?? null), 200);
    }

    /**
     * Turn a transport error into our own message: never the raw text, never as a cause.
     */
    private function transportError(Throwable $exception): BmcException
    {
        $code = null;

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof BmcException) {
                return $cause;
            }

            $context = method_exists($cause, 'getHandlerContext') ? $cause->getHandlerContext() : [];

            if (is_array($context) && isset($context['errno'])) {
                $code = (int) $context['errno'];

                break;
            }

            if (preg_match('/cURL error (\d+)/', $cause->getMessage(), $match) === 1) {
                $code = (int) $match[1];

                break;
            }
        }

        // Not a network error but a fault on this side: staff see "unreachable", the log gets the class.
        if ($code === null && ! $exception instanceof ConnectionException) {
            report(new RuntimeException('A Redfish request failed: '.$exception::class));
        }

        return match ($code) {
            28 => BmcException::unreachable(__('The BMC did not answer in time.')),
            90 => BmcException::pinMismatch(),
            51, 60, 83 => BmcException::notTrusted(__('The BMC certificate is not from a trusted CA. Use pinned mode and trust its certificate instead.')),
            35 => BmcException::unreachable(__('We could not open a secure connection to the BMC.')),
            63 => BmcException::refused(__('The BMC sent too much data.')),
            default => BmcException::unreachable(),
        };
    }

    /**
     * No login is sent before the BMC is trusted, and pinned mode only runs where the pin is
     * checked.
     *
     * @throws BmcException
     */
    private function assertTrusted(Machine $machine): void
    {
        if (! $this->pinned($machine)) {
            return;
        }

        if (! $this->pinning->available()) {
            throw BmcException::unsupported(PinningSupport::unavailableMessage());
        }

        if (! $this->hasValidPin($machine)) {
            throw BmcException::notTrusted();
        }
    }

    /**
     * Whether the machine has what power actions need: a trusted BMC, a system and a reset target.
     */
    private function ready(Machine $machine): bool
    {
        if ($this->pinned($machine) && (! $this->hasValidPin($machine) || ! $this->pinning->available())) {
            return false;
        }

        return $this->path($machine->bmc_system_path) !== null && $this->path($machine->bmc_reset_target) !== null;
    }

    /**
     * @throws BmcException
     */
    private function systemPath(Machine $machine): string
    {
        $path = $this->path($machine->bmc_system_path);

        if ($path === null) {
            throw BmcException::unsupported(__('Run Test connection on this machine first.'));
        }

        return $path;
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws BmcException
     */
    private function login(Machine $machine): array
    {
        $username = (string) $machine->bmc_username;
        $password = (string) $machine->bmc_password;

        if ($username === '' || $password === '') {
            throw BmcException::refused(__('Enter the BMC user name and password first.'));
        }

        return [$username, $password];
    }

    private function pinned(Machine $machine): bool
    {
        return $machine->bmc_tls_mode !== 'verified';
    }

    /**
     * A pin is base64 of a SHA-256 hash: 44 characters. Anything else (such as a second pin added
     * with ";") is never handed to curl.
     */
    private function hasValidPin(Machine $machine): bool
    {
        return preg_match('#^[A-Za-z0-9+/]{43}=$#D', (string) $machine->bmc_tls_pin) === 1;
    }

    /**
     * A path the driver may call, or null.
     */
    private function path(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 255 || preg_match(self::PATH_PATTERN, $value) !== 1) {
            return null;
        }

        $decoded = rawurldecode($value);

        return str_contains($decoded, '..') || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F]/', $decoded) === 1 ? null : $value;
    }

    /**
     * @throws BmcException when the value is not a path the driver may call.
     */
    private function requirePath(mixed $value, string $message): string
    {
        return $this->path($value) ?? throw BmcException::refused($message);
    }

    /**
     * The known reset types in a list from the BMC, or null when it sent no list.
     *
     * @return list<string>|null
     */
    private function resetTypes(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        return array_values(array_intersect(self::RESET_TYPES, array_filter($values, 'is_string')));
    }

    /**
     * @return list<string>
     */
    private function savedResetTypes(Machine $machine): array
    {
        return array_values(array_filter((array) $machine->bmc_reset_types, 'is_string'));
    }

    /**
     * A text from the BMC made safe to keep and show: no tags, no line breaks, no login, cut short.
     */
    private function text(Machine $machine, mixed $value, int $limit): string
    {
        if (! is_string($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
        $username = (string) $machine->bmc_username;
        $password = (string) $machine->bmc_password;
        $secrets = [$username, $password, $username.':'.$password];

        foreach (array_filter($secrets, fn (string $secret): bool => strlen($secret) >= 3) as $secret) {
            $text = str_ireplace([$secret, rawurlencode($secret), urlencode($secret), base64_encode($secret)], '[hidden]', $text);
        }

        return Str::limit(trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $text)), $limit, '…');
    }
}
