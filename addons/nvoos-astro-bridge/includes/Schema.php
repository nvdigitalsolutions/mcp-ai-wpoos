<?php
/**
 * Central constants for the Funiq Bridge addon.
 *
 * Every repeated string — CPT slugs, taxonomy slugs, meta keys, option names,
 * REST namespace, capability slug, hook names — lives here so they can be
 * renamed in one place and never typo'd.
 *
 * @package NvoosAstroBridge
 */

namespace NvoosAstroBridge;

/**
 * Central constants — not instantiable.
 */
final class Schema {

	// -----------------------------------------------------------------------
	// REST API namespace / version.
	// -----------------------------------------------------------------------
	public const REST_NAMESPACE = 'funiq/v1';

	/**
	 * NV commerce contract namespace (Proposal 092). Served as an alias of
	 * REST_NAMESPACE — identical routes, two namespaces. The legacy namespace
	 * retires with the licensed PWA (Q1: keep A/B until then).
	 */
	public const REST_NAMESPACE_NV = 'nvoos-commerce/v1';

	// -----------------------------------------------------------------------
	// Post type slugs.
	// -----------------------------------------------------------------------
	public const CPT_PRODUCT   = 'funiq_product';
	public const CPT_PROMOTION = 'funiq_promotion';
	public const CPT_PROMOCODE = 'funiq_promocode';

	// -----------------------------------------------------------------------
	// Taxonomy slugs.
	// -----------------------------------------------------------------------
	public const TAX_CATEGORY = 'funiq_category';
	public const TAX_BRAND    = 'funiq_brand';
	public const TAX_COLOR    = 'funiq_color';
	public const TAX_STATUS   = 'funiq_status';

	// -----------------------------------------------------------------------
	// Post meta keys (product).
	// -----------------------------------------------------------------------
	public const META_PRICE         = '_funiq_price';
	public const META_OLD_PRICE     = '_funiq_old_price';
	public const META_WIDTH         = '_funiq_width';
	public const META_HEIGHT        = '_funiq_height';
	public const META_DEPTH         = '_funiq_depth';
	public const META_RATING        = '_funiq_rating';
	public const META_IS_BESTSELLER = '_funiq_is_bestseller';
	public const META_IS_FEATURED   = '_funiq_is_featured';
	public const META_PROMOTION_ID  = '_funiq_promotion_id';
	public const META_GALLERY       = '_funiq_gallery';

	// -----------------------------------------------------------------------
	// Post meta keys (promotion).
	// -----------------------------------------------------------------------
	public const META_PROMO_START_DATE = '_funiq_start_date';
	public const META_PROMO_END_DATE   = '_funiq_end_date';
	public const META_PROMO_ACTIVE     = '_funiq_active';

	// -----------------------------------------------------------------------
	// Post meta keys (promocode).
	// -----------------------------------------------------------------------
	public const META_PROMOCODE_DISCOUNT   = '_funiq_discount';
	public const META_PROMOCODE_EXPIRES_AT = '_funiq_expires_at';
	public const META_PROMOCODE_IS_ACTIVE  = '_funiq_is_active';
	public const META_PROMOCODE_NAME       = '_funiq_name';
	public const META_PROMOCODE_TITLE      = '_funiq_title';
	public const META_PROMOCODE_LOGO       = '_funiq_logo';

	// -----------------------------------------------------------------------
	// Post meta keys (content — pages/posts, Proposal 107).
	// -----------------------------------------------------------------------
	public const META_CONTENT_BLOCKS = '_funiq_content_blocks';
	public const META_SUBMITTED_BY   = '_funiq_submitted_by';
	public const META_APPROVED_BY    = '_funiq_approved_by';

	// -----------------------------------------------------------------------
	// Term meta keys.
	// -----------------------------------------------------------------------
	public const TERM_META_HEX_CODE = '_funiq_hex_code';
	public const TERM_META_IMAGE_ID = '_funiq_image_id';

	// -----------------------------------------------------------------------
	// Option names (globals — banner, carousel, theme policy, shop).
	// -----------------------------------------------------------------------
	public const OPTION_BANNER   = 'funiq_banner';
	public const OPTION_CAROUSEL = 'funiq_carousel';

	/**
	 * Theme policy option name (Proposal 105) — the storefront theme ladder
	 * doc (policy / contrast / defaultTheme / updatedAt / $extensions).
	 * Read through the `funiq_bridge_theme_policy` filter at runtime (the
	 * product-source pattern — the stored value is the default, a filter can
	 * override per request).
	 */
	public const OPTION_THEME_POLICY = 'funiq_bridge_theme_policy';

	/**
	 * Shop globals option name (Proposal 106 §1.3) — checkout/cart URLs and
	 * the currency display hint for the storefront checkout handoff.
	 */
	public const OPTION_SHOP = 'funiq_bridge_shop';

