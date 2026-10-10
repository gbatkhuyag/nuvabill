<?php

namespace App\Extensions\Servers\Panel;

/**
 * One tab of the client's server panel, declared by a module for a product. A standard key (see
 * StandardSections) uses the core view and data shape; any other key needs the module's own
 * view. Core skips a declaration with problems() and reports it, so one bad section never
 * breaks the panel.
 */
final readonly class PanelSection
{
    /**
     * Section and action keys: 1 to 32 lowercase letters, digits or dashes.
     */
    public const KEY_PATTERN = '/^[a-z0-9-]{1,32}\z/';

    /**
     * A module's own section view, from its views folder.
     */
    public const VIEW_PATTERN = '/^ext-[a-z0-9][a-z0-9_-]*::[A-Za-z0-9_.-]{1,100}\z/';

    /**
     * @param  string  $key  1 to 32 lowercase letters, digits or dashes.
     * @param  string  $label  The tab name, already translated.
     * @param  string  $icon  An icon name of the core icon set.
     * @param  string|null  $view  Null for a standard key; required for any other key ("ext-{slug}::panel.name").
     * @param  array<string, list<mixed>|string>  $readRules  Laravel rules for the read's query. Only these keys reach the module.
     * @param  array<string, PanelAction>  $actions  Keyed by each action's own key.
     * @param  bool  $enabledByDefault  Whether the product switch starts on.
     * @param  int  $cacheSeconds  How long core keeps a read, per service, section and input. 0 keeps nothing.
     * @param  array{0: int, 1: int}  $readThrottle  [max reads, per seconds] per service and section.
     * @param  int|null  $pollSeconds  The shortest gap between live refreshes; core uses the larger of the setting and this.
     * @param  string|null  $staffHelp  Shown to staff under the product switch, already translated.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon = 'server',
        public ?string $view = null,
        public array $readRules = [],
        public array $actions = [],
        public bool $enabledByDefault = true,
        public int $cacheSeconds = 0,
        public array $readThrottle = [60, 60],
        public ?int $pollSeconds = null,
        public ?string $staffHelp = null,
    ) {}

    /**
     * A standard section with its core label and icon. Actions that keep PanelAction's default
     * throttle get the standard limit for that action (StandardSections::DEFAULT_THROTTLES), which
     * is never looser. An unknown key gets no view, so problems() reports it.
     *
     * @param  array<string, PanelAction>  $actions
     * @param  array<string, list<mixed>|string>  $readRules
     * @param  array{0: int, 1: int}  $readThrottle
     */
    public static function standard(
        string $key,
        array $actions = [],
        array $readRules = [],
        int $cacheSeconds = 0,
        bool $enabledByDefault = true,
        ?int $pollSeconds = null,
        ?string $staffHelp = null,
        array $readThrottle = [60, 60],
    ): self {
        [$label, $icon] = StandardSections::KEYS[$key] ?? [$key, 'server'];

        foreach ($actions as $name => $action) {
            if ($action instanceof PanelAction && $action->throttle === PanelAction::DEFAULT_THROTTLE) {
                $default = StandardSections::defaultThrottle($key, $action->key);

                if ($default !== null) {
                    $actions[$name] = $action->withThrottle($default);
                }
            }
        }

        return new self(
            key: $key,
            label: __($label),
            icon: $icon,
            actions: $actions,
            readRules: $readRules,
            enabledByDefault: $enabledByDefault,
            cacheSeconds: $cacheSeconds,
            readThrottle: $readThrottle,
            pollSeconds: $pollSeconds,
            staffHelp: $staffHelp,
        );
    }

    /**
     * The action with this key, if the section declares it.
     */
    public function action(string $key): ?PanelAction
    {
        $action = $this->actions[$key] ?? null;

        return $action instanceof PanelAction ? $action : null;
    }

    /**
     * What is wrong with this declaration, in plain English for the log. Empty when it can be used.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $section = self::name($this->key);
        $problems = [];

        if (preg_match(self::KEY_PATTERN, $this->key) !== 1) {
            $problems[] = "Section key [{$section}] must be 1 to 32 lowercase letters, digits or dashes.";
        }

        if (trim($this->label) === '') {
            $problems[] = "Section [{$section}] has no label.";
        }

        if ($this->view === null && ! StandardSections::isStandard($this->key)) {
            $problems[] = "Section [{$section}] is not a standard section, so it needs its own view.";
        }

        if ($this->view !== null && preg_match(self::VIEW_PATTERN, $this->view) !== 1) {
            $problems[] = "Section [{$section}] has view [".self::name($this->view).'], but it must be a view of the module, such as ext-{slug}::panel.name.';
        }

        if (! self::validRules($this->readRules)) {
            $problems[] = "Section [{$section}] has read rules that are not field => rules.";
        }

        if (! self::validThrottle($this->readThrottle)) {
            $problems[] = "Section [{$section}] has a read throttle that is not [max, seconds] of whole numbers above 0.";
        }

        if ($this->cacheSeconds < 0) {
            $problems[] = "Section [{$section}] has a cache time below 0.";
        }

        if ($this->pollSeconds !== null && $this->pollSeconds < 1) {
            $problems[] = "Section [{$section}] has a live refresh time below 1 second.";
        }

        foreach ($this->actions as $name => $action) {
            array_push($problems, ...$this->actionProblems($section, $name, $action));
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function actionProblems(string $section, int|string $name, mixed $action): array
    {
        if (! $action instanceof PanelAction) {
            return ["Section [{$section}] lists [".self::name((string) $name).'], which is not a PanelAction.'];
        }

        $key = self::name($action->key);
        $problems = [];

        if (preg_match(self::KEY_PATTERN, $action->key) !== 1) {
            $problems[] = "Action key [{$section}.{$key}] must be 1 to 32 lowercase letters, digits or dashes.";
        }

        if ((string) $name !== $action->key) {
            $problems[] = "Action [{$section}.{$key}] must be listed under its own key, not [".self::name((string) $name).'].';
        }

        if (trim($action->label) === '') {
            $problems[] = "Action [{$section}.{$key}] has no label.";
        }

        if (! self::validRules($action->rules)) {
            $problems[] = "Action [{$section}.{$key}] has rules that are not field => rules.";
        }

        if ($action->destructive && ! self::needsAcceptedConfirm($action->rules)) {
            $problems[] = "Action [{$section}.{$key}] is destructive, so its rules need 'confirm' => ['accepted'].";
        }

        if (! self::validThrottle($action->throttle)) {
            $problems[] = "Action [{$section}.{$key}] has a throttle that is not [max, seconds] of whole numbers above 0.";
        }

        if ($action->bucket !== null && preg_match('/^[a-z0-9.-]{1,64}\z/', $action->bucket) !== 1) {
            $problems[] = "Action [{$section}.{$key}] has a throttle bucket that is not 1 to 64 lowercase letters, digits, dots or dashes.";
        }

        if (! array_is_list($action->secretFields) || array_filter($action->secretFields, fn (mixed $field): bool => ! is_string($field)) !== []) {
            $problems[] = "Action [{$section}.{$key}] has secret fields that are not a list of field names.";
        }

        return $problems;
    }

    /**
     * @param  array<mixed>  $rules
     */
    private static function validRules(array $rules): bool
    {
        foreach ($rules as $field => $rule) {
            if (! is_string($field) || $field === '' || (! is_string($rule) && ! is_array($rule))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private static function needsAcceptedConfirm(array $rules): bool
    {
        $rule = $rules['confirm'] ?? null;
        $parts = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : []);

        return in_array('accepted', $parts, true);
    }

    /**
     * @param  array<mixed>  $throttle
     */
    private static function validThrottle(array $throttle): bool
    {
        return array_is_list($throttle)
            && count($throttle) === 2
            && is_int($throttle[0]) && $throttle[0] >= 1
            && is_int($throttle[1]) && $throttle[1] >= 1;
    }

    /**
     * A module's value made safe for one log line.
     */
    private static function name(string $value): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7E]/', '?', $value), 0, 80);
    }
}
