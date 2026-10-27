<?php

namespace App\Provisioning\Hardware;

use App\Models\Machine;
use App\Models\Service;
use Throwable;

/**
 * An add-on that takes over the "Setting up" step of a dedicated server: it installs the system by
 * itself (for example from the network) instead of staff doing it by hand. Register one in the
 * add-on's boot(): app(MachineInstallers::class)->register(new MyInstaller).
 *
 * When a paid machine moves to "Setting up", core asks each installer in turn; the first that
 * takes() it gets start(), and staff get no "install a dedicated server" email. When the install is
 * done, the add-on calls ServerSetup::ready() — the same code path as the "Server is ready" button,
 * so the host name, login name and password are handled the same way (the password is saved
 * encrypted on the service and never emailed). When it cannot finish, the add-on calls
 * ServerSetup::failed(), and staff are asked to install by hand. Staff can always press "Server is
 * ready" themselves.
 *
 * To hear about a machine that is released while it installs (an order cancelled, say), listen
 * for DedicatedServerReleased.
 */
interface MachineInstaller
{
    /**
     * A short key, usually the add-on's slug. It is saved on the machine (machines.setup_by).
     */
    public function key(): string;

    /**
     * The name staff see, already translated, such as "Automatic OS Install".
     */
    public function label(): string;

    /**
     * Whether this installer sets up this machine for this service, from the database only.
     */
    public function takes(Machine $machine, Service $service): bool;

    /**
     * Start the install. Runs after the machine moved to "Setting up" and its transaction is
     * saved. Queue long work; throw to give the machine back to staff at once.
     *
     * @throws Throwable
     */
    public function start(Machine $machine, Service $service): void;
}
