<?php

namespace App\Provisioning\Hardware;

use App\Enums\PowerState;
use App\Provisioning\Hardware\Tls\PeerCertificate;

/**
 * What "Test connection" found on a machine's BMC. It holds only what staff may see: no login, no
 * raw answer.
 */
final readonly class BmcTestResult
{
    /**
     * @param  bool  $needsTrust  The BMC's certificate must be trusted before any login is sent; $certificate is what it showed.
     * @param  list<string>  $systems  The system paths the BMC lists (Redfish), for staff to pick from when there are several.
     * @param  ?string  $systemPath  The system power actions go to, or null when staff must pick one first.
     * @param  ?string  $resetTarget  Where power actions are sent (Redfish).
     * @param  list<string>  $resetTypes  The reset types the BMC offers (Redfish).
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public bool $needsTrust = false,
        public ?PeerCertificate $certificate = null,
        public array $systems = [],
        public ?string $systemPath = null,
        public ?string $resetTarget = null,
        public array $resetTypes = [],
        public ?string $vendor = null,
        public ?string $model = null,
        public PowerState $powerState = PowerState::Unknown,
    ) {}

    public static function failed(string $message): self
    {
        return new self(ok: false, message: $message);
    }

    /**
     * The certificate must be trusted first. Nothing was sent to the BMC but the TLS handshake.
     */
    public static function trustFirst(PeerCertificate $certificate): self
    {
        return new self(
            ok: false,
            message: __('Check the BMC certificate below. Trust it only when its fingerprint matches the one on the BMC.'),
            needsTrust: true,
            certificate: $certificate,
        );
    }

    /**
     * The BMC has several systems and staff did not pick one yet.
     *
     * @param  list<string>  $systems
     */
    public static function chooseSystem(array $systems): self
    {
        return new self(
            ok: true,
            message: __('This BMC has :count systems. Choose the one for this machine, then test again.', ['count' => count($systems)]),
            systems: $systems,
        );
    }

    /**
     * Whether staff must pick a system before power actions work.
     */
    public function needsSystemChoice(): bool
    {
        return $this->ok && $this->systemPath === null && count($this->systems) > 1;
    }
}
