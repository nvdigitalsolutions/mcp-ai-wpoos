<?php
/**
 * NV oOS Skote — Core Boot Class
 *
 * Wires plugins_loaded / init / rest_api_init / admin_menu /
 * admin_enqueue_scripts / wp_enqueue_scripts in the correct order and at the
 * correct priorities. CPT/CCT touchpoints register at priority 11+ so they do
 * not race JetEngine's CCT cache hydration (priorities 1-10).
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Skote Addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Skote {

	/**
	 * Option key holding admin-controlled settings (per-site).
	 *
	 * @var string
	 */
	const OPTION_SETTINGS = 'nvoos_skote_settings';

	/**
	 * Option key holding the allowlist of post types the bridge may expose.
	 *
	 * @var string
	 */
	const OPTION_ALLOWED_CPTS = 'nvoos_skote_allowed_cpts';

	/**
	 * User-meta key for per-user SPA preferences.
	 *
	 * @var string
	 */
	const USER_META_PREFS = 'nvoos_skote_prefs';

	/**
	 * Capability that gates the admin page by default.
	 *
	 * Filterable via the `nvoos_skote_admin_capability` filter so site admins
	 * can hand it to a non-admin role.
	 *
	 * @var string
	 */
	const DEFAULT_ADMIN_CAP = 'manage_options';

	/**
	 * Whether init() has already run in this request.
	 *
	 * @var bool
	 */
	protected static $initialised = false;

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$initialised ) {
			return;
		}
		self::$initialised = true;

		// Translations.
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ), 20 );

		// Late init for any CPT/CCT touchpoints (priority 11 — outside the
		// JetEngine CCT cache hydration window of priorities 1-10).
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 11 );

		// REST routes.
		add_action( 'rest_api_init', array( 'NVOOS_Skote_REST_Settings', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'NVOOS_Skote_REST_Bridge', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'NVOOS_Skote_REST_Workflows', 'register_routes' ) );

		// Admin menu + assets.
		add_action( 'admin_menu', array( 'NVOOS_Skote_Admin_Page', 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'NVOOS_Skote_Assets', 'maybe_enqueue_admin' ) );
		add_action( 'wp_enqueue_scripts', array( 'NVOOS_Skote_Assets', 'maybe_enqueue_frontend' ) );

		// Shortcode.
		add_shortcode( 'nvoos_skote', array( 'NVOOS_Skote_Shortcode', 'render' ) );

		// Integrations boot at plugins_loaded:20 so dependency plugins are
		// already known. Each bridge does its own dependency probe.
		add_action( 'plugins_loaded', array( 'NVOOS_Skote_Pro_Bridge', 'init' ), 25 );
		add_action( 'plugins_loaded', array( 'NVOOS_Skote_JetEngine_Bridge', 'init' ), 25 );
		add_action( 'plugins_loaded', array( 'NVOOS_Skote_WooCommerce_Bridge', 'init' ), 25 );
	}

	/**
	 * Load plugin translations.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			'nvoos-skote',
			false,
			dirname( plugin_basename( NVOOS_SKOTE_FILE ) ) . '/languages'
		);
	}

	/**
	 * Register the addon's CPTs (Tasks, Calendar Events).
	 *
	 * Phase 1 ships these as no-op stubs — actual registration is done in
	 * Phase 3 once the SPA hooks are wired. We still attach to `init` at
	 * priority 11 so the future implementation slots in safely without
	 * racing JetEngine.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_post_types() {
		/**
		 * Fires when the Skote addon is ready to register its CPTs.
		 *
		 * Third parties can hook in here to add task/event meta fields,
		 * taxonomies, or to disable a CPT entirely.
		 *
		 * @since 0.1.0
		 */
		do_action( 'nvoos_skote_register_post_types' );
	}

	/**
	 * Return the capability required to view the admin SPA.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_admin_capability() {
		/**
		 * Filters the capability that gates the Skote admin page.
		 *
		 * @since 0.1.0
		 *
		 * @param string $capability Default: `manage_options`.
		 */
		return (string) apply_filters( 'nvoos_skote_admin_capability', self::DEFAULT_ADMIN_CAP );
	}

	/**
	 * Whether the NV oOS Pro addon is active.
	 *
	 * Detected via the canonical Pro init function, exactly the same way
	 * other addons (graphify, fantasy-football) probe Pro presence.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return function_exists( 'wp_mcp_ai_pro_init' );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether JetEngine is active.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_jetengine_active() {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
	}
}
