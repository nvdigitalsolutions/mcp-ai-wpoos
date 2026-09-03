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
	 *
	 * Enqueuing the core handle is not sufficient on its own: another plugin
	 * or theme can dequeue or deregister it later in the enqueue phase (or
	 * even after admin_head), leaving the page without jQuery UI Sortable.
	 * A fallback therefore re-checks the final queue state at admin_head and
	 * at wp_print_footer_scripts and prints a bundled copy of jQuery UI
	 * Sortable inline. Because head scripts and the wp_print_footer_scripts
	 * action both execute before any document-ready handler, the fallback
	 * guarantees jQuery.fn.sortable exists before third-party ready
	 * callbacks run, regardless of queue tampering.
	 */
	private static function ensure_sortable_compatibility() {
		wp_enqueue_script( 'jquery-ui-sortable' );

		// The admin_head pass catches handles removed during the enqueue
		// phase; the footer pass catches handles removed even later.
		add_action( 'admin_head', array( __CLASS__, 'print_sortable_compatibility_fallback' ), 1 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'print_sortable_compatibility_fallback' ), 1 );
	}

	/**
	 * Print a bundled jQuery UI Sortable copy when the core-registered
	 * handle will not load, so jQuery.fn.sortable exists before any
	 * document-ready handler can execute.
	 *
	 * Runs after every admin_enqueue_scripts callback (and, on the footer
	 * pass, after admin_footer) has had the chance to dequeue or deregister
	 * the core handle, so it always inspects the final queue state.
	 *
	 * @return void
	 */
	public static function print_sortable_compatibility_fallback() {
		if ( self::core_sortable_will_load() ) {
			// The core queue will print the handle (head or footer). Ready
			// handlers only run after all synchronous footer scripts have
			// executed, so no fallback is needed.
			return;
		}

		$fallback = WP_MCP_AI_PATH . 'assets/js/vendor/jquery-ui-sortable.min.js';

		if ( ! file_exists( $fallback ) || ! is_readable( $fallback ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled asset read.
		$source = file_get_contents( $fallback );

		if ( false === $source ) {
			return;
		}

		wp_print_inline_script_tag( $source );

		// A copy is already printed: the remaining safety-net hook must not
		// print a second one.
		remove_action( 'wp_print_footer_scripts', array( __CLASS__, 'print_sortable_compatibility_fallback' ), 1 );
	}

	/**
	 * Whether the core jquery-ui-sortable handle is registered with a
	 * printable source and enqueued, meaning the core queue will load it.
	 *
	 * @return bool True when the core queue will load jQuery UI Sortable.
	 */
	private static function core_sortable_will_load() {
		$wp_scripts = wp_scripts();

		if ( ! isset( $wp_scripts->registered['jquery-ui-sortable'] ) ) {
			return false;
		}

		if ( empty( $wp_scripts->registered['jquery-ui-sortable']->src ) ) {
			// Deregistered or hijacked with no usable source.
			return false;
		}

		return (bool) wp_script_is( 'jquery-ui-sortable', 'enqueued' );
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
