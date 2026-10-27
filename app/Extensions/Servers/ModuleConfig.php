<?php

namespace App\Extensions\Servers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Limits and secrets of product settings (module_config), as a module declares them in
 * productFields(). A "textarea" may be up to 16 KB long with "max"; every other field stays at
 * 190 characters. A field marked "secret" (or of type "password") is stored encrypted with the app
 * key, is never filled into the product form again (left empty, the saved value stays), and
 * Module::productSetting() gives the plain value. Since Nuvabill 0.7.0.
 */
final class ModuleConfig
{
    /**
     * Marks an encrypted value in module_config.
     */
    public const PREFIX = 'nb-secret:';

    /**
     * The longest value a "textarea" product field may declare with "max".
     */
    public const MAX_LONG = 16384;

    /**
     * The longest value of any other product field, and of a textarea without "max".
     */
    public const MAX_SHORT = 190;

    /**
     * The most all of a product's settings may hold together, in characters.
     */
    public const MAX_TOTAL = 65536;

    public static function encrypt(string $value): string
    {
        return self::PREFIX.Crypt::encryptString($value);
    }

    public static function isEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * The plain value: decrypted when it was stored encrypted, or null when it cannot be read (for
     * example after the app key changed).
     */
    public static function read(mixed $value): mixed
    {
        if (! self::isEncrypted($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * The longest value a declared product field allows.
     *
     * @param  array<string, mixed>  $field
     */
    public static function maxLength(array $field): int
    {
        if (($field['type'] ?? null) !== 'textarea' || ! is_int($field['max'] ?? null)) {
            return self::MAX_SHORT;
        }

        return max(1, min(self::MAX_LONG, $field['max']));
    }

    /**
     * Whether a declared field is kept encrypted: marked "secret", or a password.
     *
     * @param  array<string, mixed>  $field
     */
    public static function isSecret(array $field): bool
    {
        return ($field['secret'] ?? false) === true || ($field['type'] ?? null) === 'password';
    }
}
