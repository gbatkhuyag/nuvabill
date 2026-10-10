<?php

namespace App\Extensions\Servers\Panel;

/**
 * The outcome of a server panel read or action. Core turns it into the JSON answer (or a flash
 * message without JavaScript). The secret is shown to the client once, and only for actions
 * declared with reveals; staffDetail and logDetail never reach the browser.
 */
final readonly class PanelResult
{
    /**
     * HTTP statuses a failed result may carry. Anything else becomes 422.
     *
     * @var list<int>
     */
    public const FAIL_STATUSES = [403, 404, 409, 410, 413, 422, 429, 503];

    /**
     * @param  array<string, mixed>  $data  The section's standard data shape, or the action's extra values.
     * @param  string|null  $pending  A power action still running, such as "restart".
     * @param  string|null  $state  The server state now: running, stopped, suspended, busy or unknown.
     * @param  list<array{label: string, value: string}>|null  $secret  Shown to the client once, only for actions with reveals.
     * @param  string|null  $staffDetail  For the staff log, never sent to the browser.
     * @param  string|null  $logDetail  A safe summary for the activity log, for example "192.0.2.7 → mail.example.com".
     * @param  int  $status  200, or one of FAIL_STATUSES.
     * @param  int|null  $retryAfter  Seconds to wait, with a 429.
     * @param  array<string, list<string>>  $errors  Messages per field, with a 422 for input that did not pass.
     */
    private function __construct(
        public bool $ok,
        public string $message,
        public array $data,
        public ?string $pending,
        public ?string $state,
        public ?array $secret,
        public ?string $staffDetail,
        public ?string $logDetail,
        public int $status,
        public ?int $retryAfter,
        public array $errors = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(string $message = '', array $data = [], ?string $pending = null, ?string $state = null, ?string $logDetail = null): self
    {
        return new self(true, $message, $data, $pending, $state, null, null, $logDetail, 200, null);
    }

    /**
     * A result with a value the client must see once, such as a new root password.
     *
     * @param  list<array{label: string, value: string}>|array<string, string>  $secret  Rows of label and value, or label => value.
     * @param  array<string, mixed>  $data
     */
    public static function secret(string $message, array $secret, array $data = [], ?string $logDetail = null): self
    {
        $rows = [];

        foreach ($secret as $label => $row) {
            if (is_array($row) && is_string($row['label'] ?? null) && is_string($row['value'] ?? null)) {
                $rows[] = ['label' => $row['label'], 'value' => $row['value']];
            } elseif (is_string($label) && is_string($row)) {
                $rows[] = ['label' => $label, 'value' => $row];
            }
        }

        return new self(true, $message, $data, null, null, $rows, null, $logDetail, 200, null);
    }

    public static function fail(string $message, ?string $staffDetail = null, int $status = 422): self
    {
        $status = in_array($status, self::FAIL_STATUSES, true) ? $status : 422;

        return new self(false, $message, [], null, null, null, $staffDetail, null, $status, null);
    }

    /**
     * Input that did not pass the section's or action's rules.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function invalid(array $errors, ?string $message = null): self
    {
        $message ??= (string) (collect($errors)->flatten()->first() ?? __('Check the form and try again.'));

        return new self(false, $message, [], null, null, null, null, null, 422, null, $errors);
    }

    /**
     * The feature is off or not allowed for this service (403).
     */
    public static function unavailable(string $message, ?string $staffDetail = null): self
    {
        return self::fail($message, $staffDetail, 403);
    }

    /**
     * Too many tries (429), with the seconds to wait.
     */
    public static function throttled(int $seconds): self
    {
        $seconds = max(1, $seconds);
        $message = trans_choice('Too many tries. Wait :seconds second, then try again.|Too many tries. Wait :seconds seconds, then try again.', $seconds, ['seconds' => $seconds]);

        return new self(false, $message, [], null, null, null, null, null, 429, $seconds);
    }

    /**
     * A copy with every data key that looks like a secret removed (StandardSections::SCRUB_PATTERN),
     * and without the secret unless the action is allowed to reveal one.
     */
    public function scrubbed(bool $keepSecret = false): self
    {
        return new self(
            $this->ok,
            $this->message,
            StandardSections::scrub($this->data),
            $this->pending,
            $this->state,
            $keepSecret ? $this->secret : null,
            $this->staffDetail,
            $this->logDetail,
            $this->status,
            $this->retryAfter,
            $this->errors,
        );
    }

    /**
     * Keeps the secret values out of dumps and error pages.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $values = get_object_vars($this);

        if ($this->secret !== null) {
            $values['secret'] = array_map(fn (array $row): array => ['label' => $row['label'], 'value' => '[hidden]'], $this->secret);
        }

        return $values;
    }
}
