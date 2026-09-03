<?php
/**
 * Admin Scripts Registration for NV oOS.
 *
 * Registers and enqueues admin scripts globally to ensure they're available
 * across all admin pages and metaboxes, preventing conflicts in base + pro mode.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Compatibility shim: guarantee jQuery UI Sortable exists on post edit
		// screens before third-party admin scripts run.
		self::ensure_sortable_compatibility();

		// Register model selector script with localization.
		// This is used by Assistant, Profession, and Team metaboxes.
		self::register_model_selector_script();
	}

	/**
	 * Enqueue jquery-ui-sortable as a compatibility shim for third-party
	 * admin scripts on post edit screens.
	 *
	 * Some themes and plugins call jQuery.fn.sortable() from a ready handler
	 * without declaring jquery-ui-sortable as a script dependency (e.g.
	 * tagDiv Newspaper's td_wp_admin.min.js). When that script runs before
	 * jQuery UI Sortable is loaded, the call throws
	 * "sortable is not a function", which halts the remaining scripts on the
	 * edit screen and prevents the page from loading fully.
	 *
	 * This registrar runs at priority 5, before most other
	 * admin_enqueue_scripts callbacks, so the core-registered handle is
	 * enqueued first and is printed before those scripts execute. Enqueuing
	 * a core handle is idempotent and adds no asset of our own.
	 */
	private static function ensure_sortable_compatibility() {
		wp_enqueue_script( 'jquery-ui-sortable' );
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
