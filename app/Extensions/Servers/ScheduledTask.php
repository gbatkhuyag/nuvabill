<?php

namespace App\Extensions\Servers;

use Closure;

/**
 * One piece of scheduled work of a server module (RunsOnSchedule). Core runs it from the site's
 * cron job at the given frequency, never twice at the same time, and reports a failure without
 * its message (which may hold an address with a key). Since Nuvabill 0.7.0.
 */
final readonly class ScheduledTask
{
    /**
     * Frequency => the scheduler method that sets it. Every five minutes is the most often a module may run.
     *
     * @var array<string, string>
     */
    public const FREQUENCIES = [
        'every-five-minutes' => 'everyFiveMinutes',
        'every-ten-minutes' => 'everyTenMinutes',
        'every-fifteen-minutes' => 'everyFifteenMinutes',
        'every-thirty-minutes' => 'everyThirtyMinutes',
        'hourly' => 'hourly',
        'every-six-hours' => 'everySixHours',
        'daily' => 'daily',
        'weekly' => 'weekly',
    ];

    /**
     * A name: 1 to 40 lowercase letters, digits or dashes.
     */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}\z/';

    /**
     * @param  string  $name  Unique within the module, for example "traffic".
     * @param  Closure(): mixed  $callback  The work. Keep a run shorter than the frequency.
     * @param  string  $frequency  One of FREQUENCIES' keys.
     * @param  int  $lockMinutes  How long the overlap lock lasts if a run dies without freeing it (1 to 1440).
     */
    public function __construct(
        public string $name,
        public Closure $callback,
        public string $frequency = 'hourly',
        public int $lockMinutes = 30,
    ) {}

    /**
     * What is wrong with this task, or null when core can schedule it.
     */
    public function problem(): ?string
    {
        if (preg_match(self::NAME_PATTERN, $this->name) !== 1) {
            return 'The name must be 1 to 40 lowercase letters, digits or dashes.';
        }

        if (! array_key_exists($this->frequency, self::FREQUENCIES)) {
            return "The frequency [{$this->frequency}] is not one of ".implode(', ', array_keys(self::FREQUENCIES)).'.';
        }

        if ($this->lockMinutes < 1 || $this->lockMinutes > 1440) {
            return 'The lock must last 1 to 1440 minutes.';
        }

        return null;
    }
}
