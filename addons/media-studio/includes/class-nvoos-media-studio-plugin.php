<?php
/**
 * NV oOS Media Studio — Core Plugin Class
 *
 * @package NV_oOS_Media_Studio
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Media Studio addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Media_Studio_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_media_studio_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( 'NV_oOS_Media_Studio_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_Media_Studio_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_Media_Studio_REST', 'register_routes' ) );
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
		$bundle = NVOOS_MEDIA_STUDIO_PATH . 'assets/dist/media-studio.js';
		if ( file_exists( $bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/media-studio && npm ci && npm run build</code></p></div>',
			esc_html__( 'NV oOS Media Studio:', 'nvoos-media-studio' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-media-studio' )
		);
	}
}
