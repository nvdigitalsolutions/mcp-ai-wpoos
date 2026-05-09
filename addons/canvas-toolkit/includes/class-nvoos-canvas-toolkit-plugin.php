<?php
/**
 * NV oOS Canvas Toolkit — Core Plugin Class
 *
 * @package NV_oOS_Canvas_Toolkit
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Canvas Toolkit addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Canvas_Toolkit_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_canvas_toolkit_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( 'NV_oOS_Canvas_Toolkit_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_Canvas_Toolkit_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_Canvas_Toolkit_REST', 'register_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_bundle_notice' ) );
	}

	/**
	 * Render an admin notice when the pre-built SPA bundle is missing.
	 *
	 * Mirrors the SaaS Controller pattern: when assets/dist/<slug>.js is not
	 * present, operators see a clear error instead of a silent broken widget.
	 *
	 * @return void
	 */
	public static function maybe_render_missing_bundle_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bundle = NVOOS_CANVAS_TOOLKIT_PATH . 'assets/dist/canvas-toolkit.js';
		if ( file_exists( $bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/canvas-toolkit && npm ci && npm run build</code></p></div>',
			esc_html__( 'NV oOS Canvas Toolkit:', 'nvoos-canvas-toolkit' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-canvas-toolkit' )
		);
	}
}
