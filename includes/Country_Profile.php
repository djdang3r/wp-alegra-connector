<?php
/**
 * Country profile — the single resolver for the account country and the
 * country-dependent shape of the invoice `discount` field.
 *
 * The plugin already stores the Alegra company country when the connection is
 * tested (`alegra_connector_company_country`). This class adds an optional
 * explicit override (`alegra_connector_account_country`) and keeps Colombia as
 * the default, so every invoice-related decision reads ONE source of truth.
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

final class Country_Profile
{
    public const OPTION_ACCOUNT_COUNTRY   = 'alegra_connector_account_country';
    public const OPTION_COMPANY_COUNTRY   = 'alegra_connector_company_country';
    public const OPTION_CR_DISCOUNT_NATURE = 'alegra_connector_cr_discount_nature';

    /** Colombia is the default until the merchant configures otherwise. */
    public const DEFAULT_COUNTRY = 'CO';

    /**
     * Resolve the effective account country.
     *
     * Precedence: explicit `account_country` → stored `company_country` → `CO`.
     */
    public static function country(): string
    {
        $account = self::normalize((string) get_option(self::OPTION_ACCOUNT_COUNTRY, ''));
        if ($account !== '') {
            return $account;
        }

        $company = self::normalize((string) get_option(self::OPTION_COMPANY_COUNTRY, ''));
        if ($company !== '') {
            return $company;
        }

        return self::DEFAULT_COUNTRY;
    }

    /**
     * Normalize a country value to its ISO-ish uppercase form.
     *
     * Only spelling variants of the names the plugin already supports are
     * normalized; no country is hardcoded as a fiscal rule.
     */
    public static function normalize(string $country): string
    {
        $country = strtoupper(trim($country));

        return match ($country) {
            'COLOMBIA'            => 'CO',
            'COSTA RICA', 'COSTA_RICA' => 'CR',
            default               => $country,
        };
    }

    public static function is_colombia(): bool
    {
        return self::country() === 'CO';
    }

    public static function is_costa_rica(): bool
    {
        return self::country() === 'CR';
    }

    /**
     * Optional Costa Rica fiscal `nature`.
     *
     * The Costa Rica object branch is DEFERRED: it stays off unless the
     * merchant explicitly configures a nature. No default is invented.
     */
    public static function cr_discount_nature(): string
    {
        return trim((string) get_option(self::OPTION_CR_DISCOUNT_NATURE, ''));
    }

    /**
     * Shape of `items[].discount` for the effective country.
     *
     * Default (and the only active behavior today) is a numeric percentage.
     * The Costa Rica `{nature, discount}` structure exists as an optional,
     * disabled-by-default branch for future e-invoicing needs.
     *
     * @return float|array{nature:string,discount:float}
     */
    public static function discount_payload(float $percent): float|array
    {
        $percent = round($percent, 4);
        if ($percent <= 0) {
            return 0.0;
        }

        if (self::is_costa_rica()) {
            $nature = self::cr_discount_nature();
            if ($nature !== '') {
                return ['nature' => $nature, 'discount' => $percent];
            }
        }

        return $percent;
    }

    /**
     * Read-only country hint for the settings UI.
     *
     * The `company_country` value is written once by the connection test, so
     * this never performs an API call per invoice. It only reports what is
     * stored and whether it matches the effective value; it never overrides.
     *
     * @return array{country:string,detected:string,source:string,matches:bool}
     */
    public static function suggestion(): array
    {
        $account  = self::normalize((string) get_option(self::OPTION_ACCOUNT_COUNTRY, ''));
        $detected = self::normalize((string) get_option(self::OPTION_COMPANY_COUNTRY, ''));
        $country  = self::country();

        $source = $account !== '' ? 'account' : ($detected !== '' ? 'company' : 'default');

        return [
            'country'  => $country,
            'detected' => $detected,
            'source'   => $source,
            'matches'  => $detected === '' || $country === $detected,
        ];
    }
}
