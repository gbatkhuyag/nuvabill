<?php

namespace App\Provisioning;

use App\Contracts\HasClientPanel;
use App\Contracts\HasConsole;
use App\Contracts\HasPanelSections;
use App\Contracts\ServerModule;
use App\Contracts\SignsInWithContext;
use App\Contracts\UsesMachineInventory;
use App\Enums\ServiceStatus;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelAction;
use App\Extensions\Servers\Panel\PanelException;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\PanelSection;
use App\Extensions\Servers\Panel\StandardSections;
use App\Mail\TemplateMailer;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Hardware\DedicatedPanelExtensions;
use App\Support\Activity;
use App\Support\Locales;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The checks every client server panel read and action passes, in this order: owner, status,
 * module and section (with the product's switches), live availability, action declared, input
 * validation, throttles, the read cache, the service lock for changing actions, the module call,
 * the secret scrubber, then the log, cache version and email after a change. Modules never see a
 * request that did not pass them. Provisioner hands the panel work to this class.
 *
 * Cache keys (all per service): the version "nuvabill:panel:v:{id}" (a new one after every
 * changing action, so every read cache of the service is dropped), the live sections
 * "nuvabill:panel:avail:{id}:{version}" (60 seconds), the reads
 * "nuvabill:panel:{id}:{version}:{section}:{locale}:{hash of the input}" and the overview state
 * "nuvabill:panel:state:{id}:{version}".
 */
final class PanelGate
{
    /**
     * Seconds a changing action may hold the service lock (Provisioner::lockFor).
     */
    public const LOCK_SECONDS = 120;

    /**
     * Seconds the sections the live server allows are kept.
     */
    public const AVAILABLE_SECONDS = 60;

    /**
     * Sections a service keeps when the module cannot tell which ones its server allows.
     *
     * @var list<string>
     */
    public const BASIC_SECTIONS = ['overview', 'power'];

    /**
     * Console opens per service: [max, per seconds].
     *
     * @var array{0: int, 1: int}
     */
    public const CONSOLE_OPENS = [6, 600];

    /**
     * Seconds between two "server change" emails for the same service and action.
     */
    public const EMAIL_GAP_SECONDS = 600;

    /**
     * Seconds the overview's server state is kept for the power bar's status checks.
     */
    private const STATE_SECONDS = 120;

    /**
     * Seconds a version stays; read caches are far shorter, so nothing stale is left after it.
     */
    private const VERSION_SECONDS = 86400;

    /**
     * Server states an overview may report.
     *
     * @var list<string>
     */
    private const STATES = ['running', 'stopped', 'suspended', 'busy', 'unknown'];

    public function __construct(
        private Provisioner $provisioner,
        private ConsoleTickets $tickets,
        private TemplateMailer $mailer,
    ) {}

    /**
     * The serverPanel array for the client's service page (view, data, actions, state, login,
     * console, power, sections, status_url, resources_url), or null when the service has no panel:
     * it is not the client's, not active, or has no place to run.
     *
     * Each section row is {key, label, icon, view, read_url, poll, actions}, where actions maps each
     * action key to {label, url, confirm, destructive, reveals}. "power" is not a section row; its
     * actions are the power list {key, label, url, confirm, destructive, expects}. poll is the
     * shortest gap in seconds between live refreshes: for the overview the larger of the setting
     * and the section's own, for other sections the section's own or null.
     *
     * @return array<string, mixed>|null
     */
    public function serverPanel(Service $service, ServerModule $module, ClientContext $context): ?array
    {
        $service->loadMissing('product', 'server');

        if (! $module instanceof HasPanelSections || $this->refusal($service, $module, $context) !== null) {
            return null;
        }

        $available = $this->availableKeys($service, $module, $context);
        $keyed = [];

        foreach ($this->enabledSections($service->product, $module) as $section) {
            if ($available === null || in_array($section->key, $available, true)) {
                $keyed[$section->key] = $section;
            }
        }

        $data = [];

        if ($module instanceof HasClientPanel) {
            $data = (array) ($this->provisioner->clientPanel($service)['data'] ?? []);
        }

        return [
            'view' => 'theme::client.services.server-panel',
            'data' => $data,
            'actions' => [],
            'state' => $this->firstPaintState($service, $module, $data),
            'login' => $this->offersSignIn($module, $data)
                ? ['label' => __('Open :panel panel', ['panel' => $module->name()]), 'url' => route('client.services.login', $service)]
                : null,
            'console' => isset($keyed['console']) && $module instanceof HasConsole
                ? ['url' => route('client.services.console.open', $service)]
                : null,
            'power' => isset($keyed['power']) ? $this->powerRows($service, $keyed['power']) : [],
            'sections' => array_values(array_map(
                fn (PanelSection $section): array => $this->sectionRow($service, $section),
                array_filter($keyed, fn (PanelSection $section): bool => $section->key !== 'power'),
            )),
            'status_url' => route('client.services.panel-status', $service),
            'resources_url' => $service->product->resourceOptions()->where('is_active', true)->exists()
                ? route('client.services.show', $service).'#resources'
                : null,
        ];
    }

    /**
     * Read one section for the client, after every check. On success the data also carries
     * fetched_at: the Unix time the module answered (older when it comes from the read cache).
     *
     * @param  array<string, mixed>  $query  The raw query; only keys with read rules reach the module.
     */
    public function read(Service $service, ServerModule $module, string $section, array $query, ClientContext $context): PanelResult
    {
        $declared = $this->open($service, $module, $section, $context);

        if ($declared instanceof PanelResult) {
            return $declared;
        }

        // Power is the header bar: it has actions but nothing to read.
        if ($declared->key === 'power') {
            return $this->notFound();
        }

        $validator = Validator::make($query, $declared->readRules);

        if ($validator->fails()) {
            return PanelResult::invalid($validator->errors()->toArray());
        }

        $input = $validator->validated();
        [$max, $seconds] = $declared->readThrottle;
        $throttle = "panel-read:{$service->id}:{$declared->key}";

        if (RateLimiter::tooManyAttempts($throttle, $max)) {
            return PanelResult::throttled(RateLimiter::availableIn($throttle));
        }

        RateLimiter::hit($throttle, $seconds);

        $cacheKey = $this->readKey($service, $declared->key, $input);

        if ($declared->cacheSeconds > 0) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && is_array($cached['data'] ?? null) && is_int($cached['at'] ?? null)) {
                return PanelResult::ok((string) ($cached['message'] ?? ''), array_merge($cached['data'], ['fetched_at' => $cached['at']]));
            }
        }

        $result = $this->call($service, $module, $declared->key, null, fn (): PanelResult => $module->readPanelSection($service, $declared->key, $input, $context));
        $result = $result->scrubbed(false);

        if (! $result->ok) {
            return $result;
        }

        $at = Date::now()->getTimestamp();

        if ($declared->cacheSeconds > 0) {
            Cache::put($cacheKey, ['data' => $result->data, 'at' => $at, 'message' => $result->message], $declared->cacheSeconds);
        }

        if ($declared->key === 'overview' && in_array($result->data['state'] ?? null, self::STATES, true)) {
            Cache::put($this->stateKey($service), $result->data['state'], self::STATE_SECONDS);
        }

        return PanelResult::ok($result->message, array_merge($result->data, ['fetched_at' => $at]));
    }

    /**
     * Run one action for the client, after every check.
     *
     * @param  array<string, mixed>  $input  The raw input; only keys with rules reach the module.
     * @param  bool  $canReveal  Whether the answer can show a secret (the browser asked for JSON).
     */
    public function action(Service $service, ServerModule $module, string $section, string $action, array $input, ClientContext $context, bool $canReveal): PanelResult
    {
        $declared = $this->open($service, $module, $section, $context);

        if ($declared instanceof PanelResult) {
            return $declared;
        }

        $panelAction = $declared->action($action);

        if ($panelAction === null) {
            return PanelResult::fail(__('This action is not available.'), null, 404);
        }

        $validator = Validator::make($input, $panelAction->rules);

        if ($validator->fails()) {
            return PanelResult::invalid($validator->errors()->toArray());
        }

        $validated = $validator->validated();

        // A secret can only be shown by the page's script; without it the change would run unseen.
        if ($panelAction->reveals && ! $canReveal) {
            return PanelResult::fail(__('Turn on JavaScript to do this.'));
        }

        [$max, $seconds] = $panelAction->throttle;
        $throttle = "panel:{$service->id}:{$panelAction->bucketFor($declared->key)}";

        if (RateLimiter::tooManyAttempts($throttle, $max)) {
            return PanelResult::throttled(RateLimiter::availableIn($throttle));
        }

        RateLimiter::hit($throttle, $seconds);

        $run = fn (): PanelResult => $this->call($service, $module, $declared->key, $panelAction->key, fn (): PanelResult => $module->runPanelAction($service, $declared->key, $panelAction->key, $validated, $context));

        if ($panelAction->changesServer) {
            $lock = $this->provisioner->lockFor($service, self::LOCK_SECONDS);

            if (! $lock->get()) {
                return PanelResult::fail(__('Another change is running on this server. Try again in a moment.'), null, 409);
            }

            try {
                // A suspend or terminate may have finished while this request waited.
                $service->refresh();
                $service->loadMissing('product', 'server', 'client');
                $refusal = $this->refusal($service, $module, $context);

                if ($refusal !== null) {
                    return $refusal;
                }

                $result = $run();
            } finally {
                $lock->release();
            }

            $this->bumpVersion($service);
        } else {
            $result = $run();
        }

        $result = $result->scrubbed($panelAction->reveals && $canReveal && $result->ok);
        $this->afterAction($service, $declared, $panelAction, $result, $context);

        return $result;
    }

    /**
     * The sections the module declares for the product that staff left on, in display order. The
     * console also needs the site's console setting.
     *
     * @return list<PanelSection>
     */
    public function enabledSections(Product $product, ServerModule $module): array
    {
        return array_values(array_filter(
            $this->declaredSections($product, $module),
            fn (PanelSection $section): bool => $this->switchedOn($product, $section),
        ));
    }

    /**
     * Every usable section the module declares for the product, in display order: declarations
     * with problems, a duplicate key or another module's view are left out and logged once a day.
     * Empty when the module has no panel sections or cannot list them. Never calls a server.
     *
     * @return list<PanelSection>
     */
    public function declaredSections(Product $product, ServerModule $module): array
    {
        if (! $module instanceof HasPanelSections) {
            return [];
        }

        try {
            $declared = $module->panelSections($product);
        } catch (Throwable $exception) {
            if (Cache::add('nuvabill:panel:sections-failed:'.$module->slug().':'.(int) $product->id, true, 3600)) {
                $this->reportCleanly("Panel sections of {$module->slug()}", $exception);
            }

            return [];
        }

        $sections = [];

        foreach ($declared as $section) {
            $problems = $section instanceof PanelSection ? $section->problems() : ['A section is not a PanelSection.'];

            // Without problems the key is plain letters, digits and dashes, safe for the log.
            if ($section instanceof PanelSection && $problems === []) {
                if ($section->view !== null && ! str_starts_with($section->view, 'ext-'.$module->slug().'::') && ! $this->addonTab($module, $product, $section)) {
                    $problems[] = "Section [{$section->key}] uses a view of another extension.";
                }

                if (isset($sections[$section->key])) {
                    $problems[] = "Section [{$section->key}] is declared twice.";
                }
            }

            if ($problems !== []) {
                $this->logSkippedSection($module, $product, $problems);

                continue;
            }

            $sections[$section->key] = $section;
        }

        return array_values($sections);
    }

    /**
     * Whether the section is a tab an add-on adds to dedicated servers (DedicatedPanelExtensions),
     * with that add-on's own view.
     */
    private function addonTab(ServerModule $module, Product $product, PanelSection $section): bool
    {
        if (! $module instanceof UsesMachineInventory) {
            return false;
        }

        $added = app(DedicatedPanelExtensions::class)->sections($product)[$section->key]['section'] ?? null;

        return $added instanceof PanelSection && $added->view === $section->view;
    }

    /**
     * The server state from the cached overview read (running, stopped, suspended, busy or
     * unknown), or null when none is cached. It never calls the server.
     */
    public function overviewState(Service $service, ServerModule $module): ?string
    {
        $state = Cache::get($this->stateKey($service));

        return in_array($state, self::STATES, true) ? $state : null;
    }

    /**
     * Open a console for the client: the gate checks for the "console" section, then a single-use
     * ticket. On success data holds 'ticket' (the handle) and 'source' (wss://host:port).
     *
     * The ticket is bound to the signed-in session of the current request; without one no console
     * opens.
     */
    public function openConsole(Service $service, ServerModule $module, ClientContext $context): PanelResult
    {
        $declared = $this->open($service, $module, 'console', $context);

        if ($declared instanceof PanelResult) {
            return $declared;
        }

        if (! $module instanceof HasConsole) {
            return $this->notFound();
        }

        [$max, $seconds] = self::CONSOLE_OPENS;
        $throttle = "panel-console:{$service->id}";

        if (RateLimiter::tooManyAttempts($throttle, $max)) {
            return PanelResult::throttled(RateLimiter::availableIn($throttle));
        }

        RateLimiter::hit($throttle, $seconds);

        $request = request();
        $sessionId = $request->hasSession() ? (string) $request->session()->getId() : '';

        try {
            $session = $module->consoleSession($service, $context);

            // The console page speaks VNC (noVNC) only in this release.
            if ($session->protocol !== 'vnc') {
                throw new PanelException(__('The console is not available for this server right now.'), 'The module gave a console that is not VNC.', 503);
            }

            $source = $session->connectSource();
            $handle = $this->tickets->issue($service, $context, $session, $sessionId);
        } catch (PanelException $exception) {
            $this->logFailure($service, 'console', 'open', $exception->toResult());

            return $exception->toResult();
        } catch (Throwable $exception) {
            $this->reportCleanly("Panel {$module->slug()}.console", $exception);
            $failed = PanelResult::fail(__('The server did not answer. Please try again in a minute.'), class_basename($exception), 503);
            $this->logFailure($service, 'console', 'open', $failed);

            return $failed;
        }

        Activity::log('service.console_opened', "Client opened the console of service #{$service->id} ({$service->label()})", $service);

        return PanelResult::ok('', ['ticket' => $handle, 'source' => $source]);
    }

    /**
     * Steps 1 to 4: owner, status, module and section with its switches, live availability. The
     * declared section, or the refusal.
     */
    private function open(Service $service, ServerModule $module, string $section, ClientContext $context): PanelSection|PanelResult
    {
        $service->loadMissing('product', 'server');
        $refusal = $this->refusal($service, $module, $context);

        if ($refusal !== null) {
            return $refusal;
        }

        if (! $module instanceof HasPanelSections || preg_match(PanelSection::KEY_PATTERN, $section) !== 1) {
            return $this->notFound();
        }

        $declared = null;

        foreach ($this->declaredSections($service->product, $module) as $candidate) {
            if ($candidate->key === $section) {
                $declared = $candidate;
            }
        }

        if ($declared === null) {
            return $this->notFound();
        }

        if (! $this->switchedOn($service->product, $declared)) {
            return PanelResult::unavailable(__('This feature is turned off for your plan.'));
        }

        $available = $this->availableKeys($service, $module, $context);

        if ($available !== null && ! in_array($section, $available, true)) {
            return PanelResult::unavailable(__('This feature is not available on your server.'));
        }

        return $declared;
    }

    /**
     * Steps 1 and 2: the client's own active service with a place to run, or why not.
     */
    private function refusal(Service $service, ServerModule $module, ClientContext $context): ?PanelResult
    {
        if ((int) $service->client_id !== $context->clientId) {
            return $this->notFound();
        }

        if ($service->status === ServiceStatus::Suspended) {
            return PanelResult::unavailable(__('This service is suspended.'));
        }

        if ($service->status !== ServiceStatus::Active || ($service->server === null && ! $module instanceof UsesMachineInventory)) {
            return $this->notFound();
        }

        return null;
    }

    /**
     * Step 10: call the module. A PanelException becomes its own answer; anything else becomes a
     * 503, reported without its message (a raw HTTP error can hold an address with a key).
     *
     * @param  callable(): PanelResult  $call
     */
    private function call(Service $service, ServerModule $module, string $section, ?string $action, callable $call): PanelResult
    {
        try {
            return $call();
        } catch (PanelException $exception) {
            return $exception->toResult();
        } catch (Throwable $exception) {
            // Live refreshes repeat a failing read every few seconds; once in five minutes is enough.
            if ($action !== null || Cache::add("nuvabill:panel:read-failed:{$service->id}:{$section}", true, 300)) {
                $this->reportCleanly("Panel {$module->slug()}.{$section}", $exception);
            }

            return PanelResult::fail(__('The server did not answer. Please try again in a minute.'), class_basename($exception), 503);
        }
    }

    /**
     * Step 12: the activity log and the client's email after an action the module ran.
     */
    private function afterAction(Service $service, PanelSection $section, PanelAction $action, PanelResult $result, ClientContext $context): void
    {
        $service->loadMissing('product', 'client');

        if (! $result->ok) {
            $this->logFailure($service, $section->key, $action->key, $result);

            return;
        }

        $what = "\"{$section->key}: {$action->key}\"";
        $detail = $result->logDetail !== null && trim($result->logDetail) !== '' ? ' ['.self::clean($result->logDetail).']' : '';

        if ($action->changesServer || $result->secret === null) {
            Activity::log('service.client_action', "Client ran {$what} on service #{$service->id} ({$service->label()}): ".self::clean($result->message).$detail, $service);
        }

        if ($result->secret !== null) {
            Activity::log('service.secret_shown', "Client was shown a one-time secret by {$what} on service #{$service->id} ({$service->label()})", $service);
        }

        if ($action->notify !== null && (bool) setting('services.panel_change_emails') && $service->client !== null
            && Cache::add("nuvabill:panel:mail:{$service->id}:{$section->key}.{$action->key}", true, self::EMAIL_GAP_SECONDS)) {
            $this->sendChangeEmail($service, $action->notify, $context);
        }
    }

    private function sendChangeEmail(Service $service, string $notify, ClientContext $context): void
    {
        $change = Locales::in(Locales::forClient($service->client), fn (): array => [
            'text' => __($notify),
            'time' => Date::now()->translatedFormat('d M Y H:i T'),
            'ip' => $context->ip,
        ]);

        $this->mailer->send('service.server_change', $service->client, TemplateMailer::serviceContext($service) + ['change' => $change]);
    }

    /**
     * A failed action from the module, for staff: its staff detail (cleaned) or its message.
     */
    private function logFailure(Service $service, string $section, string $action, PanelResult $result): void
    {
        $service->loadMissing('product');
        $detail = self::clean($result->staffDetail ?? $result->message);

        Activity::log('service.module_failed', "Client panel \"{$section}: {$action}\" failed on service #{$service->id} ({$service->label()}): {$detail}", $service);
    }

    /**
     * Step 4: the section keys the live server allows now, or null for all declared ones. Kept 60
     * seconds per service and cache version. When the module fails, only the basic sections stay.
     *
     * @return list<string>|null
     */
    private function availableKeys(Service $service, ServerModule&HasPanelSections $module, ClientContext $context): ?array
    {
        $key = "nuvabill:panel:avail:{$service->id}:{$this->version($service)}";
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('keys', $cached)) {
            return $cached['keys'];
        }

        try {
            $keys = $module->availablePanelSections($service, $context);
            $keys = $keys === null ? null : array_values(array_filter($keys, fn (mixed $value): bool => is_string($value) && preg_match(PanelSection::KEY_PATTERN, $value) === 1));
        } catch (Throwable $exception) {
            if (Cache::add("nuvabill:panel:avail-failed:{$service->id}", true, 300)) {
                $this->reportCleanly("Panel {$module->slug()}.available", $exception);
            }

            $keys = self::BASIC_SECTIONS;
        }

        Cache::put($key, ['keys' => $keys], self::AVAILABLE_SECONDS);

        return $keys;
    }

    /**
     * Whether staff left the section on for the product. The console also needs the site setting.
     */
    private function switchedOn(Product $product, PanelSection $section): bool
    {
        if ($section->key === 'console' && ! (bool) setting('services.panel_console')) {
            return false;
        }

        return $product->panelSectionEnabled($section->key, $section->enabledByDefault);
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionRow(Service $service, PanelSection $section): array
    {
        $live = max(5, min(60, (int) setting('services.panel_live_seconds')));

        return [
            'key' => $section->key,
            'label' => $section->label,
            'icon' => $section->icon,
            'view' => $section->view ?? 'theme::client.services.panel.'.$section->key,
            'read_url' => route('client.services.sections.show', [$service, $section->key]),
            'poll' => $section->key === 'overview' ? max($live, $section->pollSeconds ?? 0) : $section->pollSeconds,
            'actions' => array_map(fn (PanelAction $action): array => [
                'label' => $action->label,
                'url' => route('client.services.sections.action', [$service, $section->key, $action->key]),
                'confirm' => $action->confirm,
                'destructive' => $action->destructive,
                'reveals' => $action->reveals,
            ], $section->actions),
        ];
    }

    /**
     * @return list<array{key: string, label: string, url: string, confirm: string|null, destructive: bool, expects: string|null}>
     */
    private function powerRows(Service $service, PanelSection $power): array
    {
        return array_values(array_map(fn (PanelAction $action): array => [
            'key' => $action->key,
            'label' => $action->label,
            'url' => route('client.services.sections.action', [$service, 'power', $action->key]),
            'confirm' => $action->confirm,
            'destructive' => $action->destructive,
            'expects' => StandardSections::expectedState($action->key),
        ], $power->actions));
    }

    /**
     * The state for the first paint: the module's own panel data, else the cached overview, else
     * the module's live state (HasPanelState), else unknown.
     *
     * @param  array<string, mixed>  $data
     */
    private function firstPaintState(Service $service, ServerModule $module, array $data): string
    {
        $state = $data['state'] ?? $this->overviewState($service, $module) ?? $this->provisioner->clientPanelState($service);

        return in_array($state, ['running', 'stopped', 'suspended'], true) ? $state : 'unknown';
    }

    /**
     * Whether the header offers "Open … panel": a module with its own client panel says so in its
     * data; another module needs a context sign-in and a login link.
     *
     * @param  array<string, mixed>  $data
     */
    private function offersSignIn(ServerModule $module, array $data): bool
    {
        if ($module instanceof HasClientPanel) {
            return is_string($data['login'] ?? null) && $data['login'] !== '' && empty($data['error']);
        }

        return $module instanceof SignsInWithContext && (! $module instanceof Module || $module->hasLoginLink());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function readKey(Service $service, string $section, array $input): string
    {
        return "nuvabill:panel:{$service->id}:{$this->version($service)}:{$section}:".app()->getLocale().':'.sha1((string) json_encode($input));
    }

    private function stateKey(Service $service): string
    {
        return "nuvabill:panel:state:{$service->id}:{$this->version($service)}";
    }

    private function version(Service $service): string
    {
        $version = Cache::get("nuvabill:panel:v:{$service->id}");

        return is_string($version) ? $version : '0';
    }

    /**
     * A new cache version for the service: its read caches, live sections and overview state are
     * read again.
     */
    private function bumpVersion(Service $service): void
    {
        Cache::put("nuvabill:panel:v:{$service->id}", Str::lower(Str::random(12)), self::VERSION_SECONDS);
    }

    private function notFound(): PanelResult
    {
        return PanelResult::fail(__('This feature is not available for this service.'), null, 404);
    }

    /**
     * Report a module exception by its class only: never its message or the exceptions before it.
     */
    private function reportCleanly(string $what, Throwable $exception): void
    {
        report(new RuntimeException("{$what}: ".class_basename($exception)));
    }

    /**
     * @param  list<string>  $problems
     */
    private function logSkippedSection(ServerModule $module, Product $product, array $problems): void
    {
        if (Cache::add('nuvabill:panel:section-skipped:'.$module->slug().':'.sha1(implode('|', $problems)), true, 86400)) {
            Log::warning("Server module \"{$module->slug()}\": a client panel section of product #".(int) $product->id.' was left out: '.implode(' ', $problems));
        }
    }

    /**
     * Text from a module made safe for one log line: no control characters, and no value after a
     * word that looks like a secret (password=…, token: …).
     */
    private static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        $text = (string) preg_replace('/((?:pass(?:word|wd)?|secret|token|api_?key|apipass|adminapikey|sid|key)\s*[=:]\s*)[^\s&"\',;]+/i', '$1[hidden]', $text);

        return Str::limit(trim($text), 200);
    }
}
