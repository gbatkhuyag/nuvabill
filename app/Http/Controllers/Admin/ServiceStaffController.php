<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\HasStaffPanel;
use App\Extensions\Servers\ModuleHooks;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Staff\StaffAction;
use App\Extensions\Servers\Staff\StaffContext;
use App\Extensions\Servers\Staff\StaffPanel;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * Admin → a service → the server module's own card (HasStaffPanel): read-only facts and the
 * module's staff actions. Only staff with the module's admin permission and two-factor login get
 * here (the route adds two-factor and a limit of 10 a minute). An action runs only when the card
 * offers it for this service now, with its own checked fields, under the service lock, and is
 * logged with the staff member's name.
 */
class ServiceStaffController extends Controller
{
    /**
     * Fragment of the card on the service page.
     */
    public const ANCHOR = '#module-staff';

    /**
     * The card for the service page, or null when the module has none or the staff member may not
     * see it. A module that fails gives a card with a notice instead.
     *
     * @return array{title: string, rows: list<array{label: string, value: string}>, actions: array<string, StaffAction>, notice: string|null, failed: bool, needs_two_factor: bool}|null
     */
    public static function card(Service $service, Admin $admin): ?array
    {
        $module = app(ModuleHooks::class)->staffModuleFor($service->loadMissing('product'), $admin);

        if ($module === null) {
            return null;
        }

        $panel = self::panel($module, $service);

        if ($panel === null) {
            return ['title' => $module->name(), 'rows' => [], 'actions' => [], 'notice' => __('The server module could not load its staff tools. Try again in a minute.'), 'failed' => true, 'needs_two_factor' => false];
        }

        $actions = $panel->checkedActions();
        // Without two-factor login staff see the facts, not the buttons (the route refuses them too).
        $mayAct = $admin->hasTwoFactorEnabled() || Demo::isEnabled();

        return [
            'title' => $panel->title !== '' ? mb_substr($panel->title, 0, 120) : $module->name(),
            'rows' => $panel->checkedRows(),
            'actions' => $mayAct ? $actions : [],
            'notice' => $panel->notice !== null ? mb_substr($panel->notice, 0, 300) : null,
            'failed' => false,
            'needs_two_factor' => ! $mayAct && $actions !== [],
        ];
    }

    public function run(Request $request, Service $service, string $action, Provisioner $provisioner): RedirectResponse
    {
        $admin = $request->user('admin');
        $back = redirect()->to(route('admin.services.show', $service).self::ANCHOR);
        $module = app(ModuleHooks::class)->staffModuleFor($service->loadMissing('product'), $admin);

        abort_if($module === null, 403, __('Your role does not allow this.'));

        // Only an action the card offers for this service right now.
        $declared = self::panel($module, $service)?->checkedActions()[$action] ?? null;

        if ($declared === null) {
            return $back->with('error', __('This action is not available for this service.'));
        }

        $fields = $declared->checkedFields();
        $validator = Validator::make($request->only(array_keys($fields)), $declared->rules(), [], array_map(fn (array $field): string => $field['label'], $fields));

        if ($validator->fails()) {
            return $back->withErrors($validator, 'staff_'.$action)->withInput($request->only(array_keys($fields)));
        }

        $validated = $validator->validated();
        $input = [];

        foreach ($fields as $name => $field) {
            $input[$name] = $field['type'] === 'checkbox'
                ? ($request->boolean($name) ? '1' : '')
                : (isset($validated[$name]) ? trim((string) $validated[$name]) : '');
        }

        $lock = $provisioner->lockFor($service, 300);

        if (! $lock->get()) {
            return $back->with('error', __('Other work is changing this service right now. Try again in a minute.'));
        }

        try {
            $service->refresh()->loadMissing('product', 'server', 'client');
            $result = $this->attempt(fn (): ModuleResult => $module->runStaffAction($service, $action, $input, new StaffContext((int) $admin->id, (string) $admin->name, (string) $request->ip())), $module->slug(), $action);
        } finally {
            $lock->release();
        }

        // The log names the action and staff member, never the values: they may hold an address with a key.
        Activity::log(
            $result->success ? 'service.staff_action' : 'service.staff_action_failed',
            "Staff ran \"{$declared->key}\" of {$module->slug()} on service #{$service->id} ({$service->label()}): ".($result->success ? 'done' : 'failed'),
            $service,
        );

        $message = mb_substr($result->message, 0, 500);

        return $back->with($result->success ? 'status' : 'error', $message !== '' ? $message : ($result->success ? __('Done.') : __('The server module could not do this. Try again in a minute.')));
    }

    private static function panel(HasStaffPanel $module, Service $service): ?StaffPanel
    {
        try {
            $panel = $module->staffPanel($service);

            return $panel instanceof StaffPanel ? $panel : null;
        } catch (Throwable $exception) {
            report(new RuntimeException("Staff panel of {$module->slug()} for service #{$service->id}: ".class_basename($exception)));

            return null;
        }
    }

    /**
     * @param  callable(): ModuleResult  $callback
     */
    private function attempt(callable $callback, string $slug, string $action): ModuleResult
    {
        try {
            $result = $callback();

            return $result instanceof ModuleResult ? $result : ModuleResult::fail(__('The server module could not do this. Try again in a minute.'));
        } catch (Throwable $exception) {
            report(new RuntimeException("Staff action {$slug}.{$action}: ".class_basename($exception)));

            return ModuleResult::fail(__('The server module could not do this. Try again in a minute.'));
        }
    }
}
