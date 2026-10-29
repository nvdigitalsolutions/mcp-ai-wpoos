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

		// 2b. Cache headers on public GETs (B1, Proposal 092).
		add_filter( 'rest_post_dispatch', array( BaseController::class, 'set_cache_headers_on_public_gets' ), 10, 3 );

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
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
			$controller->set_namespace( Schema::REST_NAMESPACE_NV );
			$controller->register_routes();
		}
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
			$admin->add_cap( Schema::CAP_MANAGE_FUNIQ );
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( Schema::CAP_MANAGE_FUNIQ );
		}

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
