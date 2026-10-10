<?php

namespace App\Provisioning\Hardware;

use App\Enums\MachineStatus;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Service;
use App\Provisioning\Hardware\Drivers\PowerDriver;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PeerCertificate;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The only way to a machine's BMC. Staff pages, the dedicated server module (for clients) and the
 * scheduler all come through here, so every power action takes the machine's power lock and
 * cool-down, power states are cached for a few seconds, and every action is in the activity log.
 *
 * Messages are short and plain. A client never sees BMC details: their failures get a general
 * message, while staff get the cleaned BMC reason.
 */
final class PowerControl
{
    /** Seconds a power state is reused, so polling pages do not flood the BMC. */
    public const CACHE_SECONDS = 15;

    /** Seconds after a power action before the next one on the same machine. */
    public const COOLDOWN_SECONDS = 20;

    /** Seconds the power lock lasts at most (one BMC call takes at most 25 seconds). */
    public const LOCK_SECONDS = 60;

    /** BMC failures in a row before staff get an alert (at most once a day per machine). */
    public const ALERT_AFTER_FAILURES = 3;

    /** A saved power state that did not change is written again after this many seconds. */
    private const SAVE_STATE_AFTER_SECONDS = 300;

    private const STATE_KEY = 'nuvabill:machine:power:';

    private const LOCK_KEY = 'nuvabill:machine:power-lock:';

    private const COOLDOWN_KEY = 'nuvabill:machine:power-cooldown:';

    private const ALERT_KEY = 'nuvabill:machine:alert:';

    public function __construct(
        private readonly PowerDrivers $drivers,
        private readonly CertificateProbe $probe,
        private readonly TemplateMailer $mailer,
    ) {}

    /**
     * Test the BMC connection and save what it found (system, reset types, vendor and model).
     * A test that needs the certificate trusted first saves nothing.
     */
    public function test(Machine $machine): BmcTestResult
    {
        $driver = $this->drivers->for($machine);

        $result = match (true) {
            $driver === null => BmcTestResult::failed(__('Choose a power control type and enter the BMC address first.')),
            ! $driver->available() => BmcTestResult::failed(__('This power control type is not available on this site.')),
            default => $this->runTest($driver, $machine),
        };

        if ($driver !== null && ! $result->needsTrust) {
            $this->saveTest($machine, $result);
        }

        Cache::forget(self::STATE_KEY.$machine->getKey());

        $outcome = match (true) {
            $result->needsTrust => 'the certificate must be trusted first',
            $result->needsSystemChoice() => 'staff must choose one of '.count($result->systems).' systems',
            $result->ok => 'connected'.(($name = trim($result->vendor.' '.$result->model)) !== '' ? " ({$name})" : ''),
            default => 'failed: '.$result->message,
        };
        Activity::log('machine.bmc_tested', "BMC test for {$machine->name}: {$outcome}", $machine);

        return $result;
    }

    /**
     * Trust the certificate the BMC shows now, so its login may be sent to it. The certificate is
     * read again here and must be the one staff saw in the test ($fingerprint) and, when they
     * pasted one from the BMC's own page, that one too.
     *
     * @throws BmcException when it does not match or the BMC cannot be reached.
     */
    public function trust(Machine $machine, string $fingerprint, ?string $pastedFingerprint, Admin $admin): void
    {
        if ($machine->bmc_type === 'ipmi') {
            throw BmcException::unsupported(__('IPMI has no certificate to trust.'));
        }

        if ($machine->bmc_tls_mode === 'verified') {
            throw BmcException::refused(__('This machine checks its BMC certificate with a trusted CA, so there is nothing to trust.'));
        }

        if (PeerCertificate::normalizeFingerprint($fingerprint) === null) {
            throw BmcException::refused(__('Run Test connection again, then trust the certificate it shows.'));
        }

        $pasted = trim((string) $pastedFingerprint);

        if ($pasted !== '' && PeerCertificate::normalizeFingerprint($pasted) === null) {
            throw BmcException::refused(__('The fingerprint you pasted is not a SHA-256 fingerprint.'));
        }

        $address = BmcAddress::for($machine, 443);
        $seen = $this->probe->fetch($address->ip, $address->port, $address->host);

        if (! PeerCertificate::sameFingerprint($seen->fingerprint, $fingerprint)) {
            throw BmcException::refused(__('The BMC now shows another certificate. Run Test connection again and check it.'));
        }

        if ($pasted !== '' && ! PeerCertificate::sameFingerprint($seen->fingerprint, $pasted)) {
            throw BmcException::refused(__('The fingerprint you pasted does not match the BMC certificate.'));
        }

        $machine->forceFill([
            'bmc_tls_pin' => $seen->pin,
            'bmc_tls_fingerprint' => $seen->fingerprint,
            'bmc_failures' => 0,
            'bmc_last_error' => null,
        ])->save();

        Cache::forget(self::STATE_KEY.$machine->getKey());
        Activity::log('machine.tls_trusted', "BMC certificate of {$machine->name} trusted (SHA-256 ".substr($seen->fingerprint, 0, 23).'…)', $machine, null, $admin);
    }

