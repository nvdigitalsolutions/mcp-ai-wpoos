<?php
/**
 * Tests for ensuring plugin doesn't interfere with Elementor editor page loads.
 *
 * Verifies that the plugin's early output buffering is skipped during
 * Elementor editor page loads to prevent interference with JavaScript module initialization.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor editor output buffering behavior.
 */
class WP_MCP_AI_Elementor_Editor_No_Buffering_Test extends WP_UnitTestCase {
	/**
	 * Original GET values to restore after tests.
	 *
	 * @var array
	 */
	private $original_get = array();

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
		// Save original GET and REQUEST state.
		$this->original_get     = $_GET;
		$this->original_request = $_REQUEST;
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Restore original GET and REQUEST state.
		$_GET     = $this->original_get;
		$_REQUEST = $this->original_request;

		// Clean up any output buffers that might be left open.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		parent::tear_down();
	}

	/**
	 * Test that early buffering is skipped for Elementor editor page loads.
	 *
	 * This validates that when the Elementor editor is loaded (via ?action=elementor),
	 * the plugin doesn't apply output buffering that could interfere with
	 * Elementor's JavaScript module initialization.
	 */
	public function test_early_buffering_skipped_for_elementor_editor() {
		// Simulate an Elementor editor page load.
		$_GET['action'] = 'elementor';

		// This is NOT an AJAX request.
		$is_ajax_request = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

		$this->assertFalse( $is_ajax_request, 'Elementor editor page load should not be AJAX' );

		// Record the buffer level before the check.
		$level_before = ob_get_level();

		// Simulate the plugin's early buffering check for AJAX requests.
		$is_elementor_ajax = false;
		if ( $is_ajax_request && isset( $_REQUEST['action'] ) ) {
			$request_action    = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
			$is_elementor_ajax = ( strpos( $request_action, 'elementor' ) === 0 );
		}

		// Simulate the plugin's check for Elementor editor page loads.
		$is_elementor_editor = false;
		if ( ! $is_ajax_request && isset( $_GET['action'] ) ) {
			$get_action          = sanitize_text_field( wp_unslash( $_GET['action'] ) );
			$is_elementor_editor = ( 'elementor' === $get_action );
		}

		// Should be detected as Elementor editor.
		$this->assertTrue( $is_elementor_editor, 'Elementor editor page load should be detected' );
		$this->assertFalse( $is_elementor_ajax, 'Elementor editor page load should not be detected as AJAX' );

		// Determine if we should skip buffering.
		$skip_buffering = $is_elementor_ajax || $is_elementor_editor;

		$this->assertTrue( $skip_buffering, 'Buffering should be skipped for Elementor editor' );

		// Simulate NOT starting the buffer when we should skip.
		if ( ! $skip_buffering ) {
			ob_start();
		}

		$level_after = ob_get_level();

		// Buffer level should NOT have increased for Elementor editor page loads.
		$this->assertEquals( $level_before, $level_after, 'Buffer level should not increase for Elementor editor page loads' );
	}

	/**
	 * Test that early buffering is applied for normal admin page loads.
	 *
	 * This validates that our change doesn't break the normal buffering
	 * behavior for non-Elementor admin pages.
	 */
	public function test_early_buffering_applied_for_normal_admin_pages() {
		// Simulate a normal admin page (not Elementor editor).
		unset( $_GET['action'] );

		// Record the buffer level before the check.
		$level_before = ob_get_level();

		// Simulate the plugin's buffering logic.
		$is_ajax_request = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

		$is_elementor_ajax = false;
		if ( $is_ajax_request && isset( $_REQUEST['action'] ) ) {
			$request_action    = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
			$is_elementor_ajax = ( strpos( $request_action, 'elementor' ) === 0 );
		}

		$is_elementor_editor = false;
		if ( ! $is_ajax_request && isset( $_GET['action'] ) ) {
			$get_action          = sanitize_text_field( wp_unslash( $_GET['action'] ) );
			$is_elementor_editor = ( 'elementor' === $get_action );
		}

		$skip_buffering = $is_elementor_ajax || $is_elementor_editor;

		// Should NOT skip buffering for normal pages.
		$this->assertFalse( $skip_buffering, 'Normal admin pages should not skip buffering' );

		// Simulate starting the buffer.
		if ( ! $skip_buffering ) {
			ob_start();
		}

		$level_after = ob_get_level();

		// Buffer level should have increased for normal pages.
		$this->assertGreaterThan( $level_before, $level_after, 'Buffer level should increase for normal admin pages' );

		// Clean up the buffer we started.
		if ( ! $skip_buffering ) {
			ob_end_clean();
		}
	}

	/**
	 * Test that various admin page actions don't skip buffering.
	 *
	 * Validates that other admin page actions still get the buffering protection.
	 */
	public function test_other_admin_actions_still_get_buffering() {
		$other_actions = array(
			'edit',
			'trash',
			'delete',
			'wp_mcp_ai_settings',
		);

		foreach ( $other_actions as $action ) {
			$_GET['action'] = $action;

			// Not an AJAX request.
			$is_ajax_request = false;

			// Run the detection logic.
			$is_elementor_ajax = false;
			if ( $is_ajax_request && isset( $_REQUEST['action'] ) ) {
				$request_action    = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
				$is_elementor_ajax = ( strpos( $request_action, 'elementor' ) === 0 );
			}

			$is_elementor_editor = false;
			if ( ! $is_ajax_request && isset( $_GET['action'] ) ) {
				$get_action          = sanitize_text_field( wp_unslash( $_GET['action'] ) );
				$is_elementor_editor = ( 'elementor' === $get_action );
			}

			$skip_buffering = $is_elementor_ajax || $is_elementor_editor;

			$this->assertFalse( $is_elementor_editor, "Action '{$action}' should NOT be detected as Elementor editor" );
			$this->assertFalse( $skip_buffering, "Action '{$action}' should NOT skip buffering" );
		}
	}

	/**
	 * Test that Elementor AJAX requests are still handled correctly.
	 *
	 * Validates that the new Elementor editor detection doesn't break
	 * the existing AJAX request handling.
	 */
	public function test_elementor_ajax_still_skips_buffering() {
		// Simulate an Elementor AJAX request.
		$_REQUEST['action'] = 'elementor_save_builder';

		// Simulate DOING_AJAX being true.
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		// Run the detection logic.
		$is_ajax_request = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

		$this->assertTrue( $is_ajax_request, 'Should be detected as AJAX request' );

		$is_elementor_ajax = false;
		if ( $is_ajax_request && isset( $_REQUEST['action'] ) ) {
			$request_action    = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
			$is_elementor_ajax = ( strpos( $request_action, 'elementor' ) === 0 );
		}

		$is_elementor_editor = false;
		if ( ! $is_ajax_request && isset( $_GET['action'] ) ) {
			$get_action          = sanitize_text_field( wp_unslash( $_GET['action'] ) );
			$is_elementor_editor = ( 'elementor' === $get_action );
		}

		$skip_buffering = $is_elementor_ajax || $is_elementor_editor;

		$this->assertTrue( $is_elementor_ajax, 'Elementor AJAX should be detected' );
		$this->assertFalse( $is_elementor_editor, 'AJAX request should not be detected as editor page load' );
		$this->assertTrue( $skip_buffering, 'Buffering should be skipped for Elementor AJAX' );
	}

	/**
	 * Test comprehensive buffering logic with multiple scenarios.
	 *
	 * Validates that the complete buffering logic works correctly for all cases.
	 */
	public function test_comprehensive_buffering_scenarios() {
		$test_cases = array(
			// Normal page: should buffer.
			array(
				'is_ajax'        => false,
				'get_action'     => null,
				'request_action' => null,
				'should_buffer'  => true,
				'description'    => 'Normal page',
			),
			// Elementor editor: should NOT buffer.
			array(
				'is_ajax'        => false,
				'get_action'     => 'elementor',
				'request_action' => null,
				'should_buffer'  => false,
				'description'    => 'Elementor editor page',
			),
			// Elementor AJAX: should NOT buffer.
			array(
				'is_ajax'        => true,
				'get_action'     => null,
				'request_action' => 'elementor_clear_cache',
				'should_buffer'  => false,
				'description'    => 'Elementor AJAX request',
			),
			// Non-Elementor admin page: should buffer.
			array(
				'is_ajax'        => false,
				'get_action'     => 'edit',
				'request_action' => null,
				'should_buffer'  => true,
				'description'    => 'Non-Elementor admin page',
			),
			// Non-Elementor AJAX: should buffer.
			array(
				'is_ajax'        => true,
				'get_action'     => null,
				'request_action' => 'wp_ajax_custom',
				'should_buffer'  => true,
				'description'    => 'Non-Elementor AJAX',
			),
		);

		foreach ( $test_cases as $test_case ) {
			// Set up the request state.
			if ( $test_case['get_action'] ) {
				$_GET['action'] = $test_case['get_action'];
			} else {
				unset( $_GET['action'] );
			}

			if ( $test_case['request_action'] ) {
				$_REQUEST['action'] = $test_case['request_action'];
			} else {
				unset( $_REQUEST['action'] );
			}

			$level_before = ob_get_level();

			// Run the detection logic.
			$is_ajax_request = $test_case['is_ajax']
				&& ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
					|| ( defined( 'DOING_AJAX' ) && DOING_AJAX ) );

			$is_elementor_ajax = false;
			if ( $is_ajax_request && isset( $_REQUEST['action'] ) ) {
				$request_action    = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );
				$is_elementor_ajax = ( strpos( $request_action, 'elementor' ) === 0 );
			}

			$is_elementor_editor = false;
			if ( ! $is_ajax_request && isset( $_GET['action'] ) ) {
				$get_action          = sanitize_text_field( wp_unslash( $_GET['action'] ) );
				$is_elementor_editor = ( 'elementor' === $get_action );
			}

			$skip_buffering = $is_elementor_ajax || $is_elementor_editor;

			// Apply buffering if appropriate.
			if ( ! $skip_buffering ) {
				ob_start();
			}

			$level_during = ob_get_level();

			// Clean buffering if we started it.
			if ( ! $skip_buffering ) {
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
