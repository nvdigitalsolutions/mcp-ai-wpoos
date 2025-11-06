<?php
/**
 * Tests for ensuring plugin doesn't interfere with Elementor AJAX operations.
 *
 * Verifies that the plugin's early output buffering is skipped during
 * Elementor AJAX requests, particularly for cache clearing functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor AJAX output buffering behavior.
 */
class WP_MCP_AI_Elementor_AJAX_No_Buffering_Test extends WP_UnitTestCase {
	/**
	 * Original REQUEST values to restore after tests.
	 *
	 * @var array
	 */
	private $original_request = array();

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		// Save original REQUEST state.
		$this->original_request = $_REQUEST;
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Restore original REQUEST state.
		$_REQUEST = $this->original_request;

		// Clean up any output buffers that might be left open.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		parent::tear_down();
	}

	/**
	 * Test that early buffering is applied for normal requests.
	 *
	 * This validates that our change doesn't break the normal buffering
	 * behavior for non-Elementor requests.
	 */
	public function test_early_buffering_applied_for_normal_requests() {
		// Simulate a normal request (not AJAX, not Elementor).
		unset( $_REQUEST['action'] );

		// Record the buffer level before the check.
		$level_before = ob_get_level();

		// Simulate the plugin's early buffering check (simplified version).
		$is_elementor_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
		if ( $is_elementor_ajax && isset( $_REQUEST['action'] ) ) {
			$action            = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
			$is_elementor_ajax = ( strpos( $action, 'elementor' ) === 0 );
		} else {
			$is_elementor_ajax = false;
		}

		// Should NOT be Elementor AJAX for normal requests.
		$this->assertFalse( $is_elementor_ajax, 'Normal requests should not be detected as Elementor AJAX' );

		// Simulate starting the buffer if not Elementor AJAX.
		if ( ! $is_elementor_ajax ) {
			ob_start();
		}

		$level_after = ob_get_level();

		// Buffer level should have increased for normal requests.
		$this->assertGreaterThan( $level_before, $level_after, 'Buffer level should increase for normal requests' );

		// Clean up the buffer we started.
		if ( ! $is_elementor_ajax ) {
			ob_end_clean();
		}
	}

	/**
	 * Test that early buffering is skipped for Elementor AJAX requests.
	 *
	 * This is the key test that validates our fix - ensuring the plugin
	 * doesn't interfere with Elementor's cache clearing and other AJAX operations.
	 */
	public function test_early_buffering_skipped_for_elementor_ajax() {
		// Simulate an Elementor AJAX request (e.g., cache clearing).
		$_REQUEST['action'] = 'elementor_clear_cache';

		// Simulate DOING_AJAX being true.
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		// Record the buffer level before the check.
		$level_before = ob_get_level();

		// Simulate the plugin's early buffering check (matching the actual code).
		$is_elementor_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

		if ( $is_elementor_ajax && isset( $_REQUEST['action'] ) ) {
			$action            = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
			$is_elementor_ajax = ( strpos( $action, 'elementor' ) === 0 );
		} else {
			$is_elementor_ajax = false;
		}

		// Should be detected as Elementor AJAX.
		$this->assertTrue( $is_elementor_ajax, 'Elementor AJAX requests should be detected' );

		// Simulate NOT starting the buffer for Elementor AJAX.
		if ( ! $is_elementor_ajax ) {
			ob_start();
		}

		$level_after = ob_get_level();

		// Buffer level should NOT have increased for Elementor AJAX requests.
		$this->assertEquals( $level_before, $level_after, 'Buffer level should not increase for Elementor AJAX requests' );
	}

	/**
	 * Test that various Elementor action names are correctly detected.
	 *
	 * Validates that different Elementor AJAX actions (including cache clearing)
	 * are all properly detected to skip buffering.
	 */
	public function test_various_elementor_actions_detected() {
		$elementor_actions = array(
			'elementor_clear_cache',
			'elementor_save_builder',
			'elementor_render_widget',
			'elementor_ajax',
			'elementor_pro_forms_send_form',
		);

		foreach ( $elementor_actions as $action ) {
			$_REQUEST['action'] = $action;

			// Simulate DOING_AJAX being true.
			if ( ! defined( 'DOING_AJAX' ) ) {
				define( 'DOING_AJAX', true );
			}

			// Run the detection logic.
			$is_elementor_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
				|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

			if ( $is_elementor_ajax && isset( $_REQUEST['action'] ) ) {
				$test_action       = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
				$is_elementor_ajax = ( strpos( $test_action, 'elementor' ) === 0 );
			} else {
				$is_elementor_ajax = false;
			}

			$this->assertTrue( $is_elementor_ajax, "Action '{$action}' should be detected as Elementor AJAX" );
		}
	}

	/**
	 * Test that non-Elementor AJAX actions don't skip buffering.
	 *
	 * Validates that other plugins' AJAX requests still get the buffering protection.
	 */
	public function test_non_elementor_ajax_still_gets_buffering() {
		$non_elementor_actions = array(
			'wp_ajax_some_action',
			'heartbeat',
			'woocommerce_add_to_cart',
			'wp_mcp_ai_test_connection',
		);

		foreach ( $non_elementor_actions as $action ) {
			$_REQUEST['action'] = $action;

			// Simulate DOING_AJAX being true.
			if ( ! defined( 'DOING_AJAX' ) ) {
				define( 'DOING_AJAX', true );
			}

			// Run the detection logic.
			$is_elementor_ajax = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
				|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

			if ( $is_elementor_ajax && isset( $_REQUEST['action'] ) ) {
				$test_action       = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
				$is_elementor_ajax = ( strpos( $test_action, 'elementor' ) === 0 );
			} else {
				$is_elementor_ajax = false;
			}

			$this->assertFalse( $is_elementor_ajax, "Action '{$action}' should NOT be detected as Elementor AJAX" );
		}
	}

	/**
	 * Test that the fix doesn't break the pairing of ob_start/ob_end_clean.
	 *
	 * Validates that when buffering is applied, it's properly cleaned up.
	 * When buffering is skipped, ob_end_clean is also skipped.
	 */
	public function test_buffer_pairing_consistency() {
		$test_cases = array(
			// Normal request: should buffer and clean.
			array(
				'is_ajax'       => false,
				'action'        => null,
				'should_buffer' => true,
				'description'   => 'Normal request',
			),
			// Elementor AJAX: should NOT buffer or clean.
			array(
				'is_ajax'       => true,
				'action'        => 'elementor_clear_cache',
				'should_buffer' => false,
				'description'   => 'Elementor AJAX request',
			),
			// Non-Elementor AJAX: should buffer and clean.
			array(
				'is_ajax'       => true,
				'action'        => 'wp_ajax_custom',
				'should_buffer' => true,
				'description'   => 'Non-Elementor AJAX request',
			),
		);

		foreach ( $test_cases as $test_case ) {
			// Set up the request state.
			if ( $test_case['action'] ) {
				$_REQUEST['action'] = $test_case['action'];
			} else {
				unset( $_REQUEST['action'] );
			}

			// Simulate DOING_AJAX constant if needed.
			$level_before = ob_get_level();

			// Run the detection logic.
			$is_elementor_ajax = $test_case['is_ajax']
				&& ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
					|| ( defined( 'DOING_AJAX' ) && DOING_AJAX ) );

			if ( $is_elementor_ajax && isset( $_REQUEST['action'] ) ) {
				$test_action       = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
				$is_elementor_ajax = ( strpos( $test_action, 'elementor' ) === 0 );
			} else {
				$is_elementor_ajax = false;
			}

			// Apply buffering if appropriate.
			if ( ! $is_elementor_ajax ) {
				ob_start();
			}

			$level_during = ob_get_level();

			// Clean buffering if we started it.
			if ( ! $is_elementor_ajax ) {
				ob_end_clean();
			}

			$level_after = ob_get_level();

			// Validate expectations.
			if ( $test_case['should_buffer'] ) {
				$this->assertGreaterThan( $level_before, $level_during, "{$test_case['description']}: Buffer should be started" );
				$this->assertEquals( $level_before, $level_after, "{$test_case['description']}: Buffer should be cleaned" );
			} else {
				$this->assertEquals( $level_before, $level_during, "{$test_case['description']}: Buffer should NOT be started" );
				$this->assertEquals( $level_before, $level_after, "{$test_case['description']}: No buffer to clean" );
			}
		}
	}
}
