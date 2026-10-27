<?php

namespace App\Extensions\Servers;

use App\Contracts\HasAdminPages;
use App\Contracts\HasAdminPermission;
use App\Contracts\HasStaffPanel;
use App\Contracts\RunsOnSchedule;
use App\Contracts\ServerModule;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Extensions\Servers\Staff\ModuleAdmin;
use App\Models\Admin;
use App\Models\Role;
use App\Models\Service;
use App\Support\AttentionItems;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

/**
 * The hooks server modules get since Nuvabill 0.7.0, each only when the module's manifest lists
 * the matching marketplace permission and the module implements the contract:
 *
 * - "admin-page": admin pages (HasAdminPages), dashboard items and a card on the admin service
 *   page (HasStaffPanel), behind the module's own admin permission (HasAdminPermission).
 * - "schedule": scheduled work (RunsOnSchedule), run by the site's one cron job.
 * - "order-fields": fields on the order form (HasOrderFields), see App\Provisioning\OrderFields.
 *
 * A module that fails is reported by class name only (its messages may hold a server address with
 * a key) and left out, so it never breaks a page or the cron run.
 */
final class ModuleHooks
{
    public function __construct(private ExtensionManager $extensions) {}

    /**
     * Installed server modules whose manifest lists the permission and that implement the contract.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return array<string, T&ServerModule>
     */
    public function modulesWith(string $contract, string $permission): array
    {
        $modules = [];

        foreach ($this->extensions->ofType(ExtensionManifest::TYPE_SERVER) as $slug => $manifest) {
            if (! in_array($permission, $manifest->permissions, true)) {
                continue;
            }

            $module = $this->make($slug);

            if ($module instanceof $contract) {
                $modules[$slug] = $module;
            }
        }

        return $modules;
    }

    /**
     * The module's admin permission key when it is valid: the slug, a dot and a short name, and a
     * slug that is not one of core's permission groups (so "billing.manage" can never be claimed).
     */
    public function permissionOf(ServerModule $module): ?string
    {
        if (! $module instanceof HasAdminPermission) {
            return null;
        }

        $slug = $module->slug();
        $key = $this->attempt(fn (): mixed => $module->adminPermission(), "Admin permission of {$slug}");

        if (! is_string($key) || preg_match('/^'.preg_quote($slug, '/').'\.[a-z][a-z0-9-]{0,30}\z/', $key) !== 1 || in_array($slug, self::coreGroups(), true)) {
            if (Cache::add('nuvabill:server-module:bad-permission:'.$slug, true, 86400)) {
                Log::warning("Server module \"{$slug}\" gets no staff pages: its admin permission must be \"{$slug}.\" and a short name, and its slug must not be a core permission group.");
            }

            return null;
        }

        return $key;
    }

    /**
     * The permissions of server modules for Settings → Roles, grouped by module name.
     *
     * @return array<string, array<string, string>>
     */
    public function permissionGroups(): array
    {
        $groups = [];

        foreach ($this->staffModules() as $slug => $module) {
            $key = $this->permissionOf($module);
            $label = $this->attempt(fn (): mixed => $module->adminPermissionLabel(), "Admin permission label of {$slug}");

            if ($key !== null) {
                $groups[$module->name()][$key] = is_string($label) && $label !== '' ? mb_substr($label, 0, 120) : $module->name();
            }
        }

        return $groups;
    }

    /**
     * Whether the staff member may use the module's admin pages and staff card.
     */
    public function allows(Admin $admin, ServerModule $module): bool
    {
        $key = $this->permissionOf($module);

        return $key !== null && $admin->hasPermission($key);
    }

    /**
     * Admin pages of server modules, under /admin/server-modules/{slug}/, each behind the module's
     * own permission. Call inside the admin route group (see routes/admin.php).
     */
    public function registerAdminRoutes(): void
    {
        foreach ($this->modulesWith(HasAdminPages::class, 'admin-page') as $slug => $module) {
            $permission = $this->permissionOf($module);

            if ($permission === null) {
                continue;
            }

            $this->attempt(function () use ($slug, $module, $permission): bool {
                Route::prefix($slug)->name($slug.'.')->middleware('admin.can:'.$permission)->group(fn () => $module->adminRoutes());

                return true;
            }, "Admin pages of {$slug}");
        }
    }

