<?php

namespace App\Extensions\Servers\Resources;

/**
 * The extras a service has on top of its plan, per kind, in units (not steps). A kind present with
 * 0 goes back to the plan; a kind that is missing is left as it is on the server. Modules read a
 * service's extras with $service->resourceExtras().
 */
final readonly class ResourceExtras
{
    /**
     * @param  array<string, int>  $units  kind => extra units on top of the plan.
     */
    public function __construct(public array $units) {}

    /**
     * Whether the kind is listed, even with 0.
     */
    public function has(string $kind): bool
    {
        return array_key_exists($kind, $this->units);
    }

    /**
     * The extra units of a kind, or 0 when it is not listed.
     */
    public function get(string $kind): int
    {
        return (int) ($this->units[$kind] ?? 0);
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_map('strval', array_keys($this->units));
    }

    /**
     * Whether there are no extras at all: every listed kind is 0.
     */
    public function isEmpty(): bool
    {
        foreach ($this->units as $units) {
            if ((int) $units !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The same extras with only these kinds, where they are listed.
     *
     * @param  list<string>  $kinds
     */
    public function only(array $kinds): self
    {
        return new self(array_intersect_key($this->units, array_flip($kinds)));
    }
}
