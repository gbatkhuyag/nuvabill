<?php

namespace App\Contracts;

use App\Extensions\Servers\ScheduledTask;

/**
 * A server module with scheduled work, run by the site's one cron job (schedule:run): no extra
 * cron lines. Each task runs at one of the frequencies in ScheduledTask, never twice at the same
 * time, and on one server only when the cache can hold locks. The manifest must list "schedule"
 * in its permissions. Since Nuvabill 0.7.0.
 */
interface RunsOnSchedule
{
    /**
     * @return list<ScheduledTask>
     */
    public function scheduledTasks(): array;
}
