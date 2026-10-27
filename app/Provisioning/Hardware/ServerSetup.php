<?php

namespace App\Provisioning\Hardware;

use App\Enums\MachineStatus;
use App\Extensions\Servers\ModuleResult;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Machine;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Locales;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The "Setting up" step of a dedicated server: who installs the system, and "Server is ready".
 *
 * When a paid machine moves to "Setting up", begin() hands it to an add-on installer that takes it
 * (MachineInstaller), or emails staff to install it by hand. Both end in ready(), the one code path
 * of the "Server is ready" button and of installers: it checks the host name, login name and
 * password, saves them on the service (the password encrypted, never emailed or logged) and sets
 * the service up, which emails the client.
 */
final class ServerSetup
{
    /** A host name such as server1.example.com: letters, numbers and dashes, at most 190 characters. */
    public const HOSTNAME_PATTERN = '/^(?=.{1,190}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.?$/';

    /** A login name such as root or admin. */
    public const LOGIN_PATTERN = '/^[a-z_][a-z0-9_-]{0,31}$/i';

    public const PASSWORD_MIN = 12;

    public const PASSWORD_MAX = 190;

    public function __construct(
        private readonly MachineInstallers $installers,
        private readonly TemplateMailer $mailer,
    ) {}

    /**
     * The machine moved to "Setting up" for the service (its transaction is saved). An installer
     * that takes it starts the install; otherwise staff get the "install a dedicated server" email.
     */
    public function begin(Machine $machine, Service $service): void
    {
        $installer = $this->installers->for($machine, $service);

        if ($installer === null) {
            $this->askStaff($machine, $service);

            return;
        }

        $machine->forceFill(['setup_by' => $installer->key(), 'setup_started_at' => now(), 'setup_error' => null])->save();
        Activity::log('machine.auto_install', "Automatic install of {$machine->name} (service #{$service->getKey()}) started by {$installer->key()}", $machine, $service->client);

        try {
            $installer->start($machine, $service);
        } catch (Throwable $exception) {
            report(new RuntimeException("The installer {$installer->key()} could not start on machine #{$machine->getKey()}: ".$exception::class));
            $this->failed($service, __('The install could not start.'));
        }
    }

    /**
     * The system is installed: save the login details and set the service up. Staff pressing
     * "Server is ready" and installers come through here.
     *
     * @param  ?string  $hostname  Null keeps the service's host name.
     * @param  ?string  $password  The root password the client sees in the client area, or null.
     * @param  ?Admin  $admin  The staff member who pressed the button; null for an installer.
     * @param  string  $by  For the activity log when no staff member did it, such as the installer's key.
     *
     * @throws InventoryException when a value is not allowed or the service is not waiting for this.
     */
    public function ready(Service $service, ?string $hostname, string $username, ?string $password, ?Admin $admin = null, string $by = ''): ModuleResult
    {
        $hostname = filled($hostname) ? trim((string) $hostname) : null;
        $username = trim($username);

        if ($hostname !== null && preg_match(self::HOSTNAME_PATTERN, $hostname) !== 1) {
            throw new InventoryException(__('Enter a host name such as server1.example.com.'));
        }

        if (preg_match(self::LOGIN_PATTERN, $username) !== 1) {
            throw new InventoryException(__('Use a login name such as root or admin: letters, numbers, _ and -, starting with a letter or _.'));
        }

        if ($password !== null && $password !== '' && (mb_strlen($password) < self::PASSWORD_MIN || mb_strlen($password) > self::PASSWORD_MAX)) {
            throw new InventoryException(__('The root password must be :min to :max characters long.', ['min' => self::PASSWORD_MIN, 'max' => self::PASSWORD_MAX]));
        }

        app(MachineInventory::class)->markInstalled($service, $hostname === null ? null : rtrim($hostname, '.'), $username, $password === '' ? null : $password, $admin, $by);

        // The module activates the service now; it sends the client the ready email.
        return app(Provisioner::class)->create($service->fresh() ?? $service);
    }

    /**
     * An installer could not finish: staff are asked to install the system by hand. The reason is
     * shown to staff, so it must hold no password; it is cleaned and cut short here.
     */
    public function failed(Service $service, string $reason): void
    {
        $machine = app(MachineInventory::class)->machineFor($service);

        if ($machine === null || $machine->status !== MachineStatus::Provisioning || $machine->provisioned_at !== null) {
            return;
        }

        $reason = Str::limit(trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', strip_tags($reason))), 250, '…');
        $machine->forceFill(['setup_error' => $reason !== '' ? $reason : __('The install did not finish.')])->save();
        Activity::log('machine.auto_install_failed', "Automatic install of {$machine->name} (service #{$service->getKey()}) failed: {$machine->setup_error}", $machine, $service->client);

        [$title, $message] = Locales::in(Locales::default(), fn (): array => [
            __('The automatic install failed'),
            __('The automatic install of service #:service on :machine did not finish: :reason Install the system by hand, then press Server is ready.', [
                'service' => $service->getKey(),
                'machine' => $machine->name,
                'reason' => $machine->setup_error,
            ]),
        ]);

        rescue(fn () => $this->mailer->sendToStaff('admin.machine_alert', [
            'machine' => ['name' => $machine->name, 'location' => (string) $machine->location],
            'alert' => ['title' => $title, 'message' => $message],
            'admin_url' => route('admin.services.show', $service),
        ]));
    }

    /**
     * The "install a dedicated server" email to staff.
     */
    private function askStaff(Machine $machine, Service $service): void
    {
        $service->loadMissing(['client', 'product']);

        rescue(fn () => $this->mailer->sendToStaff('admin.machine_install', [
            'client' => TemplateMailer::clientContext($service->client),
            'service' => ['id' => $service->getKey(), 'product' => $service->product->name],
            'machine' => [
                'name' => $machine->name,
                'location' => (string) $machine->location,
                'rack' => (string) $machine->rack,
                'rack_unit' => (string) $machine->rack_unit,
                'default_os' => (string) ($service->product->module_config['default_os'] ?? ''),
            ],
            'admin_url' => route('admin.services.show', $service),
        ]));
    }
}
