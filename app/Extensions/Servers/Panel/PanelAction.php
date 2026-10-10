<?php

namespace App\Extensions\Servers\Panel;

/**
 * One thing a client can do in a server panel section, such as "rdns-set" in "network". Core
 * validates the input against $rules, and only the keys that have rules reach the module. Core
 * also throttles it, takes the service lock when it changes the server, logs it and emails the
 * client about it. Check a declaration with PanelSection::problems().
 */
final readonly class PanelAction
{
    /**
     * The throttle an action gets when it does not name one: [max tries, per seconds].
     *
     * @var array{0: int, 1: int}
     */
    public const DEFAULT_THROTTLE = [10, 60];

    /**
     * @param  string  $key  1 to 32 lowercase letters, digits or dashes.
     * @param  string  $label  The button text, already translated.
     * @param  array<string, list<mixed>|string>  $rules  Laravel rules. Only these keys reach the module. A destructive action needs 'confirm' => ['accepted'].
     * @param  bool  $changesServer  False for actions that only read, such as showing console details. True takes the service lock and empties the read cache.
     * @param  array{0: int, 1: int}  $throttle  [max tries, per seconds] per service and bucket.
     * @param  string|null  $bucket  A throttle shared with other actions, such as "power". Null uses "{section}.{action}".
     * @param  string|null  $confirm  A question the client must confirm first, already translated.
     * @param  bool  $destructive  Shown as a red button. The client must tick a confirm box.
     * @param  bool  $reveals  The result carries a secret shown once. Never cached, and JSON only.
     * @param  string|null  $notify  English text for the "server change" email (translated when sent), or null for no email.
     * @param  list<string>  $secretFields  Input fields that are never logged and never flashed back.
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $rules = [],
        public bool $changesServer = true,
        public array $throttle = self::DEFAULT_THROTTLE,
        public ?string $bucket = null,
        public ?string $confirm = null,
        public bool $destructive = false,
        public bool $reveals = false,
        public ?string $notify = null,
        public array $secretFields = [],
    ) {}

    /**
     * The throttle bucket of this action within a section.
     */
    public function bucketFor(string $section): string
    {
        return $this->bucket ?? $section.'.'.$this->key;
    }

    /**
     * A copy with another throttle.
     *
     * @param  array{0: int, 1: int}  $throttle
     */
    public function withThrottle(array $throttle): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            rules: $this->rules,
            changesServer: $this->changesServer,
            throttle: $throttle,
            bucket: $this->bucket,
            confirm: $this->confirm,
            destructive: $this->destructive,
            reveals: $this->reveals,
            notify: $this->notify,
            secretFields: $this->secretFields,
        );
    }
}
