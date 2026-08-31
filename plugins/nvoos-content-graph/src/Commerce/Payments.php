<?php
declare(strict_types=1);

namespace NvoosContentGraph\Commerce;

use NvoosContentGraph\Schema;

use function apply_filters;
use function home_url;
use function max;
use function rawurlencode;

/**
 * Purchase configuration (price, product, vendor endpoint).
 *
 * This plugin never handles Stripe API keys. All payment processing —
 * PaymentIntent creation, server-side verification, and signed download
 * URLs — is delegated to the vendor checkout API (run by NV Digital
 * Solutions on its own server, where the Stripe secret key lives).
 *
 * The browser is never trusted with an amount: the price shown here is
 * for display only, and the vendor re-verifies everything server-side.
 *
 * @since 1.0.4
 */
final class Payments {

	/** @var int Default price in the smallest currency unit (USD cents). */
	public const DEFAULT_PRICE_CENTS = 4900;

	/** @var string Addon version pinned for the fallback download URL. */
	public const DEFAULT_ADDON_VERSION = '1.0.3';

	/**
	 * Base URL of the vendor checkout API.
	 *
	 * Defaults to the NV Digital Solutions checkout endpoint; override via
	 * the `nvoos_content_graph/payments/vendor_api_url` filter or change
	 * the default before release. See docs/commerce-vendor-api.md for the
	 * endpoint contract.
	 *
	 * @since 1.0.4
	 *
	 * @return string
	 */
	public static function vendorApiUrl(): string {
		return (string) apply_filters(
			Schema::FILTER_VENDOR_API_URL,
			'https://nvdigitalsolutions.com/wp-json/nvoos-checkout/v1'
		);
	}

	/**
	 * Whether the vendor endpoint is configured.
	 *
	 * @return bool
	 */
	public static function isConfigured(): bool {
		return '' !== self::vendorApiUrl();
	}

	/**
	 * The addon price in the smallest currency unit (cents).
	 *
	 * Filterable so the price can be adjusted without touching this file.
	 * Display-only in this plugin; the vendor sets the authoritative amount.
	 *
	 * @since 1.0.4
	 *
	 * @return int Price in cents, always at least 50 (Stripe minimum).
	 */
	public static function priceCents(): int {
		$cents = (int) apply_filters( Schema::FILTER_PRICE_CENTS, self::DEFAULT_PRICE_CENTS );
		return max( 50, $cents );
	}

	/**
	 * The three-letter ISO currency code for the addon price.
	 *
	 * @return string
	 */
	public static function currency(): string {
		return 'usd';
	}

	/**
	 * Human-readable price label for the UI.
	 *
	 * @return string e.g. "$49.00".
	 */
	public static function priceLabel(): string {
		return '$' . number_format( self::priceCents() / 100, 2 );
	}

	/**
	 * The addon version targeted by the installer.
	 *
	 * Bump this (or filter it) in lockstep with new addon releases.
	 *
	 * @since 1.0.4
	 *
	 * @return string
	 */
	public static function addonVersion(): string {
		return (string) apply_filters( Schema::FILTER_ADDON_VERSION, self::DEFAULT_ADDON_VERSION );
	}

	/**
	 * Fallback download URL of the addon ZIP.
	 *
	 * Only used when the vendor does not return a signed `download_url`
	 * in the verify response. Defaults to the monorepo GitHub release
	 * asset built by `.github/workflows/build-nvoos-content-graph-ai.yml`.
	 *
	 * @since 1.0.4
	 *
	 * @return string
	 */
	public static function zipUrl(): string {
		$version = self::addonVersion();
		$default = sprintf(
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/content-graph-ai-v%s/nvoos-content-graph-ai-v%s.zip',
			rawurlencode( $version ),
			rawurlencode( $version )
		);
		return (string) apply_filters( Schema::FILTER_ADDON_ZIP_URL, $default );
	}

	/**
	 * Payload identifying this site and product to the vendor API.
	 *
	 * The vendor binds the payment to `site_url` so an intent created for
	 * another site cannot be replayed here.
	 *
	 * @return array<string,string>
	 */
	public static function purchasePayload(): array {
		return array(
			'product'       => Schema::PRODUCT_AI_ADDON,
			'site_url'      => home_url( '' ),
			'addon_version' => self::addonVersion(),
		);
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
