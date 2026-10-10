<?php

namespace App\Provisioning\Hardware\Tls;

use Closure;

/**
 * Whether this site's PHP can check a BMC's pinned key (CURLOPT_PINNEDPUBLICKEY) before any data
 * is sent.
 *
 * curl must be usable for requests (curl_exec), because without it the HTTP client falls back to
 * PHP streams, which ignore every curl option and so would skip the pin while peer checks are off.
 * curl must also be 7.39 or newer and use a TLS library that supports pins. When any of this is
 * missing, pinned mode is refused rather than run unchecked.
 */
class PinningSupport
{
    /**
     * TLS libraries of curl that check pinned keys.
     */
    private const BACKENDS = '/^(OpenSSL|LibreSSL|BoringSSL|wolfSSL|GnuTLS|Secure ?Transport)\b/i';

    /**
     * @param  (Closure(string): bool)|null  $functionExists  Replaces function_exists() in tests.
     * @param  array{version?: string, ssl_version?: string}|null  $curlVersion  Replaces curl_version() in tests.
     */
    public function __construct(
        private readonly ?Closure $functionExists = null,
        private readonly ?array $curlVersion = null,
    ) {}

    public function available(): bool
    {
        $exists = $this->functionExists ?? fn (string $name): bool => function_exists($name) && ! self::disabled($name);

        if (! defined('CURLOPT_PINNEDPUBLICKEY') || ! $exists('curl_exec') || ! $exists('curl_version')) {
            return false;
        }

        $info = $this->curlVersion ?? curl_version();

        if (! is_array($info)) {
            return false;
        }

        return version_compare((string) ($info['version'] ?? '0'), '7.39.0', '>=')
            && preg_match(self::BACKENDS, (string) ($info['ssl_version'] ?? '')) === 1;
    }

    /**
     * The text staff see when pinned mode cannot be used.
     */
    public static function unavailableMessage(): string
    {
        return __("This site's PHP cannot check BMC certificates. Ask your host to update curl, or use a certificate from a trusted CA.");
    }

    private static function disabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $disabled, true);
    }
}
