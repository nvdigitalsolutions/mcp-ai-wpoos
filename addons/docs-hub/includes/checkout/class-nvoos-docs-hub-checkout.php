<?php
/**
 * NV oOS Docs Hub — Checkout Configuration
 *
 * Purchase configuration for the NV oOS Complete bundle (price, product,
 * vendor endpoint, legal URLs). Mirrors the Content Graph commerce config
 * (plugins/nvoos-content-graph/src/Commerce/Payments.php) with docs-hub
 * naming and snake_case filters.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.5.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout configuration for the NV oOS Complete bundle upsell.
 *
 * This plugin never handles Stripe API keys. All payment processing —
 * PaymentIntent creation, server-side verification, and signed download
 * URLs — is delegated to the vendor checkout API (operated by NV Digital
 * Solutions on behalf of NV Digital Unlocked LLC, the seller of record,
 * on its own server, where the Stripe secret key lives).
 *
 * The purchased artifact is the **NV oOS Complete** bundle (the full
 * NV oOS plugin: base + Pro, distributed as a separate WordPress plugin).
 *
 * The browser is never trusted with an amount: the price shown here is
 * for display only, and the vendor re-verifies everything server-side.
 *
 * @since 0.5.2
 */
class NV_oOS_Docs_Hub_Checkout {

	/**
	 * Default price in the smallest currency unit (USD cents).
	 *
	 * @var int
	 */
	const DEFAULT_PRICE_CENTS = 3499;

	/**
	 * Bundle version pinned for the fallback download URL.
	 *
	 * Mirrors the Complete-bundle release
	 * (`nvdigital-open-operator-system-oos-complete-{version}.zip`)
	 * published on the monorepo GitHub releases under the
	 * `nvdigital-oos-v*` tag. The vendor's `/verify` response is
	 * authoritative when it returns an `addon_version`.
	 *
	 * @var string
	 */
	const DEFAULT_ADDON_VERSION = '1.1.74';

	/**
	 * Base plugin version pinned for the free-version download link.
	 *
	 * Mirrors the free base release
	 * (`nvdigital-open-operator-system-oos-{version}.zip`) published on the
	 * monorepo GitHub releases under the `nvdigital-oos-v*` tag. Bump in
	 * lockstep with new base releases.
	 *
	 * @var string
	 */
	const DEFAULT_BASE_VERSION = '1.1.99';

	/**
	 * The product identifier the vendor checkout API expects.
	 *
	 * Must stay in sync with the vendor's accepted product list
	 * (addons/checkout-api NVOOS_Checkout_API_Rest_Controller::PRODUCTS).
	 *
	 * @var string
	 */
	const PRODUCT_COMPLETE = 'nvoos-oos-complete';

	/**
	 * WordPress option key for the local license/purchase record.
	 *
	 * @var string
	 */
	const OPTION_LICENSE = 'nvoos_docs_hub_checkout_license';

	/**
	 * Base URL of the vendor checkout API.
	 *
	 * Defaults to the NV Digital Solutions checkout endpoint; override via
	 * the `nvoos_docs_hub_checkout_vendor_api_url` filter.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function vendor_api_url() {
		return (string) apply_filters(
			'nvoos_docs_hub_checkout_vendor_api_url',
			'https://nvdigitalsolutions.com/wp-json/nvoos-checkout/v1'
		);
	}

	/**
	 * Whether the vendor endpoint is configured.
	 *
	 * @since 0.5.2
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::vendor_api_url();
	}

	/**
	 * The bundle price in the smallest currency unit (cents).
	 *
	 * Filterable so the price can be adjusted without touching this file.
	 * Display-only in this plugin; the vendor sets the authoritative amount.
	 *
	 * @since 0.5.2
	 *
	 * @return int Price in cents, always at least 50 (Stripe minimum).
	 */
	public static function price_cents() {
		$cents = (int) apply_filters( 'nvoos_docs_hub_checkout_price_cents', self::DEFAULT_PRICE_CENTS );
		return max( 50, $cents );
	}

	/**
	 * The three-letter ISO currency code for the bundle price.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function currency() {
		return 'usd';
	}

	/**
	 * Human-readable price label for the UI.
	 *
	 * @since 0.5.2
	 *
	 * @return string e.g. "$34.99".
	 */
	public static function price_label() {
		return '$' . number_format( self::price_cents() / 100, 2 );
	}

