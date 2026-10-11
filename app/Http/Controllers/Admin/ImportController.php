<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ResourceKinds;
use App\Http\Controllers\Controller;
use App\Import\ImportSource;
use App\Import\ImportSources;
use App\Import\Whmcs\WhmcsImporter;
use App\Jobs\RunImport;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Settings → Import: move clients, services, invoices and tickets over from another billing system.
 * Staff connect to its database, see a dry run of what would come across, then start the import.
 *
 * For WHMCS the dry run also lists the configurable options. Staff choose which ones become
 * resource options; the choices are saved with "Check again" (the preview route), which then makes
 * the dry run again with them.
 */
class ImportController extends Controller
{
    public function index(): View
    {
        $connection = ImportSources::connection();
        $status = RunImport::status();
        $running = RunImport::isRunning();
        $key = $running ? ($status['source'] ?? 'whmcs') : ($connection['source'] ?? $status['source'] ?? 'whmcs');
        $source = ImportSources::SOURCES[$key] ?? ImportSources::SOURCES['whmcs'];
        $preview = (array) setting('import.preview');

        return view('admin.settings.import', [
            'sources' => ImportSources::SOURCES,
            'connection' => Arr::except($connection, ['password', 'key']),
            'hasPassword' => filled($connection['password'] ?? null),
            'hasKey' => filled($connection['key'] ?? null),
            'source' => $source,
            // The last import, when it was from this system.
            'status' => ($status['source'] ?? 'whmcs') === $key ? $status : [],
            'running' => $running,
            'steps' => $source::steps(),
            'preview' => ($preview['source'] ?? null) === $key ? $preview : null,
            'optionChoices' => $key === WhmcsImporter::SOURCE ? WhmcsImporter::savedConfigOptions() : [],
        ]);
    }

