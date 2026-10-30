<?php
/**
 * Composition root for the Funiq Bridge addon.
 *
 * Registers post types, taxonomies, REST routes, the admin page, and handles
 * activation/deactivation lifecycle.
 *
 * @package FuniqBridge
 */

namespace FuniqBridge;

use FuniqBridge\PostTypes\{Product, Promotion, Promocode};
use FuniqBridge\Taxonomies\{Brand, Category, Color, Status};
use FuniqBridge\REST\{
	AuthController,
	BannerController,
	BaseController,
	BrandsController,
	CarouselController,
	CategoriesController,
	ColorsController,
	ManagersController,
	MediaController,
	ProductsController,
	PromocodesController,
	PromotionsController,
	StatusesController,
};
use FuniqBridge\Admin\AdminPage;

/**
 * Plugin composition root.
 *
 * Instantiated once via self::init() on plugins_loaded.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Idempotency guard — init() is safe to call from both the normal and late-load paths.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap the addon.
	 *
	 * Called from funiq-bridge.php on plugins_loaded (priority 30).
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		$plugin = self::instance();

		// 1. Register CPTs and taxonomies on init.
		add_action( 'init', array( $plugin, 'register_post_types' ), 11 );
		add_action( 'init', array( $plugin, 'register_taxonomies' ), 11 );

		// 2. Register REST routes on rest_api_init.
		add_action( 'rest_api_init', array( $plugin, 'register_rest_routes' ) );

		// 2a. One-shot capability migration for the brand cutover
		// (manage_funiq -> manage_astro). Late-load-immune: the fleet
		// addon-manager includes this file after `init` has fired, so a hook
		// registration alone would never run — call it directly then, the
		// same pattern the main file uses for CPT/taxonomy/REST registration
		// (PR #7281). Also covers the init:9 boot path deterministically.
		if ( did_action( 'init' ) ) {
			$plugin->maybe_migrate_caps();
		} else {
			add_action( 'init', array( $plugin, 'maybe_migrate_caps' ) );
		}

		// 2b. Cache headers on public GETs (B1, Proposal 092).
		add_filter( 'rest_post_dispatch', array( BaseController::class, 'set_cache_headers_on_public_gets' ), 10, 3 );

		// 2c. REST cookie auth on single-site HTTPS — core never resolves the
		// user for /wp-json requests there (see the method docblock), so the
		// contract's cookie surface 401s in production without this shim.
		add_filter( 'determine_current_user', array( self::class, 'determine_rest_user_from_logged_in_cookie' ), 25 );

		// 3. Seed default options on activation.
		add_action( 'init', array( $plugin, 'seed_default_options' ) );

		// 4. Admin page (React SPA).
		if ( is_admin() ) {
			AdminPage::init();
		}

		// 5. Storefront rebuild webhook (Proposal 092 Phase 2.3 — no-ops
		// until the operator configures a webhook URL).
		RebuildWebhook::init();
	}

	/**
	 * Register all custom post types.
	 *
	 * Hooked to init at priority 11 — above JetEngine's default window.
	 *
	 * @return void
	 */
	public function register_post_types(): void {
		( new Product() )->register();
		( new Promotion() )->register();
		( new Promocode() )->register();
	}

	/**
	 * Register all custom taxonomies.
	 *
	 * Hooked to init at priority 11.
	 *
	 * @return void
	 */
	public function register_taxonomies(): void {
		( new Category() )->register();
		( new Brand() )->register();
		( new Color() )->register();
		( new Status() )->register();
	}

	/**
	 * Register all REST controllers.
	 *
	 * Hooked to rest_api_init. Each controller is registered twice:
	 *
	 * 1. `funiq/v1` — legacy namespace, kept for the licensed PWA (Q1: keep
	 *    A/B until retirement).
	 * 2. `nvoos-commerce/v1` — the NV commerce contract (Proposal 092); the
	 *    Astro storefront and all new consumers bind here.
	 *
	 * Both namespaces serve identical routes — the alias costs nothing and
	 * makes the legacy namespace a one-line removal per controller at
	 * PWA retirement.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controllers = array(
			new ProductsController(),
			new CategoriesController(),
			new BrandsController(),
			new ColorsController(),
			new StatusesController(),
			new PromotionsController(),
			new PromocodesController(),
			new BannerController(),
			new CarouselController(),
			new AuthController(),
			new MediaController(),
			new ManagersController(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
			$controller->set_namespace( Schema::REST_NAMESPACE_NV );
			$controller->register_routes();
		}
	}

	/**
	 * Resolve the REST current user from the logged-in auth cookie.
	 *
	 * Single-site HTTPS quirk: core's wp_validate_logged_in_cookie() is skipped
	 * on single sites (is_blog_admin() is always true there), and over HTTPS the
	 * secure auth cookie (wordpress_sec_*) is path-scoped to /wp-admin and
	 * /wp-content/plugins. No cookie therefore authenticates /wp-json/*
	 * requests, and WP 6.9's rest_cookie_check_errors() additionally forces
	 * user 0 on any REST request that carries no nonce. The result: a session
	 * that works in wp-admin 401s on every contract route (auth/me, managers,
	 * media, writes) — exactly what the /manage gate experienced in
	 * production on 2026-10-30.
	 *
	 * This filter validates the path=/-scoped logged-in cookie explicitly for
	 * the bridge's own REST namespaces only, restoring the multisite behavior
	 * without widening cookie auth for any other REST consumer on the site.
	 *
	 * @param int|false $user_id Currently determined user ID.
	 * @return int|false
	 */
	public static function determine_rest_user_from_logged_in_cookie( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}
		if ( ! self::is_bridge_rest_request() ) {
			return $user_id;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Auth material compared via wp_validate_auth_cookie(), never echoed or stored.
		if ( empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return $user_id;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cookie_user = wp_validate_auth_cookie( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ), 'logged_in' );

		return $cookie_user ? (int) $cookie_user : 0;
	}

	/**
	 * Whether the current request is a REST request into the bridge namespaces.
	 *
	 * @return bool
	 */
	private static function is_bridge_rest_request(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read-only substring match, never echoed.
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uri        = (string) $_SERVER['REQUEST_URI'];
		$wp_json    = '/' . rest_get_url_prefix() . '/';
		$namespaces = array(
			$wp_json . Schema::REST_NAMESPACE,
			$wp_json . Schema::REST_NAMESPACE_NV,
		);

		foreach ( $namespaces as $namespace ) {
			if ( false !== strpos( $uri, $namespace ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Seed default option values when they don't exist.
	 *
	 * @return void
	 */
	public function seed_default_options(): void {
		if ( false === get_option( Schema::OPTION_BANNER ) ) {
			add_option( Schema::OPTION_BANNER, Schema::banner_defaults() );
		}
		if ( false === get_option( Schema::OPTION_CAROUSEL ) ) {
			add_option( Schema::OPTION_CAROUSEL, Schema::carousel_defaults() );
		}
	}

	// -----------------------------------------------------------------------
	// Lifecycle hooks.
	// -----------------------------------------------------------------------

	/**
	 * One-shot capability migration for the brand cutover.
	 *
	 * Copies `manage_funiq` grants onto `manage_astro` and removes the legacy
	 * cap from every role, then records the migration in an option so the
	 * work runs exactly once (2026-10-29: the Astro CMS rename).
	 *
	 * @return void
	 */
	public function maybe_migrate_caps(): void {
		if ( '2' === get_option( Schema::OPTION_CAP_VERSION, '1' ) ) {
			return;
		}

		foreach ( wp_roles()->roles as $role_key => $role ) {
			$wp_role = get_role( $role_key );
			if ( ! $wp_role ) {
				continue;
			}
			if ( $wp_role->has_cap( Schema::CAP_MANAGE_LEGACY ) ) {
				$wp_role->add_cap( Schema::CAP_MANAGE );
			}
			$wp_role->remove_cap( Schema::CAP_MANAGE_LEGACY );
		}

		update_option( Schema::OPTION_CAP_VERSION, '2', false );
	}

	/**
	 * Activation callback — flushes rewrite rules.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Register CPTs so rewrite rules are populated before flush.
		( new Product() )->register();
		( new Promotion() )->register();
		( new Promocode() )->register();
		( new Category() )->register();
		( new Brand() )->register();
		( new Color() )->register();
		( new Status() )->register();

		// Seed options.
		if ( false === get_option( Schema::OPTION_BANNER ) ) {
			add_option( Schema::OPTION_BANNER, Schema::banner_defaults() );
		}
		if ( false === get_option( Schema::OPTION_CAROUSEL ) ) {
			add_option( Schema::OPTION_CAROUSEL, Schema::carousel_defaults() );
		}

		// Add capabilities.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( Schema::CAP_MANAGE );
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( Schema::CAP_MANAGE );
		}

		// Record the capability migration as done on fresh installs (nothing
		// to migrate, but the maybe_migrate_caps() gate reads this option).
		update_option( Schema::OPTION_CAP_VERSION, '2', false );

		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback — flushes rewrite rules.
	 *
	 * Does NOT remove data; uninstall.php handles that.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/** Private constructor — singleton via instance(). */
	private function __construct() {}
}
