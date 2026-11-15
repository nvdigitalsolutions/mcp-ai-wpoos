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

	/**
	 * Local (CMS-authored) content for the forged frontend — Proposal 135 §D4.
	 *
	 * Deliberately NOT `forge_local`: a bare slug is a collision hazard on a
	 * shared WordPress install, and every other new identifier in this addon
	 * carries the `nvoos_` prefix (Proposal 112's meta-prefix decision).
	 * Private + `show_in_rest` false — this CPT is reached only through the
	 * bridge contract, never through wp/v2.
	 */
	public const CPT_FORGE_LOCAL = 'nvoos_forge_local';

	/**
	 * Preview token lifetime in seconds (Proposal 135 Phase 5).
	 *
	 * A preview link is a short-lived convenience for an editor, not a session:
	 * 15 minutes is long enough to open the link and look at a draft, short
	 * enough that a leaked link dies on its own.
	 */
	public const PREVIEW_TTL = 900;

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
	// Proposal 112 — Woo-owned catalog migration (nvoos_bridge_ prefix per
	// the owner naming decision 2026-11-04).
	// -----------------------------------------------------------------------

	/**
	 * Woo product meta: the native (funiq_product) post id this product was
	 * migrated from. The idempotency anchor alongside the generated SKU.
	 */
	public const META_NVOOS_MIGRATED_FROM = '_nvoos_bridge_migrated_from';

	/**
	 * Woo product meta: the native promotionId carried across the migration
	 * (Woo has no promotion equivalent — the storefront reads it back).
	 */
	public const META_NVOOS_PROMOTION_ID = '_nvoos_bridge_promotion_id';

	/**
	 * Migration state option (autoload off): the last sweep counters and
	 * status — read by the Settings page and the WP-CLI status command.
	 */
	public const OPTION_MIGRATION_STATE = 'nvoos_bridge_migration_state';

	/**
	 * Action Scheduler hook for one sweep chunk (cursor pattern: args carry
	 * only the offset). Group: nvoos-bridge-migrate.
	 */
	public const HOOK_MIGRATE_SWEEP = 'nvoos_bridge/migrate_native_sweep';

	/**
	 * Generated SKU prefix for migrated products — NV-<native_id> is a
	 * namespaced business key that can never collide with real store SKUs.
	 */
	public const SKU_PREFIX_MIGRATED = 'NV-';

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

	/**
	 * Public site origin (Proposal 135) — the origin visitors use, e.g.
	 * `https://client.com` when WordPress itself lives on `cms.client.com`.
	 *
	 * Shopper-facing defaults (checkout/cart/return) and the checkout-URL
	 * allowlist derive from this instead of `home_url()`. Empty = fall back to
	 * `home_url()`, so single-origin deployments are unchanged.
	 */
	public const OPTION_PUBLIC_ORIGIN = 'funiq_bridge_public_origin';

	/**
	 * Media origin (Proposal 135 / A6) — `cms` (default: absolute URLs on the
	 * WordPress host) or `frontend` (same-origin `/media/...` paths for the
	 * managed site's media proxy). `frontend` is only correct when the frontend
	 * serves `/media/*` (the managed proxy) — a static site needs `cms`.
	 */
	public const OPTION_MEDIA_ORIGIN = 'funiq_bridge_media_origin';

	/**
	 * Media origin: absolute URLs on the WordPress host (default).
	 */
	public const MEDIA_ORIGIN_CMS = 'cms';

	/**
	 * Media origin: same-origin `/media/...` paths (managed sites with the
	 * media proxy).
	 */
	public const MEDIA_ORIGIN_FRONTEND = 'frontend';

	// -----------------------------------------------------------------------
	// Product source names (the `source` field on product documents).
	// -----------------------------------------------------------------------
	public const SOURCE_NATIVE = 'native';
	public const SOURCE_WOO    = 'woocommerce';

	/**
	 * Local CPT-authored content read by the forge loader
	 * (`WpCollection.source === 'local'`).
	 */
	public const SOURCE_LOCAL = 'local';

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
	 * The media URL for an attachment, honoring the media-origin setting.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string URL (absolute in cms mode, `/media/...` in frontend mode).
	 */
	public static function media_url( $attachment_id ): string {
		if ( empty( $attachment_id ) ) {
			return '';
		}
		$url = wp_get_attachment_url( (int) $attachment_id );

		return self::media_url_from( is_string( $url ) ? $url : '' );
	}

	/**
	 * Rewrite a WordPress uploads URL for the configured media origin.
	 * Pure string work — unit-testable without an attachment fixture.
	 *
	 * @param string $url Absolute uploads URL (or any URL).
	 * @return string The URL, or its `/media/...` path in frontend mode.
	 */
	public static function media_url_from( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$mode = get_option( self::OPTION_MEDIA_ORIGIN, self::MEDIA_ORIGIN_CMS );
		if ( self::MEDIA_ORIGIN_FRONTEND !== $mode ) {
			return $url;
		}

		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		if ( '' !== $base && 0 === strpos( $url, $base ) ) {
			return '/media' . substr( $url, strlen( $base ) );
		}

		return $url;
	}

	/**
	 * The public site origin (Proposal 135).
	 *
	 * `home_url()` is the CMS origin. When the frontend owns the public domain
	 * (`cms.client.com` for WordPress, `client.com` for shoppers), shopper-facing
	 * URLs built from `home_url()` point at a host that serves no storefront.
	 * Resolves the configured origin; falls back to `home_url()` when unset, so
	 * single-origin installs (nvoos.pro) behave exactly as before.
	 *
	 * @return string Absolute origin, no trailing slash.
	 */
	public static function public_origin(): string {
		$stored = get_option( self::OPTION_PUBLIC_ORIGIN, '' );
		$origin = is_string( $stored ) ? self::normalize_origin( $stored ) : '';

		if ( '' === $origin ) {
			$origin = self::normalize_origin( home_url() );
		}

		/**
		 * Per-request public-origin override.
		 *
		 * @param string $origin Normalized origin.
		 */
		$origin = apply_filters( 'funiq_bridge_public_origin', $origin );

		return is_string( $origin ) && '' !== $origin ? $origin : untrailingslashit( (string) home_url() );
	}

	/**
	 * Normalize an absolute http(s) origin to `scheme://host[:port]`.
	 *
	 * Returns an empty string for anything else (relative values, other
	 * schemes, userinfo). Validation is deliberately local —
	 * `wp_http_validate_url()` resolves DNS, which fails on intranet hosts and
	 * in CI (the ShopGlobalsController documents the same gotcha).
	 *
	 * @param mixed $value Raw value.
	 * @return string Normalized origin or ''.
	 */
	public static function normalize_origin( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return $scheme . '://' . strtolower( (string) $parts['host'] ) . $port;
	}

	/**
	 * Default values for the shop globals option (Proposal 106 §1.3,
	 * extended by 108 §3).
	 *
	 * Checkout/cart/return URLs derive from the PUBLIC origin (Proposal 135) —
	 * the configured frontend origin when one is set, `home_url()` otherwise.
	 * The currency comes from WooCommerce when it is active (USD otherwise).
	 * The currency symbol is a display hint for the storefront — no currency
	 * conversion happens bridge-side.
	 *
	 * An already-stored shop doc keeps its URLs; changing the public origin
	 * does not rewrite it (update the doc via /manage → Shop).
	 *
	 * @return array{checkoutUrl: string, cartUrl: string, returnUrl: string, currency: string, currencySymbol: string, updatedAt: string}
	 */
	public static function shop_defaults(): array {
		$origin = self::public_origin();

		return array(
			'checkoutUrl'    => $origin . '/checkout/',
			'cartUrl'        => $origin . '/cart/',
			'returnUrl'      => $origin . '/checkout/order-received/',
			'currency'       => ( class_exists( 'WooCommerce' ) && function_exists( 'get_woocommerce_currency' ) ) ? get_woocommerce_currency() : 'USD',
			'currencySymbol' => '$',
			'updatedAt'      => gmdate( 'c' ),
		);
	}

	/** Private — not instantiable. */
	private function __construct() {}
}