    /**
     * The module pages for the admin menu this staff member may open: name => address.
     *
     * @return array<string, string>
     */
    public function menuPages(Admin $admin): array
    {
        $pages = [];

        foreach ($this->modulesWith(HasAdminPages::class, 'admin-page') as $slug => $module) {
            $route = ModuleAdmin::routeName($slug, 'index');

            if (Route::has($route) && $this->allows($admin, $module)) {
                $pages[$module->name()] = route($route);
            }
        }

        return $pages;
    }

    /**
     * Let modules with admin pages add to the dashboard's "Needs attention" list, for staff with
     * their permission only. Called once when the app boots.
     */
    public function registerAttentionItems(AttentionItems $items): void
    {
        foreach ($this->extensions->ofType(ExtensionManifest::TYPE_SERVER) as $slug => $manifest) {
            if (in_array('admin-page', $manifest->permissions, true)) {
                // One list per module, so AttentionItems keeps at most 5 of each.
                $items->add(fn (Admin $admin): array => app(self::class)->attentionItems((string) $slug, $admin));
            }
        }
    }

    /**
     * The dashboard items of one module, when it has admin pages and the staff member has its
     * permission. AttentionItems checks each item.
     *
     * @return list<mixed>
     */
    public function attentionItems(string $slug, Admin $admin): array
    {
        $module = $this->modulesWith(HasAdminPages::class, 'admin-page')[$slug] ?? null;

        if ($module === null || ! $this->allows($admin, $module)) {
            return [];
        }

        $added = $this->attempt(fn (): mixed => $module->attentionItems($admin), "Attention items of {$slug}");

        return is_array($added) ? array_values($added) : [];
    }

    /**
     * The scheduled work of every module that may run on a schedule. Each task runs at its
     * frequency, never twice at the same time, on one server when the cache can hold locks.
     */
    public function schedule(Schedule $schedule): void
    {
        $oneServer = Cache::getStore() instanceof LockProvider;

        foreach ($this->modulesWith(RunsOnSchedule::class, 'schedule') as $slug => $module) {
            $tasks = $this->attempt(fn (): mixed => $module->scheduledTasks(), "Scheduled tasks of {$slug}");
            $names = [];

            foreach (is_array($tasks) ? $tasks : [] as $task) {
                $problem = $task instanceof ScheduledTask ? $task->problem() : 'It is not a '.ScheduledTask::class.'.';
                $problem ??= isset($names[$task->name]) ? 'Its name is used twice.' : null;

                if ($problem !== null) {
                    if (Cache::add('nuvabill:server-module:bad-task:'.$slug.':'.sha1($problem), true, 86400)) {
                        Log::warning("Server module \"{$slug}\": a scheduled task was left out. {$problem}");
                    }

                    continue;
                }

                $names[$task->name] = true;
                $event = $schedule->call(fn () => $this->attempt($task->callback, "Scheduled task {$slug}:{$task->name}"))
                    ->{ScheduledTask::FREQUENCIES[$task->frequency]}()
                    ->name("server-module:{$slug}:{$task->name}")
                    ->withoutOverlapping($task->lockMinutes);

                if ($oneServer) {
                    $event->onOneServer();
                }
            }
        }
    }

    /**
     * The module of a service when it may show a staff card and the staff member has its
     * permission, or null.
     */
    public function staffModuleFor(Service $service, Admin $admin): ?HasStaffPanel
    {
        $slug = (string) $service->product?->server_module;
        $module = $slug !== '' ? ($this->modulesWith(HasStaffPanel::class, 'admin-page')[$slug] ?? null) : null;

        return $module !== null && $this->allows($admin, $module) ? $module : null;
    }

    /**
     * @return array<string, ServerModule&HasAdminPermission>
     */
    private function staffModules(): array
    {
        return array_filter(
            $this->modulesWith(HasAdminPermission::class, 'admin-page'),
            fn (ServerModule $module): bool => $module instanceof HasAdminPages || $module instanceof HasStaffPanel,
        );
    }

    /**
     * The first part of every core permission, such as "billing" and "hardware".
     *
     * @return list<string>
     */
    private static function coreGroups(): array
    {
        $groups = [];

        foreach (Role::PERMISSIONS as $permissions) {
            foreach (array_keys($permissions) as $key) {
                $groups[] = explode('.', $key, 2)[0];
            }
        }

        return array_values(array_unique($groups));
    }

    private function make(string $slug): ?ServerModule
    {
        return $this->attempt(fn (): ServerModule => $this->extensions->serverModule($slug), "Server module {$slug}");
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function attempt(callable $callback, string $what): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report(new RuntimeException("{$what}: ".class_basename($exception)));

            return null;
        }
    }
}
