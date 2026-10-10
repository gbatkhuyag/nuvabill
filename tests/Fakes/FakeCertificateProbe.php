<?php

namespace Tests\Fakes;

use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PeerCertificate;

/**
 * Shows a set BMC certificate instead of connecting, and records each call.
 */
class FakeCertificateProbe implements CertificateProbe
{
    /**
     * @var list<array{0: string, 1: int, 2: string}>
     */
    public array $calls = [];

    public function __construct(public PeerCertificate $certificate) {}

    public function fetch(string $ip, int $port, string $serverName): PeerCertificate
    {
        $this->calls[] = [$ip, $port, $serverName];

        return $this->certificate;
    }

    /**
     * A certificate as a fake BMC shows it; each name is another key.
     */
    public static function certificate(string $name): PeerCertificate
    {
        return new PeerCertificate(
            fingerprint: (string) PeerCertificate::normalizeFingerprint(hash('sha256', "certificate {$name}")),
            pin: base64_encode(hash('sha256', "key {$name}", true)),
            subject: 'bmc-'.$name,
        );
    }
}