    /**
     * The machine's power state, reused for CACHE_SECONDS unless $fresh. A BMC that does not
     * answer gives Unknown, counts as a failure, and after ALERT_AFTER_FAILURES in a row staff get
     * an email (once a day).
     */
    public function state(Machine $machine, bool $fresh = false): PowerState
    {
        $driver = $this->drivers->for($machine);

        if ($driver === null || ! $driver->available()) {
            return PowerState::Unknown;
        }

        $key = self::STATE_KEY.$machine->getKey();

        if ($fresh) {
            Cache::forget($key);
        }

        $cached = Cache::get($key);

        if (is_string($cached) && ($state = PowerState::tryFrom($cached)) !== null) {
            return $state;
        }

        $state = $this->readState($machine, $driver);
        Cache::put($key, $state->value, self::CACHE_SECONDS);

        return $state;
    }

    /**
     * The power actions the machine's BMC offers.
     *
     * @return list<PowerAction>
     */
    public function actions(Machine $machine): array
    {
        $driver = $this->drivers->for($machine);

        if ($driver === null || ! $driver->available()) {
            return [];
        }

        try {
            return $driver->supportedActions($machine);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Run a power action for a client or a staff member. With a service, the machine must belong
     * to it. One action per machine at a time, with a short pause between actions.
     *
     * @param  Model  $actor  The client or staff member who asked.
     */
    public function run(Machine $machine, PowerAction $action, Model $actor, ?Service $service = null): PowerResult
    {
        if ($service !== null && (int) $machine->service_id !== (int) $service->getKey()) {
            return PowerResult::failed(__('This server is not ready yet.'));
        }

        $driver = $this->drivers->for($machine);

        if ($driver === null || ! $driver->available()) {
            return PowerResult::failed(__('This server has no power control.'));
        }

        if (! in_array($action, $this->actions($machine), true)) {
            return PowerResult::failed(__('Not supported by this server.'));
        }

        $lock = Cache::lock(self::LOCK_KEY.$machine->getKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return PowerResult::failed(__('Another power action is running. Try again in a minute.'), busy: true);
        }

        try {
            if (Cache::has(self::COOLDOWN_KEY.$machine->getKey())) {
                return PowerResult::failed(__('Wait a few seconds before the next power action.'), busy: true);
            }

            return $this->send($machine, $driver, $action, $actor, $service);
        } finally {
            $lock->release();
        }
    }

    /**
     * The scheduler's power check: machines whose power_check_at is due are switched off when they
     * should be off. A suspended machine (of a suspended service) is checked again every hour, as
     * a power cut or a BIOS setting can switch it on again; a machine waiting for a wipe is
     * switched off once. A BMC that fails keeps the check due, so the next run tries again.
     *
     * Bounded: at most $limit machines, and no new one after $budgetSeconds. Returns how many were
     * handled.
     */
    public function enforce(int $limit = 20, int $budgetSeconds = 120): int
    {
        $started = hrtime(true);
        $handled = 0;

        $machines = Machine::query()
            ->whereNotNull('power_check_at')
            ->where('power_check_at', '<=', now())
            ->orderBy('power_check_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($machines as $machine) {
            if ((hrtime(true) - $started) / 1_000_000_000 >= $budgetSeconds) {
                break;
            }

            try {
                $this->enforceOne($machine);
            } catch (Throwable $exception) {
                report(new RuntimeException("Power check of machine #{$machine->getKey()} failed: ".$exception::class));
            }

            $handled++;
        }

        return $handled;
    }

    private function runTest(PowerDriver $driver, Machine $machine): BmcTestResult
    {
        try {
            return $driver->test($machine);
        } catch (BmcException $exception) {
            return BmcTestResult::failed($exception->getMessage());
        } catch (Throwable $exception) {
            report(new RuntimeException("BMC test of machine #{$machine->getKey()} failed: ".$exception::class));

            return BmcTestResult::failed(__('The test could not run. Try again later.'));
        }
    }

    private function saveTest(Machine $machine, BmcTestResult $result): void
    {
        if (! $result->ok) {
            $machine->forceFill(['bmc_checked_at' => now(), 'bmc_last_error' => Str::limit($result->message, 250, '…')])->save();

            return;
        }

        $known = $result->powerState !== PowerState::Unknown;

        // Without a chosen system there are no power actions until staff pick one.
        $machine->forceFill([
            'bmc_system_path' => $result->systemPath,
            'bmc_reset_target' => $result->systemPath === null ? null : $result->resetTarget,
            'bmc_reset_types' => $result->systemPath === null ? null : $result->resetTypes,
            'bmc_vendor' => $result->vendor,
            'bmc_model' => $result->model,
            'bmc_checked_at' => now(),
            'bmc_failures' => 0,
            'bmc_last_error' => null,
            'power_state' => $known ? $result->powerState->value : $machine->power_state,
            'power_checked_at' => $known ? now() : $machine->power_checked_at,
        ])->save();
    }

    private function readState(Machine $machine, PowerDriver $driver): PowerState
    {
        try {
            $state = $driver->powerState($machine);
        } catch (BmcException $exception) {
            $this->recordFailure($machine, $exception->getMessage());

            return PowerState::Unknown;
        } catch (Throwable $exception) {
            report(new RuntimeException("Power state of machine #{$machine->getKey()} failed: ".$exception::class));
            $this->recordFailure($machine, __('We could not read the power state.'));

            return PowerState::Unknown;
        }

        $changes = [];
        $checkedAt = $machine->power_checked_at;

        if ($machine->power_state !== $state->value || $checkedAt === null || $checkedAt->lt(now()->subSeconds(self::SAVE_STATE_AFTER_SECONDS))) {
            $changes = ['power_state' => $state->value, 'power_checked_at' => now()];
        }

        if ((int) $machine->bmc_failures > 0 || $machine->bmc_last_error !== null) {
            $changes += ['bmc_failures' => 0, 'bmc_last_error' => null];
        }

        if ($changes !== []) {
            $machine->forceFill($changes)->save();
        }

        return $state;
    }

    private function recordFailure(Machine $machine, string $message): void
    {
        $error = Str::limit($message, 250, '…');
        Machine::query()->whereKey($machine->getKey())->increment('bmc_failures', 1, ['bmc_last_error' => $error]);
        $failures = (int) Machine::query()->whereKey($machine->getKey())->value('bmc_failures');
        $machine->forceFill(['bmc_failures' => $failures, 'bmc_last_error' => $error])->syncOriginalAttributes(['bmc_failures', 'bmc_last_error']);

        if ($failures >= self::ALERT_AFTER_FAILURES && Cache::add(self::ALERT_KEY.'bmc:'.$machine->getKey(), true, 86400)) {
            $this->alert($machine, __('The BMC does not answer'), __('The BMC of :machine failed :count times in a row, so its power cannot be checked or switched. Last error: :error', [
                'machine' => $machine->name,
                'count' => $failures,
                'error' => $error,
            ]));
        }
    }

    /**
     * Send the action to the BMC (the caller holds the power lock) and log it.
     */
    private function send(Machine $machine, PowerDriver $driver, PowerAction $action, ?Model $actor, ?Service $service): PowerResult
    {
        $id = $machine->getKey();
        $service ??= $machine->service;
        $client = $actor instanceof Client ? $actor : $service?->client;
        $who = match (true) {
            $actor instanceof Client => 'Client',
            $actor instanceof Admin => 'Staff',
            default => 'Automatic',
        };
        $on = $machine->name.($service !== null ? " (service #{$service->getKey()})" : '');

        Cache::put(self::COOLDOWN_KEY.$id, true, self::COOLDOWN_SECONDS);

        try {
            $driver->run($machine, $action);
        } catch (Throwable $exception) {
            Cache::forget(self::STATE_KEY.$id);
            $failure = $exception instanceof BmcException ? $exception : null;

            if ($failure === null) {
                report(new RuntimeException("Power action on machine #{$id} failed: ".$exception::class));
            }

            if ($failure?->reason === BmcException::CONFLICT && $action !== PowerAction::Restart && $action !== PowerAction::Reset) {
                $isOn = $action === PowerAction::On;
                Activity::log('machine.power', "{$who} power action \"{$action->value}\" on {$on}: the server was already ".($isOn ? 'on' : 'off'), $machine, $client, $actor);

                return PowerResult::done($isOn ? __('The server is already on.') : __('The server is already off.'), null, $isOn ? PowerState::On : PowerState::Off);
            }

            $message = $failure?->getMessage() ?? __('We could not switch the power. Try again later.');
            $machine->forceFill(['bmc_last_error' => Str::limit($message, 250, '…')])->save();
            Activity::log('machine.power_failed', "{$who} power action \"{$action->value}\" on {$on} failed: {$message}", $machine, $client, $actor);

            return PowerResult::failed($actor instanceof Client ? self::clientMessage($failure) : $message);
        }

        Cache::forget(self::STATE_KEY.$id);
        Activity::log('machine.power', "{$who} power action \"{$action->value}\" on {$on}: accepted", $machine, $client, $actor);

        return PowerResult::done(match ($action) {
            PowerAction::On => __('The server is starting.'),
            PowerAction::Shutdown => __('A shutdown signal was sent. The server turns off when its system has shut down.'),
            PowerAction::Restart => __('The server is restarting.'),
            PowerAction::Reset => __('The server was reset.'),
            PowerAction::Off => __('The server is powered off.'),
        }, $action, PowerState::Changing);
    }

    /**
     * What a client sees when a power action fails: no BMC details.
     */
    private static function clientMessage(?BmcException $failure): string
    {
        return match ($failure?->reason) {
            BmcException::UNREACHABLE => __("The server's management port does not answer. Try again later or open a ticket."),
            BmcException::UNSUPPORTED => __('Not supported by this server.'),
            default => __('We could not switch the power right now. Try again later or open a ticket.'),
        };
    }

    /**
     * One due machine of enforce(), under its power lock. A busy machine waits for the next run.
     */
    private function enforceOne(Machine $machine): void
    {
        $lock = Cache::lock(self::LOCK_KEY.$machine->getKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $machine->refresh()->load('service');
            $wiping = $machine->status === MachineStatus::Wiping;
            $suspended = $machine->status === MachineStatus::Suspended && $machine->service?->status === ServiceStatus::Suspended;

            if (! $wiping && ! $suspended) {
                $machine->forceFill(['power_check_at' => null])->save();

                return;
            }

            $driver = $this->drivers->for($machine);

            if ($driver === null || ! $driver->available() || ! in_array(PowerAction::Off, $this->actions($machine), true)) {
                $machine->forceFill(['power_check_at' => null])->save();

                if ($suspended && Cache::add(self::ALERT_KEY.'manual:'.$machine->getKey(), true, 86400)) {
                    $this->alert($machine, __('Switch it off by hand'), __('Service #:service is suspended, but Nuvabill cannot switch :machine off because it has no power control. Switch it off by hand or block its network port.', [
                        'service' => $machine->service_id,
                        'machine' => $machine->name,
                    ]));
                }

                return;
            }

            $state = $this->state($machine, fresh: true);

            if ($state === PowerState::Off) {
                $machine->forceFill(['power_check_at' => $suspended ? now()->addHour() : null])->save();

                return;
            }

            // Unknown (the BMC failed) or changing: the check stays due for the next run.
            if ($state !== PowerState::On) {
                return;
            }

            $result = $this->send($machine, $driver, PowerAction::Off, null, $machine->service);

            if ($result->ok && $wiping) {
                $machine->forceFill(['power_check_at' => null])->save();
            } elseif (! $result->ok && $suspended && Cache::add(self::ALERT_KEY.'still-on:'.$machine->getKey(), true, 86400)) {
                $this->alert($machine, __('A suspended server is still on'), __('Service #:service is suspended, but :machine is still on and Nuvabill could not switch it off: :error', [
                    'service' => $machine->service_id,
                    'machine' => $machine->name,
                    'error' => $result->message,
                ]));
            }
        } finally {
            $lock->release();
        }
    }

    private function alert(Machine $machine, string $title, string $message): void
    {
        rescue(fn () => $this->mailer->sendToStaff('admin.machine_alert', [
            'machine' => ['name' => $machine->name, 'location' => (string) $machine->location],
            'alert' => ['title' => $title, 'message' => $message],
            'admin_url' => MachineInventory::adminUrl($machine),
        ]));
    }
}
