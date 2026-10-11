<?php

namespace App\Provisioning\Hardware;

use App\Enums\PowerAction;
use App\Enums\PowerState;

/**
 * The answer to a power action, safe to show to whoever pressed the button.
 */
final readonly class PowerResult
{
    /**
     * @param  ?PowerAction  $pending  The action the BMC accepted and that may still be under way, so a page can check the state again.
     * @param  bool  $busy  Another power action on this machine is running.
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public ?PowerAction $pending = null,
        public PowerState $state = PowerState::Unknown,
        public bool $busy = false,
    ) {}

    public static function done(string $message, ?PowerAction $pending = null, PowerState $state = PowerState::Unknown): self
    {
        return new self(true, $message, $pending, $state);
    }

    public static function failed(string $message, PowerState $state = PowerState::Unknown, bool $busy = false): self
    {
        return new self(false, $message, null, $state, $busy);
    }

    /**
     * @return array{ok: bool, message: string, pending: ?string, state: string, label: string, busy: bool}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'pending' => $this->pending?->value,
            'state' => $this->state->panelState(),
            'label' => $this->state->label(),
            'busy' => $this->busy,
        ];
    }
}
