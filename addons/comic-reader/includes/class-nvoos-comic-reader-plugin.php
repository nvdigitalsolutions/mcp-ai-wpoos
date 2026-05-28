<?php
/**
 * NV oOS Comic Reader — Core Plugin Class
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Comic Reader addon.
 *
 * Registers shortcode, block, REST routes, and handles asset enqueuing.
 *
 * @since 0.1.0
 */
class NV_oOS_Comic_Reader_Plugin {

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// Register MIME types for comic archive uploads.
		NV_oOS_Comic_Reader_Mime::init();

		add_action( 'init', array( 'NV_oOS_Comic_Reader_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_Comic_Reader_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_Comic_Reader_REST', 'register_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_bundle_notice' ) );
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
		$bundle = NVOOS_COMIC_READER_PATH . 'assets/dist/comic-reader.js';
		if ( file_exists( $bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/comic-reader &amp;&amp; npm ci &amp;&amp; npm run build</code></p></div>',
			esc_html__( 'NV oOS Comic Reader:', 'nvoos-comic-reader' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-comic-reader' )
		);
	}
}
