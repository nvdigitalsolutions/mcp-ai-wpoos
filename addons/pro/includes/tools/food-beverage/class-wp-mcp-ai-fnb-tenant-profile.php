<?php
/**
 * Food & Beverage Management Toolkit — Tenant Profile.
 *
 * Single source of truth for the venue/currency/locale context that used to be
 * hard-coded into the toolkit's column keys, metric titles, report copy and
 * assistant prompts (Surf Club Midigama / LKR). Every layer that renders money,
 * venue or locale text reads from here, and both assistant-delivery surfaces
 * (the canonical packs seeder and the blueprint import tool) pass their prompt
 * strings through render() so the operator's configured identity is applied
 * identically regardless of how the assistant was installed.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tenant profile wrapper.
 *
 * Reads the `wp_mcp_ai_fnb_settings` option (managed by
 * WP_MCP_AI_Fnb_Settings). All accessors are safe to call before the option
 * exists — they resolve to neutral fallbacks rather than assuming any single
 * venue, currency or locale.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Tenant_Profile {

	/**
	 * Tenant profile keys and their neutral fallbacks.
	 *
	 * @var array<string,string>
	 */
	private static $keys = array(
		'venue_name'  => 'the venue',
		'venue_type'  => 'hospitality business',
		'currency'    => '',
		'locale'      => '',
		'owner_role'  => 'the owner',
	);

	/**
	 * Resolve a single profile value over its neutral fallback.
	 *
	 * @param string $key Profile key.
	 * @return string
	 */
	private static function raw( $key ) {
		$fallback = isset( self::$keys[ $key ] ) ? self::$keys[ $key ] : '';

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			return $fallback;
		}

		$value = WP_MCP_AI_Fnb_Settings::get( $key, '' );

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : $fallback;
	}

	/**
	 * Venue display name.
	 *
	 * @return string
	 */
	public static function venue_name() {
		return self::raw( 'venue_name' );
	}

	/**
	 * Venue type (restaurant, bar, cafe, club, hotel, ghost-kitchen, …).
	 *
	 * @return string
	 */
	public static function venue_type() {
		return self::raw( 'venue_type' );
	}

	/**
	 * Owner / decision-maker role pronoun used in assistant identities.
	 *
	 * @return string
	 */
	public static function owner_role() {
		return self::raw( 'owner_role' );
	}

	/**
	 * ISO-4217 currency code (may be empty when unset).
	 *
	 * @return string
	 */
	public static function currency_code() {
		return self::raw( 'currency' );
	}

	/**
	 * Currency symbol for the configured code.
	 *
	 * Falls back to the ISO code itself when the code is unknown or unset.
	 *
	 * @return string
	 */
	public static function currency_symbol() {
		$code = strtoupper( self::currency_code() );

		$symbols = array(
			'LKR' => 'Rs', 'USD' => '$', 'EUR' => '€', 'GBP' => '£',
			'JPY' => '¥', 'INR' => '₹', 'AUD' => 'A$', 'CAD' => 'C$',
			'NZD' => 'NZ$', 'SGD' => 'S$', 'AED' => 'AED', 'SAR' => 'SAR',
			'ZAR' => 'R', 'BRL' => 'R$', 'MXN' => 'MX$', 'CHF' => 'CHF',
			'SEK' => 'kr', 'DKK' => 'kr', 'NOK' => 'kr', 'HKD' => 'HK$',
			'CNY' => '¥', 'KRW' => '₩', 'VND' => '₫', 'IDR' => 'Rp',
			'TRY' => '₺', 'RUB' => '₽', 'PLN' => 'zł', 'THB' => '฿',
		);

		if ( '' === $code ) {
			return '';
		}

		return isset( $symbols[ $code ] ) ? $symbols[ $code ] : $code;
	}

	/**
	 * Number of decimal places for money in this currency.
	 *
	 * Zero-decimal currencies (IS0 4217 minor unit 0) get 0; everything else
	 * gets 2.
	 *
	 * @return int
	 */
	public static function currency_decimals() {
		$code = strtoupper( self::currency_code() );

		return in_array( $code, array( 'JPY', 'KRW', 'VND', 'LKR' ), true ) ? 0 : 2;
	}

	/**
	 * BCP-47 locale tag for number/date formatting.
	 *
	 * @return string
	 */
	public static function locale() {
		return self::raw( 'locale' );
	}

	/**
	 * Format a money amount for the configured currency.
	 *
	 * Returns the value without a symbol when the currency is unset, so a fresh
	 * install never asserts a wrong currency.
	 *
	 * @param float $amount Raw amount.
	 * @return string
	 */
	public static function format_money( $amount ) {
		$symbol    = self::currency_symbol();
		$decimals  = self::currency_decimals();
		$formatted = number_format( (float) $amount, $decimals, '.', ',' );

		return '' === $symbol ? $formatted : $symbol . ' ' . $formatted;
	}

	/**
	 * Currency suffix for metric/report labels, e.g. " (LKR)" or " ($)".
	 *
	 * Returns an empty string when the currency is unset.
	 *
	 * @return string
	 */
	public static function money_suffix() {
		$symbol = self::currency_symbol();
		$code   = self::currency_code();

		if ( '' === $symbol ) {
			return '';
		}

		$label = ( '' !== $code && $code !== $symbol ) ? $code . ' ' . $symbol : $symbol;

		return ' (' . $label . ')';
	}

	/**
	 * Render tenant-profile tokens in a prompt/description string.
	 *
	 * Substitutes the following tokens (idempotent; a no-op when none are
	 * present):
	 *   {{venue_name}}      {{venue_type}}      {{owner_role}}
	 *   {{currency}}        {{currency_symbol}}
	 *
	 * @param string $text Source text possibly containing tokens.
	 * @return string Rendered text.
	 */
	public static function render( $text ) {
		$text = (string) $text;

		$map = array(
			'{{venue_name}}'      => self::venue_name(),
			'{{venue_type}}'      => self::venue_type(),
			'{{owner_role}}'      => self::owner_role(),
			'{{currency}}'        => self::currency_code(),
			'{{currency_symbol}}' => self::currency_symbol(),
		);

		return strtr( $text, $map );
	}
}
