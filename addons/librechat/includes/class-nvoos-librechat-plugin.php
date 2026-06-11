<?php
/**
 * NV oOS LibreChat — Core Plugin Class
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS LibreChat addon.
 *
 * @since 0.1.0
 */
class NV_oOS_LibreChat_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_librechat_settings';

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'theme'                    => 'dark',
		'default_assistant_id'     => 0,
		'enable_code_interpreter'  => true,
		'enable_web_search'        => true,
		'enable_speech'            => false,
		'code_interpreter_timeout' => 60,
		'web_search_provider'      => 'tavily',
		'rerank_provider'          => 'jina',
		'speech_tts_provider'      => '',
		'speech_stt_provider'      => '',
		'enable_artifacts'         => true,
		'max_executions_per_hour'  => 10,
	);

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( 'NV_oOS_LibreChat_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_LibreChat_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_LibreChat_REST', 'register_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_bundle_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_dependency_notice' ) );
		if ( is_admin() ) {
			NV_oOS_LibreChat_Admin_Page::register();
		}
	}

	/**
	 * Get plugin settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $settings, self::DEFAULTS );
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
		$bundle = NVOOS_LIBRECHAT_PATH . 'assets/dist/librechat.js';
		if ( file_exists( $bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/librechat && npm ci && npm run build</code></p></div>',
			esc_html__( 'NV oOS LibreChat:', 'nvoos-librechat' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-librechat' )
		);
	}

	/**
	 * Render an admin notice if the base NV oOS plugin is not active.
	 *
	 * @return void
	 */
	public static function maybe_render_missing_dependency_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'NV oOS LibreChat:', 'nvoos-librechat' ),
			esc_html__( 'requires the NV oOS (Open Operator System) base plugin to be active.', 'nvoos-librechat' )
		);
	}
}
