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
	 * Sortable inline.
	 *
	 * Queue state alone is not proof the script will actually execute before
	 * document-ready either: on nugl.com the core handle was registered, had
	 * a source, and was enqueued — yet td_wp_admin.min.js still threw
	 * "sortable is not a function" at ready time (third-party queue or print
	 * tampering outside WordPress' own queue view). When the tagDiv admin
	 * script is present on the page, the fallback therefore always prints the
	 * bundled copy inline, regardless of queue health.
	 *
	 * Because head scripts and the wp_print_footer_scripts action both
	 * execute before any document-ready handler, the fallback guarantees
	 * jQuery.fn.sortable exists before third-party ready callbacks run,
	 * regardless of queue tampering.
	 *
	 * td_post_gallery() also patches wp.media.view.Attachment.Library from
	 * the same ready handler, so pages carrying td_wp_admin additionally
	 * receive the WordPress media script chain as plain head tags (see
	 * print_media_compatibility_scripts()) — the enqueue queue has proven
	 * unreliable for those assets on the same site.
	 */
	private static function ensure_sortable_compatibility() {
		wp_enqueue_script( 'jquery-ui-sortable' );

		// The admin_head pass catches handles removed during the enqueue
		// phase; the footer pass catches handles removed even later.
		add_action( 'admin_head', array( __CLASS__, 'print_sortable_compatibility_fallback' ), 1 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'print_sortable_compatibility_fallback' ), 1 );
		add_action( 'admin_head', array( __CLASS__, 'print_media_compatibility_scripts' ), 1 );
	}

	/**
	 * Print a bundled jQuery UI Sortable copy when the core-registered
	 * handle will not load, or whenever the tagDiv admin script is present
	 * on the page, so jQuery.fn.sortable exists before any document-ready
	 * handler can execute.
	 *
	 * Runs after every admin_enqueue_scripts callback (and, on the footer
	 * pass, after admin_footer) has had the chance to dequeue or deregister
	 * the core handle, so it always inspects the final queue state.
	 *
	 * A healthy core queue is normally enough, but a registered+enqueued
	 * handle can still fail to execute before ready on pages where a third
	 * party tampers with printing or execution (observed on nugl.com with
	 * tagDiv's td_wp_admin.min.js). When td_wp_admin is present the bundled
	 * copy is therefore printed unconditionally: redefining jQuery.fn.sortable
	 * with the same version is harmless.
	 *
	 * @return void
	 */
	public static function print_sortable_compatibility_fallback() {
		if ( self::core_sortable_will_load() && ! self::td_wp_admin_present() ) {
			// The core queue will print the handle (head or footer) and the
			// tagDiv admin script is absent, so no fallback is needed.
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
	 * Print the WordPress media script chain as plain head tags when the
	 * tagDiv admin script is present on the page.
	 *
	 * td_post_gallery() patches wp.media.view.Attachment.Library inside the
	 * same document-ready handler that calls .sortable(). On nugl.com the
	 * media scripts enqueued through the normal queue (wp_enqueue_media)
	 * never executed before ready — the same third-party queue/print
	 * interference that broke jQuery UI Sortable. Printing each core file as
	 * a plain script tag bypasses WP_Scripts, load-scripts.php
	 * concatenation, and any third-party dequeueing, so wp.media is
	 * guaranteed to exist before the ready handler runs. Re-running these
	 * files when a healthy queue prints them again later is harmless: they
	 * define the same globals.
	 *
	 * @return void
	 */
	public static function print_media_compatibility_scripts() {
		if ( ! self::td_wp_admin_present() ) {
			return;
		}

		// Register the media localization payloads so they can be printed
		// with the direct tags below; an idempotent enqueue on healthy
		// queues.
		wp_enqueue_media();

		$handles = array(
			'underscore',
			'backbone',
			'wp-util',
			'wp-hooks',
			'wp-i18n',
			'wp-api-fetch',
			'mediaelement',
			'media-models',
			'utils',
			'imagesloaded',
			'media-views',
			'media-editor',
		);

		// Source the files from the core-registered handles instead of
		// hardcoding paths: file names differ across WordPress versions
		// (e.g. wp-hooks loads from hooks.min.js, and api-fetch moved in
		// newer cores).
		foreach ( $handles as $handle ) {
			if ( ! isset( wp_scripts()->registered[ $handle ] ) ) {
				continue;
			}

			$src = wp_scripts()->registered[ $handle ]->src;
			if ( empty( $src ) ) {
				continue;
			}

			// Print the handle's localized settings before its file, if any.
			$data = wp_scripts()->get_data( $handle, 'data' );
			if ( $data ) {
				wp_print_inline_script_tag( $data );
			}

			wp_print_script_tag(
				array(
					'src' => $src,
				)
			);
		}
	}

	/**
	 * Whether the tagDiv admin script is enqueued on this page (present in
	 * the print queue), identified by its source containing "td_wp_admin".
	 *
	 * The check targets the queue rather than the registration table so a
	 * script intentionally dequeued by another plugin (e.g. on the assistant
	 * edit screen, where the theme script is not needed) does not trigger
	 * the compatibility fallbacks.
	 *
	 * td_wp_admin.min.js calls jQuery.fn.sortable() from a ready handler
	 * without declaring the dependency, and queue health alone has proven
	 * unreliable on production sites, so pages carrying this script always
	 * receive the inline fallback.
	 *
	 * @return bool True when a td_wp_admin script is enqueued on the page.
	 */
	private static function td_wp_admin_present() {
		$wp_scripts = wp_scripts();

		foreach ( (array) $wp_scripts->queue as $handle ) {
			if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
				continue;
			}

			$src = $wp_scripts->registered[ $handle ]->src;

			if ( is_string( $src ) && false !== strpos( $src, 'td_wp_admin' ) ) {
				return true;
			}
		}

		return false;
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
