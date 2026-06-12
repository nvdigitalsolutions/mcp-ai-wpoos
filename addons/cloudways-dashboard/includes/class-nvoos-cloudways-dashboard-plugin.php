<?php
/**
 * NV oOS Cloudways Dashboard — Core Plugin Class
 *
 * Singleton that registers all hooks for the Velzon-themed operator dashboard.
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton.
 *
 * @since 0.1.0
 */
class NV_oOS_CloudwaysDashboard_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @since 0.1.1
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_cloudways_dashboard_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( 'NV_oOS_CloudwaysDashboard_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_CloudwaysDashboard_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_CloudwaysDashboard_REST', 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_base_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_bundle_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_cloudways_notice' ) );

		// Register the provisioning job hook with Action Scheduler.
		add_action( NV_oOS_CloudwaysDashboard_Provisioning_Job::HOOK, array( 'NV_oOS_CloudwaysDashboard_Provisioning_Job', 'run' ), 10, 4 );
	}

	/**
	 * Register WP-Admin menu page.
	 *
	 * @return void
	 */
	public static function register_admin_menu() {
		add_menu_page(
			__( 'Cloudways Dashboard', 'nvoos-cloudways-dashboard' ),
			__( 'oOS Cloudways', 'nvoos-cloudways-dashboard' ),
			'manage_options',
			'nvoos-cloudways-dashboard',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-cloud',
			31
		);
	}

	/**
	 * Render the WP-Admin page that hosts the SPA.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		echo sprintf(
			'<div class="nvoos-cloudways-dashboard-root" role="application" aria-label="%s" data-config="%s"></div>',
			esc_attr( __( 'Cloudways Dashboard', 'nvoos-cloudways-dashboard' ) ),
			esc_attr( wp_json_encode( array( 'isAdmin' => true ) ) )
		);

		// Enqueue assets inline so the admin page always gets them.
		NV_oOS_CloudwaysDashboard_Shortcode::enqueue_assets( array() );
	}

	/**
	 * Check whether the addon is enabled in settings.
	 *
	 * @since 0.1.1
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Render an admin notice when the NV oOS base plugin is not active.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public static function maybe_render_missing_base_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( defined( 'WP_MCP_AI_VERSION' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'NV oOS Cloudways Dashboard:', 'nvoos-cloudways-dashboard' ),
			esc_html__( 'the NV oOS base plugin is not active. The Cloudways Dashboard requires the base plugin to function.', 'nvoos-cloudways-dashboard' )
		);
	}

	/**
	 * Render an admin notice when the pre-built SPA bundle is missing.
	 *
	 * @return void
	 */
	public static function maybe_render_missing_bundle_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bundle = NVOOS_CLOUDWAYS_DASHBOARD_PATH . 'assets/dist/cloudways-dashboard.js';
		if ( file_exists( $bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/cloudways-dashboard && npm ci && npm run build</code></p></div>',
			esc_html__( 'NV oOS Cloudways Dashboard:', 'nvoos-cloudways-dashboard' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-cloudways-dashboard' )
		);
	}

	/**
	 * Render an admin notice when the Cloudways Pro toolkit is not configured.
	 *
	 * @return void
	 */
	public static function maybe_render_missing_cloudways_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_mcp_ai_is_cloudways_toolkit_enabled' ) ) {
			return;
		}
		if ( wp_mcp_ai_is_cloudways_toolkit_enabled() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'NV oOS Cloudways Dashboard:', 'nvoos-cloudways-dashboard' ),
			esc_html__( 'Cloudways API credentials are not configured. Go to NV oOS Settings → Cloudways to connect your account.', 'nvoos-cloudways-dashboard' )
		);
	}
}
