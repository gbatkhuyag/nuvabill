<?php

namespace App\Provisioning\Hardware\Tls;

use App\Provisioning\Hardware\BmcException;
use OpenSSLCertificate;
use Throwable;

/**
 * Opens a TLS connection to the BMC only to read its certificate: no HTTP request and no login is
 * sent, and the connection is closed straight after the handshake. The certificate is not checked
 * here; staff check its fingerprint before they trust it.
 */
final class StreamCertificateProbe implements CertificateProbe
{
    public const TIMEOUT_SECONDS = 5;

    public function fetch(string $ip, int $port, string $serverName): PeerCertificate
    {
        $isName = filter_var($serverName, FILTER_VALIDATE_IP) === false;
        $ssl = [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => $isName,
        ];

        if ($isName) {
            $ssl['peer_name'] = $serverName;
        }

        $target = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $stream = @stream_socket_client("ssl://{$target}:{$port}", $errorCode, $errorText, self::TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));

        if ($stream === false) {
            throw BmcException::unreachable();
        }

        try {
            $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        } finally {
            fclose($stream);
        }

        if (! $certificate instanceof OpenSSLCertificate) {
            throw BmcException::unreachable(__('The BMC did not show a certificate.'));
        }

        try {
            return PeerCertificate::fromX509($certificate);
        } catch (Throwable) {
            throw BmcException::refused(__('The BMC certificate cannot be read.'));
        }
    }
}
