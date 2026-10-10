<?php

namespace App\Extensions\Servers\Panel;

use InvalidArgumentException;

/**
 * The server panel sections every module shares: their keys, labels and icons, the power actions,
 * the old panel actions, the standard throttles and the secret scrubber. A standard key uses the
 * core view and data shape, so the panel looks the same for every module.
 */
final class StandardSections
{
    /**
     * Standard section keys, in display order, as key => [English label, icon]. "power" is the
     * header bar, never a tab.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KEYS = [
        'overview' => ['Overview', 'server'],
        'console' => ['Console', 'terminal'],
        'graphs' => ['Graphs', 'chart'],
        'reinstall' => ['Reinstall', 'disc'],
        'network' => ['IPs and rDNS', 'globe'],
        'firewall' => ['Firewall', 'shield'],
        'backups' => ['Backups', 'archive'],
        'snapshots' => ['Snapshots', 'layers'],
        'rescue' => ['Rescue', 'life-buoy'],
        'ssh-keys' => ['SSH keys', 'key'],
        'media' => ['ISO and boot', 'disc'],
        'settings' => ['Settings', 'settings'],
        'activity' => ['Tasks and logs', 'list'],
        'power' => ['Power', 'power'],
    ];

    /**
     * Standard power actions, as key => [English label, state when done, destructive, English
     * confirm question or null]. Other power keys are allowed (such as "wol"); they have no state
     * to wait for.
     *
     * @var array<string, array{0: string, 1: string, 2: bool, 3: string|null}>
     */
    public const POWER_ACTIONS = [
        'start' => ['Start', 'running', false, null],
        'stop' => ['Shut down', 'stopped', false, 'Shut down the server now? Websites and apps on it will stop.'],
        'restart' => ['Restart', 'running', false, 'Restart the server now?'],
        'poweroff' => ['Power off', 'stopped', true, 'Power off without a clean shutdown? Only use this if the server does not respond.'],
        'reset' => ['Force restart', 'running', true, 'Force a restart? This is like pressing the reset button. Unsaved data is lost.'],
        'power-cycle' => ['Power cycle', 'running', true, 'Turn the power off and on again? Unsaved data is lost.'],
    ];

    /**
     * Actions of the old client.services.panel/{action} route, as old action => "section.action".
     *
     * @var array<string, string>
     */
    public const LEGACY_ACTIONS = [
        'start' => 'power.start',
        'stop' => 'power.stop',
        'restart' => 'power.restart',
        'poweroff' => 'power.poweroff',
        'hostname' => 'settings.hostname',
        'password' => 'settings.root-password',
        'reinstall' => 'reinstall.run',
        'vnc' => 'console.details',
    ];

    /**
     * Standard throttles as "section.action" (or "section.*") => [max tries, per seconds].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const DEFAULT_THROTTLES = [
        'power.*' => [10, 60],
        'reinstall.run' => [3, 3600],
        'reinstall.request' => [3, 86400],
        'settings.*' => [5, 600],
        'network.*' => [20, 3600],
        'firewall.*' => [10, 600],
        'backups.create' => [3, 3600],
        'backups.restore' => [3, 3600],
        'snapshots.create' => [3, 3600],
        'snapshots.restore' => [3, 3600],
        'backups.delete' => [10, 3600],
        'snapshots.delete' => [10, 3600],
        'rescue.*' => [5, 3600],
        'ssh-keys.*' => [10, 600],
        'media.*' => [10, 600],
        'console.details' => [5, 600],
    ];

    /**
     * Data keys that look like a secret. Core removes them from every read and action result, at
     * any depth, so modules must not use these words in normal keys ("credential_mode", not
     * "password_mode"). Dashes do not count as a word edge, so "root-password" stays.
     */
    public const SCRUB_PATTERN = '/(^|_)(pass(word|wd)?|secret|token|api_?key|apipass|sid)($|_)/i';

    public static function isStandard(string $key): bool
    {
        return array_key_exists($key, self::KEYS);
    }

    /**
     * The standard throttle of an action, or null when there is none.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function defaultThrottle(string $section, string $action): ?array
    {
        return self::DEFAULT_THROTTLES[$section.'.'.$action] ?? self::DEFAULT_THROTTLES[$section.'.*'] ?? null;
    }

    /**
     * The state a standard power action leaves the server in, or null for other actions.
     */
    public static function expectedState(string $action): ?string
    {
        return self::POWER_ACTIONS[$action][1] ?? null;
    }

    /**
     * A standard power action, sharing the "power" throttle bucket. A destructive one asks for a
     * ticked confirm box.
     *
     * @param  string|null  $label  Another button text, already translated, such as "Restart (Ctrl+Alt+Del)".
     *
     * @throws InvalidArgumentException For a key that is not in POWER_ACTIONS.
     */
    public static function powerAction(string $key, ?string $label = null): PanelAction
    {
        if (! array_key_exists($key, self::POWER_ACTIONS)) {
            throw new InvalidArgumentException("[{$key}] is not a standard power action.");
        }

        [$defaultLabel, , $destructive, $confirm] = self::POWER_ACTIONS[$key];

        return new PanelAction(
            key: $key,
            label: $label ?? __($defaultLabel),
            rules: $destructive ? ['confirm' => ['accepted']] : [],
            throttle: self::DEFAULT_THROTTLES['power.*'],
            bucket: 'power',
            confirm: $confirm === null ? null : __($confirm),
            destructive: $destructive,
        );
    }

    /**
     * The data without any key that matches SCRUB_PATTERN, at any depth.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SCRUB_PATTERN, $key) === 1) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return array_is_list($data) ? array_values($clean) : $clean;
    }
}
