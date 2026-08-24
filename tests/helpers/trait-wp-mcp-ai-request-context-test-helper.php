<?php
/**
 * Leak-free request-context helpers for test classes.
 *
 * Two kinds of per-test state are impossible to undo naively and therefore
 * bleed into every later test in the shared PHPUnit process:
 *
 * 1. **`DOING_AJAX`** — a constant, so `define( 'DOING_AJAX', true )` inside a
 *    test can never be reverted. Every subsequent `wp_doing_ajax()` call in the
 *    run then reports true, which silently changes how WordPress terminates
 *    requests (`wp_send_json()`, `check_ajax_referer()`) and makes production
 *    guards of the shape `if ( wp_doing_ajax() ) { return; }` skip their work.
 *    Use {@see self::simulate_ajax_context()} instead: it goes through the
 *    `wp_doing_ajax` filter, which is reversible.
 *
 * 2. **Output buffers** — `while ( ob_get_level() > 0 ) { ob_end_clean(); }`
 *    unwinds past the buffer PHPUnit opens around each test, which PHPUnit
 *    reports as "Test code or tested code closed output buffers other than its
 *    own" and which discards output the runner still needed. Record a baseline
 *    in `set_up()` with {@see self::record_output_buffer_baseline()} and unwind
 *    only down to it with {@see self::unwind_output_buffers()}.
 *
 * ## Usage
 *
 * ```php
 * class My_Test extends WP_UnitTestCase {
 *     use WP_MCP_AI_Request_Context_Test_Helper;
 *
 *     public function set_up() {
 *         parent::set_up();
 *         $this->record_output_buffer_baseline();
 *     }
 *
 *     public function tear_down() {
 *         $this->end_ajax_context();
 *         $this->unwind_output_buffers();
 *         parent::tear_down();
 *     }
 *
 *     public function test_something_ajax() {
 *         $this->simulate_ajax_context();
 *         $this->assertTrue( wp_doing_ajax() );
 *     }
 * }
 * ```
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Request-context test helper.
 */
trait WP_MCP_AI_Request_Context_Test_Helper {

	/**
	 * Whether the simulated AJAX context is currently installed.
	 *
	 * @var bool
	 */
	private $wp_mcp_ai_ajax_context_active = false;

	/**
	 * Output-buffer level recorded at set-up time.
	 *
	 * Null until {@see self::record_output_buffer_baseline()} runs.
	 *
	 * @var int|null
	 */
	private $wp_mcp_ai_output_buffer_baseline = null;

	/**
	 * Make `wp_doing_ajax()` report true for the remainder of the test.
	 *
	 * Reversible stand-in for `define( 'DOING_AJAX', true )`. Idempotent, so
	 * loops over several fixtures can call it freely.
	 */
	protected function simulate_ajax_context() {
		if ( $this->wp_mcp_ai_ajax_context_active ) {
			return;
		}

		// Priority 99 so this wins over the bootstrap's own `wp_doing_ajax`
		// filter, which only reports true for AJAX-shaped payloads.
		add_filter( 'wp_doing_ajax', '__return_true', 99 );
		$this->wp_mcp_ai_ajax_context_active = true;
	}

	/**
	 * Drop the simulated AJAX context.
	 *
	 * Safe to call when no context was installed, so it belongs in `tear_down()`
	 * unconditionally.
	 */
	protected function end_ajax_context() {
		if ( ! $this->wp_mcp_ai_ajax_context_active ) {
			return;
		}

		remove_filter( 'wp_doing_ajax', '__return_true', 99 );
		$this->wp_mcp_ai_ajax_context_active = false;
	}

	/**
	 * Report whether the simulated AJAX context is installed.
	 *
	 * @return bool
	 */
	protected function is_ajax_context_simulated() {
		return $this->wp_mcp_ai_ajax_context_active;
	}

	/**
	 * Record the current output-buffer level as this test's floor.
	 *
	 * Call from `set_up()` after `parent::set_up()`; PHPUnit has already opened
	 * its own buffer by then, so the recorded level protects it.
	 */
	protected function record_output_buffer_baseline() {
		$this->wp_mcp_ai_output_buffer_baseline = ob_get_level();
	}

	/**
	 * Close buffers opened during the test, never the ones that pre-dated it.
	 *
	 * Falls back to the current level (i.e. a no-op) when no baseline was
	 * recorded, so a missing `set_up()` call cannot make things worse.
	 */
	protected function unwind_output_buffers() {
		$baseline = null === $this->wp_mcp_ai_output_buffer_baseline
			? ob_get_level()
			: $this->wp_mcp_ai_output_buffer_baseline;

		while ( ob_get_level() > $baseline ) {
			ob_end_clean();
		}
	}

	/**
	 * Restore `$_SERVER['REQUEST_URI']` to a previously captured value.
	 *
	 * `WP_UnitTestCase_Base` does not reset `$_SERVER`, so a test that
	 * overwrites the request URI leaks it into every later test in the process.
	 * Capture the old value with `$_SERVER['REQUEST_URI'] ?? null` and hand it
	 * back here.
	 *
	 * @param string|null $original_uri Value captured before the test overwrote it.
	 */
	protected function restore_request_uri( $original_uri ) {
		if ( null === $original_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
			return;
		}

		$_SERVER['REQUEST_URI'] = $original_uri;
	}
}
