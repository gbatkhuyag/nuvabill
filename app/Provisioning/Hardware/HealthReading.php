<?php

namespace App\Provisioning\Hardware;

use Illuminate\Support\Str;

/**
 * One hardware health reading from a machine's BMC, such as "CPU 1 temperature: 54 °C" or
 * "Power supply 2: failed", safe to show to staff.
 */
final readonly class HealthReading
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    public string $name;

    public string $value;

    public string $status;

    /**
     * @param  string  $status  ok, warning, critical or unknown.
     */
    public function __construct(string $name, string $value, string $status = self::UNKNOWN)
    {
        $this->name = self::clean($name, 80);
        $this->value = self::clean($value, 80);
        $this->status = in_array($status, [self::OK, self::WARNING, self::CRITICAL], true) ? $status : self::UNKNOWN;
    }

    /**
     * @return array{name: string, value: string, status: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'value' => $this->value, 'status' => $this->status];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self((string) ($row['name'] ?? ''), (string) ($row['value'] ?? ''), (string) ($row['status'] ?? self::UNKNOWN));
    }

    private static function clean(string $text, int $limit): string
    {
        return Str::limit(trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', strip_tags($text))), $limit, '…');
    }
}
