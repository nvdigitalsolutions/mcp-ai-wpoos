<?php

/**
 * The Food & Beverage toolkit's settings, typed.
 *
 * Three things the toolkit reads from `wp_mcp_ai_fnb_toolkit_settings` affect a
 * figure rather than a location: the **currency** (which decides how many
 * decimals every money value is rounded to), the **as-of date** that M-14 and
 * M-21 default to, and the table map the source reads. `FnbTableSource` reads
 * the map; this class reads the other two, so the metric engine does not have to
 * know the option shape.
 *
 * ## Currency precision, ported
 *
 * `WP_MCP_AI_Fnb_Tenant_Profile::currency_decimals()` is the source: `JPY`,
 * `KRW`, `VND` and `LKR` round to zero decimals and every other code rounds to
 * two. That matters because nearly every `r()` call in the engine passes no
 * decimal count, so the tenant's currency decides the precision of every money
 * figure. A port that hardcoded 2 would move LKR figures by a rounding step.
 *
 * ## As-of date
 *
 * WordPress defaults it to the current GMT date and lets an operator pin it, so
 * a report can be reproduced on a later day. The same default here, read at call
 * time rather than construction, so a long-lived worker does not freeze the date
 * at boot.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

final class FnbSettings
{
    /** The WordPress option the toolkit settings live in. */
    public const OPTION = 'wp_mcp_ai_fnb_toolkit_settings';

    /** Currencies whose ISO 4217 minor unit is zero. */
    public const ZERO_DECIMAL_CURRENCIES = array('JPY', 'KRW', 'VND', 'LKR');

    /**
     * The symbols `WP_MCP_AI_Fnb_Tenant_Profile::currency_symbol()` returns.
     *
     * A code that is not listed is its own symbol, which is why an operator's
     * unlisted currency still produces a labelled figure rather than a bare
     * number.
     */
    public const CURRENCY_SYMBOLS = array(
        'LKR' => 'Rs',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'INR' => '₹',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'NZD' => 'NZ$',
        'SGD' => 'S$',
        'AED' => 'AED',
        'SAR' => 'SAR',
        'ZAR' => 'R',
        'BRL' => 'R$',
        'MXN' => 'MX$',
        'CHF' => 'CHF',
        'SEK' => 'kr',
        'DKK' => 'kr',
        'NOK' => 'kr',
        'HKD' => 'HK$',
        'CNY' => '¥',
        'KRW' => '₩',
        'VND' => '₫',
        'IDR' => 'Rp',
        'TRY' => '₺',
        'RUB' => '₽',
        'PLN' => 'zł',
        'THB' => '฿',
    );

    public function __construct(
        private readonly ?SettingsStoreInterface $settings = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = null !== $this->settings ? $this->settings->get(self::OPTION, array()) : array();

        return is_array($stored) ? $stored : array();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[ $key ] ?? $default;
    }

    /**
     * The date the demand and margin metrics default to, `Y-m-d`.
     */
    public function asOfDate(): string
    {
        $stored = $this->get('as_of_date', '');
        $date   = is_string($stored) ? FnbTableCatalogue::dateOnly($stored) : '';

        return '' !== $date ? $date : (string) gmdate('Y-m-d');
    }

    /**
     * The configured ISO-4217 code, upper-case, or `''`.
     */
    public function currency(): string
    {
        $code = $this->get('currency', '');

        return is_string($code) ? strtoupper(trim($code)) : '';
    }

    /**
     * Decimal places for money in this currency.
     */
    public function currencyDecimals(): int
    {
        return in_array($this->currency(), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
    }

    /**
     * The configured currency's symbol, or `''` when no currency is configured.
     *
     * An unlisted code answers with the code itself, as the tenant profile does.
     */
    public function currencySymbol(): string
    {
        $code = $this->currency();

        if ('' === $code) {
            return '';
        }

        return self::CURRENCY_SYMBOLS[ $code ] ?? $code;
    }

    /**
     * A money figure as the reports print it: symbol, space, grouped amount.
     *
     * `WP_MCP_AI_Fnb_Report_Builder::money()` delegates to the tenant profile,
     * whose decimals come from the currency (not from the report). The docblock
     * there claims whole units; the code does not round to them, and the code is
     * what this port follows. No currency configured means no symbol, so a fresh
     * install never asserts the wrong one.
     */
    public function formatMoney(float $amount): string
    {
        $formatted = number_format($amount, $this->currencyDecimals(), '.', ',');
        $symbol    = $this->currencySymbol();

        return '' === $symbol ? $formatted : $symbol . ' ' . $formatted;
    }
}
