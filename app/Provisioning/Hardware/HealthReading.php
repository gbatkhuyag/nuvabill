<?php

namespace App\Provisioning\Hardware;

use Illuminate\Support\Str;

/**
 * One hardware health reading from a machine's BMC, such as "CPU 1 temperature: 54 °C" or
 * "Power supply 2: failed", safe to show to staff.
 *
 * The key says which part of the BMC the reading came from and which sensor it is, as "part:id"
 * (for example "thermal:CPU1 Temp"), in no language, so a health check can follow one sensor from
 * read to read while its name is translated. A part the driver could not read this time (it timed
 * out or the BMC refused it) is a reading with the key "skipped:part", so nothing from that part
 * is taken as fixed just because it was not read.
 */
final readonly class HealthReading
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    /** The key prefix of a part that was not read this time. */
    public const SKIPPED = 'skipped:';

    public string $name;

    public string $value;

    public string $status;

    public string $key;

    /**
     * @param  string  $status  ok, warning, critical or unknown.
     * @param  string  $key  "part:id", the same in every language; empty when the driver has none.
     */
    public function __construct(string $name, string $value, string $status = self::UNKNOWN, string $key = '')
    {
        $this->name = self::clean($name, 80);
        $this->value = self::clean($value, 80);
        $this->status = in_array($status, [self::OK, self::WARNING, self::CRITICAL], true) ? $status : self::UNKNOWN;
        $this->key = self::clean($key, 160);
    }

    /**
     * The part this reading says was not read this time, or null.
     */
    public function skippedPart(): ?string
    {
        return str_starts_with($this->key, self::SKIPPED) && strlen($this->key) > strlen(self::SKIPPED) ? substr($this->key, strlen(self::SKIPPED)) : null;
    }

    /**
     * @return array{name: string, value: string, status: string, key: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'value' => $this->value, 'status' => $this->status, 'key' => $this->key];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self((string) ($row['name'] ?? ''), (string) ($row['value'] ?? ''), (string) ($row['status'] ?? self::UNKNOWN), (string) ($row['key'] ?? ''));
    }

    private static function clean(string $text, int $limit): string
    {
        return Str::limit(trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', strip_tags($text))), $limit, '…');
    }
}
