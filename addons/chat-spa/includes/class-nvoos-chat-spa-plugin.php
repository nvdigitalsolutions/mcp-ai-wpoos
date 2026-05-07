<?php
/**
 * NV oOS Chat SPA — Core Plugin Class
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Chat SPA addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Chat_Spa_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_chat_spa_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( 'NV_oOS_Chat_Spa_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_Chat_Spa_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_Chat_Spa_REST', 'register_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_bundle_notice' ) );
		if ( is_admin() ) {
			NV_oOS_Chat_Spa_Admin_Page::register();
		}
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
		$bundle = NVOOS_CHAT_SPA_PATH . 'assets/dist/chat-spa.js';
		if ( file_exists( $bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/chat-spa && npm ci && npm run build</code></p></div>',
			esc_html__( 'NV oOS Chat SPA:', 'nvoos-chat-spa' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-chat-spa' )
		);
	}
}