	/**
	 * The bundle version targeted by the installer.
	 *
	 * Bump this (or filter it) in lockstep with new NV oOS releases.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function addon_version() {
		return (string) apply_filters( 'nvoos_docs_hub_checkout_addon_version', self::DEFAULT_ADDON_VERSION );
	}

	/**
	 * Fallback download URL of the NV oOS Complete ZIP.
	 *
	 * Only used when the vendor does not return a signed `download_url`
	 * in the verify response. Defaults to the monorepo GitHub release
	 * asset built by `.github/workflows/build-nvdigital-oos-wporg.yml`.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function zip_url() {
		$version = self::addon_version();
		$default = sprintf(
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/nvdigital-oos-v%s/nvdigital-open-operator-system-oos-complete-%s.zip',
			rawurlencode( $version ),
			rawurlencode( $version )
		);
		return (string) apply_filters( 'nvoos_docs_hub_checkout_zip_url', $default );
	}

	/**
	 * Download URL of the free NV oOS base plugin (latest base release).
	 *
	 * Shown at the bottom of the purchase modal as the free alternative to
	 * the paid Complete bundle. Filterable via
	 * `nvoos_docs_hub_checkout_base_version_url`. An empty value hides the
	 * link in the modal.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function base_version_url() {
		$version = self::DEFAULT_BASE_VERSION;
		$default = sprintf(
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/nvdigital-oos-v%s/nvdigital-open-operator-system-oos-%s.zip',
			rawurlencode( $version ),
			rawurlencode( $version )
		);
		return (string) apply_filters( 'nvoos_docs_hub_checkout_base_version_url', $default );
	}

	/**
	 * Fallback product page URL.
	 *
	 * Shown (and redirected to) when the vendor checkout endpoint is not
	 * available, so users can still obtain the NV oOS Complete bundle.
	 * Filterable via `nvoos_docs_hub_checkout_fallback_url`; an empty value
	 * disables the redirect fallback.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function fallback_product_url() {
		return (string) apply_filters(
			'nvoos_docs_hub_checkout_fallback_url',
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases'
		);
	}

	/**
	 * The Terms of Service URL linked from the purchase modal.
	 *
	 * The vendor's /session response is authoritative when it carries a
	 * terms URL; this client-side default is the fallback so the consent
	 * link is always present. Filterable via
	 * `nvoos_docs_hub_checkout_terms_url`.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function terms_url() {
		return (string) apply_filters(
			'nvoos_docs_hub_checkout_terms_url',
			'https://nvdigitalsolutions.com/terms-of-service'
		);
	}

	/**
	 * The Refund Policy URL linked from the purchase modal.
	 *
	 * Same fallback semantics as {@see terms_url()}. Filterable via
	 * `nvoos_docs_hub_checkout_refund_policy_url`.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function refund_policy_url() {
		return (string) apply_filters(
			'nvoos_docs_hub_checkout_refund_policy_url',
			'https://nvdigitalsolutions.com/refund-policy'
		);
	}

	/**
	 * The roadmap / feature-feedback URL shown in the purchase modal.
	 *
	 * The modal renders the "funded by owners" line only when this URL is
	 * non-empty — an empty value hides the promise entirely. Filterable via
	 * `nvoos_docs_hub_checkout_roadmap_url`.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function roadmap_url() {
		return (string) apply_filters(
			'nvoos_docs_hub_checkout_roadmap_url',
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/discussions'
		);
	}

	/**
	 * The changelog/releases URL shown on the purchase success screen.
	 *
	 * Filterable via `nvoos_docs_hub_checkout_changelog_url`.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function changelog_url() {
		return (string) apply_filters(
			'nvoos_docs_hub_checkout_changelog_url',
			'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases'
		);
	}

	/**
	 * The support email shown on the purchase success screen.
	 *
	 * Filterable via `nvoos_docs_hub_checkout_support_email`.
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function support_email() {
		return (string) apply_filters(
			'nvoos_docs_hub_checkout_support_email',
			'support@nvdigitalsolutions.com'
		);
	}

	/**
	 * ISO 3166-1 alpha-2 codes for EU member states (EU-27).
	 *
	 * Buyers selecting one of these in the purchase modal are required to
	 * provide a billing address (VAT records for digital services); the
	 * code is forwarded to the vendor and stored on the license. Filterable
	 * via `nvoos_docs_hub_checkout_eu_countries`.
	 *
	 * @since 0.5.2
	 *
	 * @return array<int,string>
	 */
	public static function eu_country_codes() {
		$eu = array(
			'AT',
			'BE',
			'BG',
			'HR',
			'CY',
			'CZ',
			'DK',
			'EE',
			'FI',
			'FR',
			'DE',
			'GR',
			'HU',
			'IE',
			'IT',
			'LV',
			'LT',
			'LU',
			'MT',
			'NL',
			'PL',
			'PT',
			'RO',
			'SK',
			'SI',
			'ES',
			'SE',
		);

		$filtered = apply_filters( 'nvoos_docs_hub_checkout_eu_countries', $eu );
		return array_values(
			array_filter(
				is_array( $filtered ) ? $filtered : $eu,
				static function ( $code ) {
					return is_string( $code ) && 1 === preg_match( '/^[A-Z]{2}$/', $code );
				}
			)
		);
	}

	/**
	 * Payload identifying this site and product to the vendor API.
	 *
	 * The vendor binds the payment to `site_url` so an intent created for
	 * another site cannot be replayed here.
	 *
	 * @since 0.5.2
	 *
	 * @return array<string,string>
	 */
	public static function purchase_payload() {
		return array(
			'product'       => self::PRODUCT_COMPLETE,
			'site_url'      => home_url( '' ),
			'addon_version' => self::addon_version(),
		);
	}

	/**
	 * Private constructor — static utility, not instantiable.
	 */
	private function __construct() {}
}
