<?php
/**
 * Admin Scripts Registration for NV oOS.
 *
 * Registers and enqueues admin scripts globally to ensure they're available
 * across all admin pages and metaboxes, preventing conflicts in base + pro mode.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles global registration of admin scripts.
 *
 * This class ensures scripts like the model selector are properly registered
 * and localized once during admin_enqueue_scripts, before any metabox tries
 * to use them. This prevents issues in base + pro mode where multiple plugins
 * might try to enqueue the same script.
 */
class WP_MCP_AI_Admin_Scripts {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_scripts' ), 5 );
	}

	/**
	 * Register admin scripts globally.
	 *
	 * Priority 5 ensures this runs before most other admin_enqueue_scripts callbacks.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function register_scripts( $hook ) {
		// Only register on post edit screens where metaboxes appear.
		$post_screens = array( 'post.php', 'post-new.php' );
		if ( ! in_array( $hook, $post_screens, true ) ) {
			return;
		}

		// Register model selector script with localization.
		// This is used by Assistant, Profession, and Team metaboxes.
		self::register_model_selector_script();
	}

	/**
	 * Register the model selector script with localization.
	 *
	 * Registers the script without enqueuing it. Metaboxes will enqueue it
	 * when needed, but the localization will already be attached.
	 */
	private static function register_model_selector_script() {
		$handle = 'wp-mcp-ai-model-selector';

		// Check if already registered to avoid duplicate registration.
		if ( wp_script_is( $handle, 'registered' ) ) {
			return;
		}

		// Register the script.
		wp_register_script(
			$handle,
			WP_MCP_AI_URL . 'assets/js/admin-model-selector.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Localize script for AJAX.
		// This happens once during registration, not during enqueue.
		wp_localize_script(
			$handle,
			'wpMcpAiModelSelector',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'wp-mcp-ai-model-selector' ),
				'selectModelText' => __( '— Select Model —', 'mcp-ai-wpoos' ),
				'errorMessage'    => __( 'Failed to load models. Please try again.', 'mcp-ai-wpoos' ),
			)
		);
	}
}
