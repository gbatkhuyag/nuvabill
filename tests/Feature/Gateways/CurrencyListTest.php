<?php

namespace Tests\Feature\Gateways;

use App\Extensions\ExtensionManager;
use App\Http\Controllers\Admin\SettingsController;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NumberFormatter;
use ResourceBundle;
use Tests\TestCase;

/**
 * Nuvabill stores every amount with two decimal places. A gateway may only take a currency it
 * charges with exactly that amount; any other currency must be refused by its supportsCurrency.
 */
class CurrencyListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ISO 4217 currencies whose minor unit is not two decimal places.
     */
    private const ISO_NO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const ISO_THREE_DECIMALS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /**
     * Stripe's zero-decimal and three-decimal currencies, and ISK and UGX, which Stripe takes only
     * with 00 as the decimals (docs.stripe.com/currencies).
     */
    private const STRIPE_NOT_TWO_DECIMALS = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        'BHD', 'JOD', 'KWD', 'OMR', 'TND',
        'ISK',
    ];

    /**
     * PayPal's currencies without two decimal places (HUF, JPY and TWD have none at PayPal).
     */
    private const PAYPAL_NOT_TWO_DECIMALS = ['HUF', 'JPY', 'TWD'];

    /**
     * Currencies the server's language data (CLDR) shows without decimals, although ISO 4217 and
     * Stripe count them with two. Each was checked by hand; a currency is only added here after that.
     */
    private const CHECKED_TWO_DECIMALS = ['AFN', 'ALL', 'LAK', 'LBP', 'MMK', 'RSD', 'SOS', 'YER'];

    public function test_the_currency_list_has_only_current_codes_and_no_sanctioned_ones(): void
    {
        $codes = SettingsController::CURRENCIES;

        $this->assertSame(array_values(array_unique($codes)), $codes, 'No currency is listed twice.');
        $this->assertSame($codes, collect($codes)->sort()->values()->all(), 'The list is in alphabetical order.');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $code);
        }

        // Kept on purpose: hosts may bill in them; card gateways refuse them below.
        foreach (['KPW', 'IRR', 'SYP', 'CUP', 'VED'] as $kept) {
            $this->assertContains($kept, $codes);
        }

        // Withdrawn codes that were replaced by a new one.
        foreach (['ANG', 'HRK', 'SLL', 'MRO', 'STD', 'VEF', 'ZWL', 'CUC', 'BYR'] as $withdrawn) {
            $this->assertNotContains($withdrawn, $codes);
        }

        // The currencies offered before the list grew are all still there, so no site loses its currency.
        foreach (['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'RON', 'HUF', 'TRY', 'AED', 'SAR', 'QAR', 'IQD', 'EGP', 'MAD', 'INR', 'PKR', 'BDT', 'IDR', 'MYR', 'SGD', 'HKD', 'PHP', 'THB', 'CNY', 'BRL', 'MXN', 'ARS', 'ZAR', 'NGN', 'KES', 'UAH'] as $old) {
            $this->assertContains($old, $codes);
        }

        $bundle = class_exists(ResourceBundle::class) ? ResourceBundle::create('en', 'ICUDATA-curr') : null;

        if ($bundle !== null) {
            foreach ($codes as $code) {
                $this->assertNotNull($bundle['Currencies'][$code] ?? null, "{$code} is a currency the server's language data knows.");
            }
        }
    }

    public function test_every_currency_is_charged_correctly_or_refused_by_each_gateway(): void
    {
        $gateways = app(ExtensionManager::class);
        $notTwoDecimals = [...self::ISO_NO_DECIMALS, ...self::ISO_THREE_DECIMALS];

        foreach (SettingsController::CURRENCIES as $code) {
            if ($gateways->gateway('stripe')->supportsCurrency($code)) {
                $this->assertNotContains($code, [...self::STRIPE_NOT_TWO_DECIMALS, ...$notTwoDecimals], "Stripe would charge {$code} with the wrong amount.");
            }

            if ($gateways->gateway('paypal')->supportsCurrency($code)) {
                $this->assertNotContains($code, [...self::PAYPAL_NOT_TWO_DECIMALS, ...$notTwoDecimals], "PayPal would charge {$code} with the wrong amount.");
            }

            // The Iraqi payments charge whole dinars, rounded up, and take nothing else.
            foreach (['fib', 'fastpay', 'wayl'] as $iraqi) {
                $this->assertSame($code === 'IQD', $gateways->gateway($iraqi)->supportsCurrency($code), "{$iraqi} and {$code}");
            }

            // Lower case codes are treated the same.
            $this->assertSame($gateways->gateway('stripe')->supportsCurrency($code), $gateways->gateway('stripe')->supportsCurrency(strtolower($code)));
        }

        // A currency the card gateways cannot charge, or not a currency at all, is never charged by them.
        foreach (['KPW', 'IRR', 'SYP', 'CUP', 'VED', 'XXX', 'ABC', ''] as $unknown) {
            $this->assertFalse($gateways->gateway('stripe')->supportsCurrency($unknown), "stripe and {$unknown}");
            $this->assertFalse($gateways->gateway('paypal')->supportsCurrency($unknown));
            $this->assertFalse($gateways->gateway('fib')->supportsCurrency($unknown));
        }
    }

    /**
     * A second check that does not rest on the lists above: the server's language data says how
     * many decimals each currency has, so a currency added later without decimals or with three is
     * caught even when nobody updated the lists in this test.
     */
    public function test_two_decimal_gateways_refuse_currencies_the_language_data_does_not_count_with_two_decimals(): void
    {
        if (! class_exists(NumberFormatter::class)) {
            $this->markTestSkipped('The intl extension is not installed.');
        }

        $gateways = app(ExtensionManager::class);

        foreach (SettingsController::CURRENCIES as $code) {
            $digits = (new NumberFormatter("en@currency={$code}", NumberFormatter::CURRENCY))->getAttribute(NumberFormatter::FRACTION_DIGITS);

            if ($digits === 2 || in_array($code, self::CHECKED_TWO_DECIMALS, true)) {
                continue;
            }

            foreach (['stripe', 'paypal'] as $gateway) {
                $this->assertFalse($gateways->gateway($gateway)->supportsCurrency($code), "{$gateway} would charge {$code} ({$digits} decimals) with the wrong amount.");
            }
        }
    }

    public function test_every_currency_shows_the_amount_that_is_owed(): void
    {
        foreach (SettingsController::CURRENCIES as $code) {
            $shown = Money::format(123456, $code);

            $this->assertMatchesRegularExpression('/1,?234\.56/u', $shown, "{$code}: {$shown}");

            if (class_exists(NumberFormatter::class)) {
                $this->assertMatchesRegularExpression('/1,?234/u', Money::format(123400, $code), $code);
            }
        }

        // Three-decimal currencies show the two stored decimals for whole amounts too, never 1,234.000.
        if (class_exists(NumberFormatter::class)) {
            foreach (['BHD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'] as $code) {
                $this->assertMatchesRegularExpression('/1,?234\.00(?!\d)/u', Money::format(123400, $code), $code);
                $this->assertStringNotContainsString('234.000', Money::format(123400, $code), $code);
            }

            // Other currencies keep their usual look for whole amounts.
            $this->assertStringNotContainsString('.00', Money::format(123400, 'JPY'));
        }
    }
}
