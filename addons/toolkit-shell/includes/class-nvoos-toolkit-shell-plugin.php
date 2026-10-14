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
	 * Option key storing the auto-provisioned embed page ID.
	 *
	 * @var string
	 */
	const EMBED_PAGE_OPTION = 'nvoos_toolkit_shell_embed_page';

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
		add_action( 'admin_init', array( __CLASS__, 'maybe_provision_embed_page' ) );
	}

	/**
	 * Ensure a published page embedding [nvoos_toolkit_app] exists.
	 *
	 * The Pro SPA Surfaces controller resolves a shortcode surface to the first
	 * published page rendering its shortcode. Without an embed page it falls back
	 * to the home URL (a broken iframe). Idempotent: it only creates a page when
	 * none exists, and it never modifies user-authored pages.
	 *
	 * @return void
	 */
	public static function maybe_provision_embed_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$cache_key = 'wp_mcp_ai_pro_spa_page_nvoos_toolkit_app';
		delete_transient( $cache_key );

		$stored = get_option( self::EMBED_PAGE_OPTION, 0 );
		$stored = is_numeric( $stored ) ? (int) $stored : 0;

		if ( $stored > 0 ) {
			$page = get_post( $stored );
			if ( $page && 'publish' === $page->post_status && false !== strpos( (string) $page->post_content, '[nvoos_toolkit_app' ) ) {
				return;
			}
			delete_option( self::EMBED_PAGE_OPTION );
		}

		// Reuse any existing published page that already renders the shortcode
		// (mirrors the Pro controller's find_page_with_shortcode resolution),
		// so we never create a duplicate when one already exists.
		$existing = self::find_page_with_shortcode( 'nvoos_toolkit_app' );
		if ( $existing > 0 ) {
			update_option( self::EMBED_PAGE_OPTION, $existing, false );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'NV oOS Toolkit', 'nvoos-toolkit-shell' ),
				'post_content' => '[nvoos_toolkit_app toolkit="crm"]',
			),
			true
		);

		if ( is_wp_error( $page_id ) || (int) $page_id <= 0 ) {
			return;
		}

		update_option( self::EMBED_PAGE_OPTION, (int) $page_id, false );
	}

	/**
	 * Find the first published page/post containing the given shortcode.
	 *
	 * Mirrors WP_MCP_AI_Pro_SPA_Surfaces_Controller::find_page_with_shortcode()
	 * (newest-first content scan with a needle of `[shortcode`), so provisioning
	 * and the Pro discovery layer agree on which page "owns" a surface.
	 *
	 * @param string $shortcode Shortcode tag (without brackets).
	 * @return int Post ID, or 0 when none found.
	 */
	private static function find_page_with_shortcode( $shortcode ) {
		$needle = '[' . $shortcode;
		$ids    = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $post_id ) {
			$content = get_post_field( 'post_content', $post_id );
			if ( is_string( $content ) && false !== strpos( $content, $needle ) ) {
				return (int) $post_id;
			}
		}

		return 0;
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
