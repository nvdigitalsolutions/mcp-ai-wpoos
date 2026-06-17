<?php
/**
 * NV oOS Document Editor — Core Plugin Class
 *
 * @package NV_oOS_Document_Editor
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Document Editor addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Document_Editor_Plugin {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_document_editor_settings';

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 12 );
		add_action( 'init', array( 'NV_oOS_Document_Editor_Shortcode', 'register' ), 12 );
		add_action( 'init', array( 'NV_oOS_Document_Editor_Block', 'register' ), 12 );
		add_action( 'rest_api_init', array( 'NV_oOS_Document_Editor_REST', 'register_routes' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_missing_bundle_notice' ) );
	}

	/**
	 * Register the nvoos_document custom post type.
	 *
	 * Priority ≥ 12 so it runs after JetEngine's CCT init (priorities 1-10).
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			NV_oOS_Document_Editor_REST::POST_TYPE,
			array(
				'label'           => __( 'Documents', 'nvoos-document-editor' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false, // Exposed via our own REST routes only.
				'supports'        => array( 'title', 'editor', 'author' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
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
		$bundle = NVOOS_DOCUMENT_EDITOR_PATH . 'assets/dist/document-editor.js';
		if ( file_exists( $bundle ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>cd addons/document-editor && npm ci && npm run build</code></p></div>',
			esc_html__( 'NV oOS Document Editor:', 'nvoos-document-editor' ),
			esc_html__( 'pre-built SPA bundle is missing. Build it with:', 'nvoos-document-editor' )
		);
	}
}
