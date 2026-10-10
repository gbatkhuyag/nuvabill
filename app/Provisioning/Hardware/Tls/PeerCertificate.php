<?php

namespace App\Provisioning\Hardware\Tls;

use Carbon\CarbonImmutable;
use OpenSSLCertificate;
use RuntimeException;

/**
 * The certificate a BMC showed: what staff compare with the BMC's own page before they trust it,
 * and the key pin Nuvabill then checks on every connection.
 */
final readonly class PeerCertificate
{
    /**
     * @param  string  $fingerprint  SHA-256 of the whole certificate, as AA:BB:…
     * @param  string  $pin  base64 of the SHA-256 of the public key (SPKI DER), for CURLOPT_PINNEDPUBLICKEY.
     * @param  string  $subject  The certificate's common name, cleaned for display.
     */
    public function __construct(
        public string $fingerprint,
        public string $pin,
        public string $subject,
        public ?CarbonImmutable $validTo = null,
    ) {}

    /**
     * Read a certificate.
     *
     * @throws RuntimeException when it cannot be read.
     */
    public static function fromX509(OpenSSLCertificate|string $certificate): self
    {
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        $publicKey = openssl_pkey_get_public($certificate);
        $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);

        if ($fingerprint === false || ! is_array($details) || ! is_string($details['key'] ?? null)) {
            throw new RuntimeException('The certificate cannot be read.');
        }

        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $details['key']), true);

        if ($der === false || $der === '') {
            throw new RuntimeException('The certificate key cannot be read.');
        }

        $parsed = openssl_x509_parse($certificate);
        $subject = is_array($parsed) ? ($parsed['subject']['CN'] ?? $parsed['name'] ?? '') : '';
        $subject = is_array($subject) ? implode(', ', array_map('strval', $subject)) : (string) $subject;
        $validTo = is_array($parsed) && is_int($parsed['validTo_time_t'] ?? null) ? CarbonImmutable::createFromTimestamp($parsed['validTo_time_t']) : null;

        return new self(
            fingerprint: (string) self::normalizeFingerprint($fingerprint),
            pin: base64_encode(hash('sha256', $der, true)),
            subject: mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', $subject)), 0, 190),
            validTo: $validTo,
        );
    }

    /**
     * A SHA-256 fingerprint in the form AA:BB:…, from upper or lower case hex with or without
     * colons, or null when it is not one.
     */
    public static function normalizeFingerprint(string $value): ?string
    {
        $hex = strtoupper(str_replace([':', ' '], '', trim($value)));

        return preg_match('/^[0-9A-F]{64}$/D', $hex) === 1 ? implode(':', str_split($hex, 2)) : null;
    }

    /**
     * Whether two fingerprints are the same certificate.
     */
    public static function sameFingerprint(string $first, string $second): bool
    {
        $first = self::normalizeFingerprint($first);
        $second = self::normalizeFingerprint($second);

        return $first !== null && $second !== null && hash_equals($first, $second);
    }

    /**
     * What staff see: never the pin, which is not secret but means nothing to them.
     *
     * @return array{fingerprint: string, subject: string, valid_to: ?string}
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'subject' => $this->subject,
            'valid_to' => $this->validTo?->toIso8601String(),
        ];
    }
}
