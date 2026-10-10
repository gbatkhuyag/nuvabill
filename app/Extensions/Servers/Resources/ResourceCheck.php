<?php

namespace App\Extensions\Servers\Resources;

/**
 * What the live server has, compared with the plan plus the service's extras. It changes nothing.
 */
final readonly class ResourceCheck
{
    /**
     * @param  array<string, int>  $expected  kind => total units (plan plus extras).
     * @param  array<string, int|null>  $live  kind => what the server has, or null when it could not be read.
     * @param  list<string>  $problems  Plain English for staff, such as "RAM: 8192 MB, expected 12288 MB".
     */
    public function __construct(public array $expected, public array $live, public array $problems = []) {}

    /**
     * Whether the server has exactly what is expected for every kind, and nothing was reported.
     */
    public function matches(): bool
    {
        if ($this->problems !== []) {
            return false;
        }

        foreach ($this->expected as $kind => $units) {
            $live = $this->live[$kind] ?? null;

            if ($live === null || (int) $live !== (int) $units) {
                return false;
            }
        }

        return true;
    }
}