    /**
     * Save the database details after checking they work, then make a dry run.
     */
    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(ImportSources::SOURCES))],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]*$/'],
            'key' => ['nullable', 'string', 'max:500'],
            'tls' => ['nullable', 'boolean'],
            'ssl_ca' => ['nullable', 'string', 'max:500'],
        ]);

        $saved = ImportSources::connection();
        // Encrypted unless staff untick it, for a database server without TLS.
        $data['tls'] = $request->has('tls') ? $request->boolean('tls') : true;
        $data['ssl_ca'] = trim((string) ($data['ssl_ca'] ?? ''));
        $data['port'] = (int) ($data['port'] ?? 3306);
        $data['prefix'] = (string) ($data['prefix'] ?? '');
        $data['password'] = filled($data['password'] ?? null) ? $data['password'] : (string) ($saved['password'] ?? '');
        $data['key'] = filled($data['key'] ?? null) ? trim($data['key']) : (($saved['source'] ?? null) === $data['source'] ? (string) ($saved['key'] ?? '') : '');
        $source = ImportSources::get($data['source']);

        try {
            $importer = $source::connect($data);
            $check = $importer->check();
            $preview = $this->dryRun($importer);
        } catch (Throwable $exception) {
            return back()
                ->withInput($request->except('password', 'key'))
                ->with('error', __('Could not read the :system database: :error', ['system' => $source::name(), 'error' => Str::limit($exception->getMessage(), 300)]));
        }

        $settings->set('import.connection', $data);
        $settings->set('import.preview', ['source' => $data['source']] + $preview);

        if ($source::keyChecksPasswords() && filled($data['key'])) {
            $settings->set('import.password_keys', [$data['source'] => $data['key']] + (array) setting('import.password_keys', []));
        }

        return back()->with('status', __('Connected to :system :version. Read the dry run below, then start the import.', ['system' => $source::name(), 'version' => $check['version']]));
    }

    /**
     * Make the dry run again with the saved details, for example after fixing something. With the
     * WHMCS configurable options form, its choices are saved first.
     */
    public function preview(Settings $settings): RedirectResponse
    {
        $connection = ImportSources::connection();

        if (blank($connection['database'] ?? null)) {
            return back()->with('error', __('Save the database details first.'));
        }

        $saved = request()->boolean('config_options_form');

        if ($saved) {
            if (($connection['source'] ?? null) !== WhmcsImporter::SOURCE) {
                return back()->with('error', __('Configurable options can only be chosen for a WHMCS import.'));
            }

            if (RunImport::isRunning()) {
                return back()->with('error', __('The import is running. Change the choices when it has finished.'));
            }

            $this->saveOptionChoices(request(), $settings);
        }

        try {
            $preview = $this->dryRun(ImportSources::connect($connection));
        } catch (Throwable $exception) {
            return back()->with('error', __('Could not read the :system database: :error', ['system' => ImportSources::get($connection['source'])::name(), 'error' => Str::limit($exception->getMessage(), 300)]));
        }

        $settings->set('import.preview', ['source' => $connection['source']] + $preview);

        return back()->with('status', $saved ? __('Choices saved. The dry run is up to date.') : __('The dry run is up to date.'));
    }

    public function start(): RedirectResponse
    {
        $connection = ImportSources::connection();
        $preview = (array) setting('import.preview');

        if (blank($connection['database'] ?? null)) {
            return back()->with('error', __('Save the database details first.'));
        }

        if (($preview['source'] ?? null) === $connection['source'] && collect($preview['problems'] ?? [])->contains('level', 'error')) {
            return back()->with('error', __('Fix the problems marked in red in the dry run first, then check again.'));
        }

        if (RunImport::isRunning()) {
            return back()->with('error', __('The import is already running.'));
        }

        RunImport::start($connection['source']);
        Activity::log('import.started', ImportSources::get($connection['source'])::name().' import started.');

        return back()->with('status', __('The import has started. It runs in the background, so you can leave this page.'));
    }

    public function cancel(Settings $settings): RedirectResponse
    {
        $status = RunImport::status();

        if (($status['state'] ?? null) === 'running') {
            $settings->set('import.status', ['state' => 'cancelled', 'finished_at' => now()->toIso8601String()] + $status);
            Activity::log('import.cancelled', 'Import stopped by staff.');
        }

        return back()->with('status', __('The import is stopped. Records already imported stay.'));
    }

    /**
     * The dry run, with WHMCS's configurable options for staff to choose from.
     *
     * @return array<string, mixed>
     */
    private function dryRun(ImportSource $importer): array
    {
        $preview = $importer->preflight()->toArray();

        if ($importer instanceof WhmcsImporter) {
            $preview['config_options'] = $importer->configOptions();
        }

        return $preview;
    }

    /**
     * Save which WHMCS configurable options become resource options. Only options the last dry run
     * listed can be chosen, each only as a kind its type allows, with whole units of at least the
     * kind's smallest step. The form holds every listed option, so it replaces the saved choices.
     *
     * @throws ValidationException
     */
    private function saveOptionChoices(Request $request, Settings $settings): void
    {
        $data = $request->validate([
            'config_options' => ['nullable', 'array', 'max:'.WhmcsImporter::MAX_LISTED_OPTIONS],
            'config_options.*' => ['array:kind,units'],
            'config_options.*.kind' => ['nullable', 'string', Rule::in(WhmcsImporter::configOptionKinds())],
            'config_options.*.units' => ['nullable', 'integer', 'between:1,'.WhmcsImporter::MAX_UNITS],
        ]);

        $listed = collect(((array) setting('import.preview'))['config_options']['options'] ?? [])->keyBy('id');
        $choices = [];
        $errors = [];

        foreach ((array) ($data['config_options'] ?? []) as $id => $choice) {
            $option = $listed->get((int) $id);
            $kind = (string) ($choice['kind'] ?? '');

            if ($option === null || $kind === '') {
                continue;
            }

            if (! in_array($kind, WhmcsImporter::CONFIG_OPTION_KINDS[$option['type'] ?? ''] ?? [], true)) {
                $errors["config_options.{$id}.kind"] = __('This option cannot become :resource.', ['resource' => ResourceKinds::label($kind)]);

                continue;
            }

            $units = $kind === 'backups' ? 1 : (int) ($choice['units'] ?? 0);
            $smallest = ResourceKinds::BUILT_IN[$kind]['min_step'];

            if ($units < $smallest) {
                $errors["config_options.{$id}.units"] = __('Each step must add at least :amount.', ['amount' => ResourceKinds::format(ResourceKinds::BUILT_IN[$kind]['unit'], $smallest)]);

                continue;
            }

            $choices[(int) $id] = ['kind' => $kind, 'units' => $units];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        WhmcsImporter::saveConfigOptions($settings, $choices);
        Activity::log('import.options_saved', 'WHMCS configurable options for the import saved: '.count($choices).' brought across as resource options.');
    }
}
