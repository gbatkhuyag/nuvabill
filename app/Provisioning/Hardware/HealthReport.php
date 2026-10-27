<?php

namespace App\Provisioning\Hardware;

use Illuminate\Support\Carbon;

/**
 * What PowerControl::health() last read from a machine's BMC: the health readings, the power used
 * in watts, and the error when the BMC did not answer. Only staff see it.
 */
final readonly class HealthReport
{
    /**
     * @param  list<HealthReading>  $readings
     */
    public function __construct(
        public array $readings,
        public ?int $watts,
        public Carbon $readAt,
        public ?string $error = null,
    ) {}

    /**
     * The worst status of all readings: critical, warning, ok or unknown.
     */
    public function status(): string
    {
        $statuses = array_map(fn (HealthReading $reading): string => $reading->status, $this->readings);

        foreach ([HealthReading::CRITICAL, HealthReading::WARNING, HealthReading::OK] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return HealthReading::UNKNOWN;
    }

    /**
     * @return array{readings: list<array{name: string, value: string, status: string}>, watts: ?int, read_at: string, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'readings' => array_map(fn (HealthReading $reading): array => $reading->toArray(), $this->readings),
            'watts' => $this->watts,
            'read_at' => $this->readAt->toIso8601String(),
            'error' => $this->error,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_values(array_map(fn (mixed $row): HealthReading => HealthReading::fromArray(is_array($row) ? $row : []), (array) ($data['readings'] ?? []))),
            isset($data['watts']) && is_int($data['watts']) ? $data['watts'] : null,
            Carbon::parse((string) ($data['read_at'] ?? 'now')),
            isset($data['error']) && is_string($data['error']) ? $data['error'] : null,
        );
    }
}