	/**
	 * Product source mode — `native` (default: the built-in CPTs),
	 * `woo` (WooCommerce products) or `both` (merged). Read through the
	 * `funiq_bridge_products_source` filter at runtime; a `woo`/`both` mode
	 * degrades to `native` when WooCommerce is not active.
	 */
	public const OPTION_PRODUCT_SOURCE = 'funiq_bridge_products_source';

	/**
	 * Woo SSO handoff switch (Proposal 108 Phase 3) — when `yes`, logins on
	 * the bridge auth surface mint a short-lived, single-use token that the
	 * `woo-login` route exchanges for a logged-in Woo session before landing
	 * on the hosted checkout. Off by default: cross-domain carts work without
	 * it, shoppers simply sign in at checkout.
	 */
	public const OPTION_WOO_SSO_ENABLED = 'funiq_bridge_woo_sso_enabled';

	// -----------------------------------------------------------------------
	// Product source names (the `source` field on product documents).
	// -----------------------------------------------------------------------
	public const SOURCE_NATIVE = 'native';
	public const SOURCE_WOO    = 'woocommerce';

	// -----------------------------------------------------------------------
	// WooCommerce mapping targets (used when Woo is the products source).
	// -----------------------------------------------------------------------
	public const TAX_WOO_CATEGORY = 'product_cat';
	public const ATTR_WOO_BRAND   = 'pa_brand';
	public const ATTR_WOO_COLOR   = 'pa_color';

	// -----------------------------------------------------------------------
	// Capability slug.
	//
	// Renamed from `manage_funiq` (2026-10-29 brand cutover). The legacy slug
	// is kept as a constant so existing installs can be migrated without a
	// reactivation — see Plugin::maybe_migrate_caps().
	// -----------------------------------------------------------------------
	public const CAP_MANAGE        = 'manage_astro';
	public const CAP_MANAGE_LEGACY = 'manage_funiq';

	/**
	 * Option flag recording that the capability migration has run.
	 *
	 * @var string
	 */
	public const OPTION_CAP_VERSION = 'nvoos_bridge_cap_version';

	// -----------------------------------------------------------------------
	// Admin page slug.
	// -----------------------------------------------------------------------
	public const ADMIN_PAGE_SLUG = 'astro-cms';

	// -----------------------------------------------------------------------
	// Option defaults.
	// -----------------------------------------------------------------------

	/**
	 * Default values for the banner option.
	 *
	 * @return array{image: ?int, promotion: ?int}
	 */
	public static function banner_defaults(): array {
		return array(
			'image'     => null,
			'promotion' => null,
		);
	}

	/**
	 * Default values for the carousel option.
	 *
	 * @return array{carousel: list<array{image: ?int, promotion: ?int}>}
	 */
	public static function carousel_defaults(): array {
		return array(
			'carousel' => array(),
		);
	}

	/**
	 * Default values for the theme policy option (Proposal 105 §1).
	 *
	 * `$extensions` is always an empty object in v1 (reserved for
	 * Proposal 097); `updatedAt` is the SSR cache-key component and is
	 * re-stamped server-side on every PATCH.
	 *
	 * @return array{policy: string, contrast: string, defaultTheme: string, updatedAt: string, $extensions: array<string, mixed>}
	 */
	public static function theme_policy_defaults(): array {
		return array(
			'policy'       => 'auto',
			'contrast'     => 'auto',
			'defaultTheme' => 'dark',
			'updatedAt'    => gmdate( 'c' ),
			'$extensions'  => array(),
		);
	}

	/**
	 * Default values for the shop globals option (Proposal 106 §1.3,
	 * extended by 108 §3).
	 *
	 * Checkout/cart/return URLs derive from the site home URL; the currency
	 * comes from WooCommerce when it is active (USD otherwise). The currency
	 * symbol is a display hint for the storefront — no currency conversion
	 * happens bridge-side.
	 *
	 * @return array{checkoutUrl: string, cartUrl: string, returnUrl: string, currency: string, currencySymbol: string, updatedAt: string}
	 */
	public static function shop_defaults(): array {
		return array(
			'checkoutUrl'    => home_url( '/checkout/' ),
			'cartUrl'        => home_url( '/cart/' ),
			'returnUrl'      => home_url( '/checkout/order-received/' ),
			'currency'       => ( class_exists( 'WooCommerce' ) && function_exists( 'get_woocommerce_currency' ) ) ? get_woocommerce_currency() : 'USD',
			'currencySymbol' => '$',
			'updatedAt'      => gmdate( 'c' ),
		);
	}

	/** Private — not instantiable. */
	private function __construct() {}
}
