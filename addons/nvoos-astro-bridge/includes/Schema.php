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
	// Term meta keys.
	// -----------------------------------------------------------------------
	public const TERM_META_HEX_CODE = '_funiq_hex_code';
	public const TERM_META_IMAGE_ID = '_funiq_image_id';

	// -----------------------------------------------------------------------
	// Option names (globals — banner & carousel).
	// -----------------------------------------------------------------------
	public const OPTION_BANNER   = 'funiq_banner';
	public const OPTION_CAROUSEL = 'funiq_carousel';

	/**
	 * Product source mode — `native` (default: the built-in CPTs),
	 * `woo` (WooCommerce products) or `both` (merged). Read through the
	 * `funiq_bridge_products_source` filter at runtime; a `woo`/`both` mode
	 * degrades to `native` when WooCommerce is not active.
	 */
	public const OPTION_PRODUCT_SOURCE = 'funiq_bridge_products_source';

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

	/** Private — not instantiable. */
	private function __construct() {}
}
