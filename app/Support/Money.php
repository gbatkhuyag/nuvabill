<?php

namespace App\Support;

use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Money is stored as integer minor units (cents). These helpers convert and format it.
 */
class Money
{
    /**
     * Some currencies, such as IQD, show whole units by default. An amount with cents is still
     * shown with them, so the shown amount is always the amount stored and owed. Currencies with
     * three decimals by default (KWD, BHD and others) always show the two that are stored, so
     * one currency does not show 1,234.000 next to 1,234.56.
     */
    public static function format(int $minor, string $currency): string
    {
        $precision = $minor % 100 !== 0 || self::defaultDecimals($currency) > 2 ? 2 : null;

        return Number::currency($minor / 100, in: $currency, locale: Locales::numberLocale(), precision: $precision);
    }

    /**
     * How many decimals the server's language data shows for a currency, or 2 when it cannot say.
     */
    private static function defaultDecimals(string $currency): int
    {
        static $known = [];
        $currency = strtoupper($currency);

        if (! class_exists(\NumberFormatter::class) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return 2;
        }

        if (! isset($known[$currency])) {
            $digits = (new \NumberFormatter("en@currency={$currency}", \NumberFormatter::CURRENCY))->getAttribute(\NumberFormatter::FRACTION_DIGITS);
            $known[$currency] = is_int($digits) ? $digits : 2;
        }

        return $known[$currency];
    }

    /**
     * Convert a typed amount such as "12.50" or "1,200" into minor units.
     */
    public static function toMinor(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $normalized = str_replace([',', ' '], '', (string) $amount);

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("[{$amount}] is not a valid amount.");
        }

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Minor units as a plain decimal string for form fields, for example 1250 => "12.50".
     */
    public static function toDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
