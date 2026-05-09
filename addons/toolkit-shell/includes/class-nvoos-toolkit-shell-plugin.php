<?php
/**
 * NV oOS Toolkit Shell — Core Plugin Class
 *
 * Singleton that registers all hooks for the manifest-driven SPA shell.
 *
 * @package NV_oOS_Toolkit_Shell
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
class NV_oOS_Toolkit_Shell_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_toolkit_shell_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( 'NV_oOS_Toolkit_Shell_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_Toolkit_Shell_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_Toolkit_Shell_REST', 'register_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_bundle_notice' ) );
	}

	/**
	 * Render an admin notice when the pre-built SPA bundle is missing.
	 *
	 * Mirrors the SaaS Controller pattern.
	 *
	 * @return void
	 */
	public static function maybe_render_missing_bundle_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bundle = NVOOS_TOOLKIT_SHELL_PATH . 'assets/dist/toolkit-shell.js';
		if ( file_exists( $bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/toolkit-shell && npm ci && npm run build</code></p></div>',
			esc_html__( 'NV oOS Toolkit Shell:', 'nvoos-toolkit-shell' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-toolkit-shell' )
		);
	}
}
