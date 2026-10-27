<?php

namespace App\Provisioning\Hardware;

use App\Enums\BootDevice;
use App\Enums\MachineStatus;
use App\Enums\PowerAction;
use App\Enums\PowerState;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Machine;
use App\Models\MachinePowerEvent;
use App\Models\Service;
use App\Provisioning\Hardware\Drivers\ChoosesBootDevice;
use App\Provisioning\Hardware\Drivers\PowerDriver;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\Drivers\ReadsHealth;
use App\Provisioning\Hardware\Drivers\ReadsPowerUsage;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PeerCertificate;
use App\Support\Activity;
use App\Support\Locales;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The only way to a machine's BMC. Staff pages, the dedicated server module (for clients), add-ons
 * and the scheduler all come through here, so every power action takes the machine's power lock and
 * cool-down, power states and health are cached for a while, and every action is in the activity
 * log and in the machine's power history (MachinePowerEvent), whichever driver ran it.
 *
 * Core has no driver of its own (see PowerDrivers). Without one every machine has no power control:
 * nothing here calls a BMC, and staff are reminded to switch machines by hand.
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

    /** Minutes a failing power check waits longer for each BMC failure in a row after the first. */
    public const RETRY_STEP_MINUTES = 5;

    /** Minutes a failing power check waits at most. */
    public const RETRY_MAX_MINUTES = 60;

    /** Seconds a health reading is reused unless staff ask for a fresh one. */
    public const HEALTH_SECONDS = 600;

    /** A saved power state that did not change is written again after this many seconds. */
    private const SAVE_STATE_AFTER_SECONDS = 300;

    private const STATE_KEY = 'nuvabill:machine:power:';

    private const LOCK_KEY = 'nuvabill:machine:power-lock:';

    private const COOLDOWN_KEY = 'nuvabill:machine:power-cooldown:';

    private const ALERT_KEY = 'nuvabill:machine:alert:';

    private const HEALTH_KEY = 'nuvabill:machine:health:';

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
            $driver === null && $machine->hasBmc() => BmcTestResult::failed(self::needsAddonMessage()),
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
     * Whether Nuvabill can reach the machine's BMC through an installed, usable driver.
     */
    public function hasControl(Machine $machine): bool
    {
        return $this->drivers->for($machine)?->available() === true;
    }

    /**
     * What staff read when power control needs the Dedicated Server Power add-on.
     */
    public static function needsAddonMessage(): string
    {
        return __('Automatic power control needs the Dedicated Server Power add-on. Until then, switch this machine on and off by hand.');
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
     * The boot devices the machine's BMC can choose for its next boot (ChoosesBootDevice).
     *
     * @return list<BootDevice>
     */
    public function bootDevices(Machine $machine): array
    {
        $driver = $this->drivers->for($machine);

        if (! $driver instanceof ChoosesBootDevice || ! $driver->available()) {
            return [];
        }

        try {
            return array_values(array_filter($driver->bootDevices($machine), fn (mixed $device): bool => $device instanceof BootDevice));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Start the machine from this device on its next boot only, for a client, staff or an add-on
     * (such as an automatic install that boots from the network). It does not restart the machine.
     * With a service, the machine must belong to it. It takes the machine's power lock and is
     * written to the power history.
     *
     * @param  Model  $actor  The client or staff member who asked, or the machine itself for automatic work.
     */
    public function setBootDevice(Machine $machine, BootDevice $device, Model $actor, ?Service $service = null): PowerResult
    {
        if ($service !== null && (int) $machine->service_id !== (int) $service->getKey()) {
            return PowerResult::failed(__('This server is not ready yet.'));
        }

        $driver = $this->drivers->for($machine);

        if (! $driver instanceof ChoosesBootDevice || ! $driver->available() || ! in_array($device, $this->bootDevices($machine), true)) {
            return PowerResult::failed(__('Not supported by this server.'));
        }

        $lock = Cache::lock(self::LOCK_KEY.$machine->getKey(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return PowerResult::failed(__('Another power action is running. Try again in a minute.'), busy: true);
        }

        $service ??= $machine->service;
        $client = $actor instanceof Client ? $actor : $service?->client;
        $action = MachinePowerEvent::BOOT_PREFIX.$device->value;
        $on = $machine->name.($service !== null ? " (service #{$service->getKey()})" : '');

        try {
            $driver->setBootDevice($machine, $device);
        } catch (Throwable $exception) {
            $failure = $exception instanceof BmcException ? $exception : null;

            if ($failure === null) {
                report(new RuntimeException("Boot device of machine #{$machine->getKey()} failed: ".$exception::class));
            }

            $message = $failure?->getMessage() ?? __('We could not set the boot device. Try again later.');
            $this->record($machine, $driver, $service, $actor, $action, MachinePowerEvent::RESULT_FAILED, $message);
            Activity::log('machine.boot_device_failed', self::who($actor)." next boot device \"{$device->value}\" on {$on} failed: {$message}", $machine, $client, $actor);

            return PowerResult::failed($actor instanceof Client ? self::clientMessage($failure) : $message);
        } finally {
            $lock->release();
        }

        $this->record($machine, $driver, $service, $actor, $action, MachinePowerEvent::RESULT_OK, null);
        Activity::log('machine.boot_device', self::who($actor)." next boot device \"{$device->value}\" on {$on}: accepted", $machine, $client, $actor);

        return PowerResult::done(__('The server starts from :device on its next boot.', ['device' => $device->label()]));
    }

    /**
     * The machine's hardware health and power use (ReadsHealth, ReadsPowerUsage), reused for
     * HEALTH_SECONDS unless $fresh. Null when its driver can read neither. A BMC that fails gives a
     * report with the error. Only staff see health, never clients.
     */
    public function health(Machine $machine, bool $fresh = false): ?HealthReport
    {
        if (! $this->readsHealth($machine)) {
            return null;
        }

        if (! $fresh && ($saved = $this->lastHealth($machine)) !== null) {
            return $saved;
        }

        $driver = $this->drivers->for($machine);
        $readings = [];
        $watts = null;
        $error = null;

        try {
            if ($driver instanceof ReadsHealth) {
                $readings = array_values(array_filter(array_slice($driver->health($machine), 0, 50), fn (mixed $reading): bool => $reading instanceof HealthReading));
            }

            if ($driver instanceof ReadsPowerUsage) {
                $used = $driver->powerUsage($machine);
                $watts = is_int($used) && $used >= 0 && $used < 100_000 ? $used : null;
            }
        } catch (BmcException $exception) {
            $error = Str::limit($exception->getMessage(), 250, '…');
        } catch (Throwable $exception) {
            report(new RuntimeException("Health of machine #{$machine->getKey()} failed: ".$exception::class));
            $error = __('We could not read the health of this server. Try again later.');
        }

        $report = new HealthReport($readings, $watts, now(), $error);
        Cache::put(self::HEALTH_KEY.$machine->getKey(), $report->toArray(), self::HEALTH_SECONDS);

        return $report;
    }

    /**
     * The last health report read, without asking the BMC.
     */
    public function lastHealth(Machine $machine): ?HealthReport
    {
        $saved = Cache::get(self::HEALTH_KEY.$machine->getKey());

        return is_array($saved) ? HealthReport::fromArray($saved) : null;
    }

    /**
     * Whether the machine's driver can read its health or power use.
     */
    public function readsHealth(Machine $machine): bool
    {
        $driver = $this->drivers->for($machine);

        return $driver !== null && $driver->available() && ($driver instanceof ReadsHealth || $driver instanceof ReadsPowerUsage);
    }

    /**
     * Email staff to switch a machine by hand because Nuvabill cannot, written in the site's
     * language. A reminder to switch it off also stops the scheduler's own reminder for a day, so
     * staff get one email.
     *
     * @param  Closure(): string  $message
     */
    public function remindByHand(Machine $machine, PowerAction $wanted, Closure $message): void
    {
        [$title, $text] = Locales::in(Locales::default(), fn (): array => [
            $wanted === PowerAction::On ? __('Switch it on by hand') : __('Switch it off by hand'),
            $message(),
        ]);

        if ($wanted !== PowerAction::On) {
            Cache::put(self::ALERT_KEY.'manual:'.$machine->getKey(), true, 86400);
        }

        $this->alert($machine, $title, $text);
        Activity::log('machine.power_by_hand', "Staff asked to switch {$machine->name} ".($wanted === PowerAction::On ? 'on' : 'off').' by hand: it has no power control', $machine);
    }

    /**
     * The scheduler's power check: machines whose power_check_at is due are switched off when they
     * should be off. A suspended machine (of a suspended service) is checked again every hour, as
     * a power cut or a BIOS setting can switch it on again; a machine waiting for a wipe is
     * switched off once. A check that cannot finish (a BMC that fails, say) is tried again on a
     * later run, behind the other due machines, so broken BMCs never block the healthy ones.
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
        $who = self::who($actor);
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
                $this->record($machine, $driver, $service, $actor, $action->value, MachinePowerEvent::RESULT_ALREADY, null);
                Activity::log('machine.power', "{$who} power action \"{$action->value}\" on {$on}: the server was already ".($isOn ? 'on' : 'off'), $machine, $client, $actor);

                return PowerResult::done($isOn ? __('The server is already on.') : __('The server is already off.'), null, $isOn ? PowerState::On : PowerState::Off);
            }

            $message = $failure?->getMessage() ?? __('We could not switch the power. Try again later.');
            $machine->forceFill(['bmc_last_error' => Str::limit($message, 250, '…')])->save();
            $this->record($machine, $driver, $service, $actor, $action->value, MachinePowerEvent::RESULT_FAILED, $message);
            Activity::log('machine.power_failed', "{$who} power action \"{$action->value}\" on {$on} failed: {$message}", $machine, $client, $actor);

            return PowerResult::failed($actor instanceof Client ? self::clientMessage($failure) : $message);
        }

        Cache::forget(self::STATE_KEY.$id);
        $this->record($machine, $driver, $service, $actor, $action->value, MachinePowerEvent::RESULT_OK, null);
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
     * Write one line of the machine's power history. A history that cannot be written never stops
     * the action, which already happened.
     */
    private function record(Machine $machine, PowerDriver $driver, ?Service $service, ?Model $actor, string $action, string $result, ?string $message): void
    {
        $person = $actor instanceof Client || $actor instanceof Admin ? $actor : null;

        rescue(fn () => MachinePowerEvent::query()->forceCreate([
            'machine_id' => $machine->getKey(),
            'service_id' => $service?->getKey(),
            'actor_type' => $person?->getMorphClass(),
            'actor_id' => $person?->getKey(),
            'actor_kind' => match (true) {
                $actor instanceof Client => 'client',
                $actor instanceof Admin => 'staff',
                default => 'automatic',
            },
            'action' => Str::limit($action, 32, ''),
            'result' => $result,
            'message' => $message === null ? null : Str::limit($message, 250, '…'),
            'driver' => Str::limit($driver->type(), 40, ''),
            'created_at' => now(),
        ]));
    }

    /**
     * Who asked, for the activity log, which stays in English.
     */
    private static function who(?Model $actor): string
    {
        return match (true) {
            $actor instanceof Client => 'Client',
            $actor instanceof Admin => 'Staff',
            default => 'Automatic',
        };
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
            [$wiping, $suspended] = $this->mustBeOff($machine);

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
                } elseif ($wiping && Cache::add(self::ALERT_KEY.'manual:'.$machine->getKey(), true, 86400)) {
                    $this->alert($machine, __('Switch it off by hand'), __(':machine waits for a wipe, but Nuvabill cannot switch it off because it has no power control. Switch it off by hand, then erase its disks and press Confirm wipe.', [
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

            // Unknown (the BMC failed) or changing: tried again on a later run.
            if ($state !== PowerState::On) {
                $this->retryLater($machine);

                return;
            }

            // The state read can take seconds, and a payment may have unsuspended the service
            // meanwhile (that change does not wait for the power lock): read both again.
            [$wiping, $suspended] = $this->mustBeOff($machine);

            if (! $wiping && ! $suspended) {
                return;
            }

            $result = $this->send($machine, $driver, PowerAction::Off, null, $machine->service);

            if ($result->ok) {
                // A suspended machine stays due, so the next run checks that it is really off.
                if ($wiping) {
                    $machine->forceFill(['power_check_at' => null])->save();
                }

                return;
            }

            $this->retryLater($machine);

            if ($suspended && Cache::add(self::ALERT_KEY.'still-on:'.$machine->getKey(), true, 86400)) {
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

    /**
     * Read the machine and its service again. Returns whether the machine waits for a wipe, and
     * whether it and its service are suspended: either means it must be off.
     *
     * @return array{0: bool, 1: bool}
     */
    private function mustBeOff(Machine $machine): array
    {
        $machine->refresh()->load('service');

        return [
            $machine->status === MachineStatus::Wiping,
            $machine->status === MachineStatus::Suspended && $machine->service?->status === ServiceStatus::Suspended,
        ];
    }

    /**
     * A power check that could not finish (the BMC failed, the server was turning on or off, or
     * the power off did not work) moves behind the other due machines, so a few broken BMCs never
     * fill every run. It is tried again on the next run; while the BMC keeps failing, the wait
     * grows by RETRY_STEP_MINUTES per failure in a row, up to RETRY_MAX_MINUTES.
     */
    private function retryLater(Machine $machine): void
    {
        $minutes = min(self::RETRY_MAX_MINUTES, self::RETRY_STEP_MINUTES * max(0, (int) $machine->bmc_failures - 1));

        $machine->forceFill(['power_check_at' => now()->addMinutes($minutes)])->save();
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
