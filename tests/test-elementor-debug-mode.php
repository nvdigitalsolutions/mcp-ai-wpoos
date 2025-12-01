<?php
/**
 * Tests for Elementor editor functionality with WP_DEBUG enabled.
 *
 * Verifies that the Elementor editor loads correctly when WP_DEBUG is enabled
 * by suppressing display_errors to prevent debug output from breaking JSON responses.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor editor with WP_DEBUG enabled.
 */
class WP_MCP_AI_Elementor_Debug_Mode_Test extends WP_UnitTestCase {
	/**
	 * Backup of original display_errors setting.
	 *
	 * @var string|false
	 */
	private $original_display_errors;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Backup original display_errors setting.
		$this->original_display_errors = ini_get( 'display_errors' );

		// Ensure DOING_AJAX is defined for tests that need it.
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Restore original display_errors setting.
		if ( false !== $this->original_display_errors ) {
			@ini_set( 'display_errors', $this->original_display_errors );
		}

		parent::tear_down();
	}

	/**
	 * Test that suppress_debug_in_elementor_ajax method exists.
	 */
	public function test_suppress_debug_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI', 'suppress_debug_in_elementor_ajax' ),
			'suppress_debug_in_elementor_ajax method should exist'
		);
	}

	/**
	 * Test that disable_auth_check_in_elementor method exists.
	 */
	public function test_disable_auth_check_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI', 'disable_auth_check_in_elementor' ),
			'disable_auth_check_in_elementor method should exist'
		);
	}

	/**
	 * Test that display_errors is suppressed during Elementor AJAX requests when WP_DEBUG is enabled.
	 */
	public function test_display_errors_suppressed_for_elementor_ajax() {
		// Skip if WP_DEBUG is not defined or false.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG must be enabled for this test' );
		}

		// Set display_errors to '1' to simulate debug mode.
		@ini_set( 'display_errors', '1' );
		$this->assertEquals( '1', ini_get( 'display_errors' ), 'display_errors should start as 1' );

		// Simulate an Elementor AJAX request.
		$_REQUEST['action'] = 'elementor_ajax';

		// Create plugin instance and call the method.
		$plugin = wp_mcp_ai();

		// Call the suppress method.
		$plugin->suppress_debug_in_elementor_ajax();

		// Verify display_errors was suppressed.
		$this->assertEquals( '0', ini_get( 'display_errors' ), 'display_errors should be suppressed for Elementor AJAX' );

		// Clean up.
		unset( $_REQUEST['action'] );
	}

	/**
	 * Test that display_errors is NOT suppressed for non-Elementor AJAX requests.
	 */
	public function test_display_errors_not_suppressed_for_other_ajax() {
		// Skip if WP_DEBUG is not defined or false.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG must be enabled for this test' );
		}

		// Set display_errors to '1' to simulate debug mode.
		@ini_set( 'display_errors', '1' );

		// Simulate a non-Elementor AJAX request.
		$_REQUEST['action'] = 'my_custom_action';

		// Create plugin instance and call the method.
		$plugin = wp_mcp_ai();
		$plugin->suppress_debug_in_elementor_ajax();

		// Verify display_errors was NOT suppressed.
		$this->assertEquals( '1', ini_get( 'display_errors' ), 'display_errors should not be suppressed for non-Elementor AJAX' );

		// Clean up.
		unset( $_REQUEST['action'] );
	}

	/**
	 * Test that display_errors is suppressed in Elementor editor page when WP_DEBUG is enabled.
	 */
	public function test_display_errors_suppressed_in_elementor_editor() {
		// Skip if WP_DEBUG is not defined or false.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG must be enabled for this test' );
		}

		// Set display_errors to '1' to simulate debug mode.
		@ini_set( 'display_errors', '1' );

		// Create a user with edit_posts capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Simulate Elementor editor mode.
		$_GET['action'] = 'elementor';

		// Create plugin instance and call the method.
		$plugin = wp_mcp_ai();
		$plugin->disable_auth_check_in_elementor();

		// Verify display_errors was suppressed.
		$this->assertEquals( '0', ini_get( 'display_errors' ), 'display_errors should be suppressed in Elementor editor' );

		// Clean up.
		unset( $_GET['action'] );
	}

	/**
	 * Test that display_errors is NOT suppressed on regular admin pages.
	 */
	public function test_display_errors_not_suppressed_on_regular_pages() {
		// Skip if WP_DEBUG is not defined or false.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG must be enabled for this test' );
		}

		// Set display_errors to '1' to simulate debug mode.
		@ini_set( 'display_errors', '1' );

		// Create a user with edit_posts capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Simulate a regular admin page (no Elementor action).
		$_GET['page'] = 'some-admin-page';

		// Create plugin instance and call the method.
		$plugin = wp_mcp_ai();
		$plugin->disable_auth_check_in_elementor();

		// Verify display_errors was NOT suppressed.
		$this->assertEquals( '1', ini_get( 'display_errors' ), 'display_errors should not be suppressed on regular pages' );

		// Clean up.
		unset( $_GET['page'] );
	}

	/**
	 * Test that the fix handles various Elementor action patterns.
	 */
	public function test_elementor_action_patterns_detected() {
		// Skip if WP_DEBUG is not defined or false.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'WP_DEBUG must be enabled for this test' );
		}

		$elementor_actions = array(
			'elementor_ajax',
			'elementor_render_widget',
			'elementor_get_templates',
		);

		foreach ( $elementor_actions as $action ) {
			// Reset display_errors.
			@ini_set( 'display_errors', '1' );

			// Simulate the AJAX request.
			$_REQUEST['action'] = $action;

			// Call the suppress method.
			$plugin = wp_mcp_ai();
			$plugin->suppress_debug_in_elementor_ajax();

			// Verify display_errors was suppressed.
			$this->assertEquals(
				'0',
				ini_get( 'display_errors' ),
				"display_errors should be suppressed for action: {$action}"
			);

			// Clean up.
			unset( $_REQUEST['action'] );

			// Clean up any output buffers that were started.
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Test that output buffering is started for Elementor AJAX requests.
	 */
	public function test_output_buffering_started_for_elementor_ajax() {
		// Record the initial output buffer level.
		$initial_level = ob_get_level();

		// Simulate an Elementor AJAX request.
		$_REQUEST['action'] = 'elementor_ajax';

		// Create plugin instance and call the method.
		$plugin = wp_mcp_ai();
		$plugin->suppress_debug_in_elementor_ajax();

		// Verify a new output buffer was started.
		$new_level = ob_get_level();
		$this->assertGreaterThan( $initial_level, $new_level, 'Output buffer should be started for Elementor AJAX' );

		// Clean up.
		unset( $_REQUEST['action'] );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Test that output buffering captures stray output during Elementor AJAX save.
	 */
	public function test_output_buffering_captures_stray_output() {
		// Simulate an Elementor AJAX request.
		$_REQUEST['action'] = 'elementor_save_builder';

		// Create plugin instance and call the method.
		$plugin = wp_mcp_ai();
		$plugin->suppress_debug_in_elementor_ajax();

		// Simulate some stray output that would normally break JSON responses.
		echo 'This output should be captured';

		// Get the buffered content.
		$buffered = ob_get_contents();

		// Verify the output was captured.
		$this->assertStringContainsString( 'This output should be captured', $buffered, 'Stray output should be captured in buffer' );

		// Clean up.
		unset( $_REQUEST['action'] );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Test that clean_elementor_output_buffer method exists.
	 */
	public function test_clean_output_buffer_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI', 'clean_elementor_output_buffer' ),
			'clean_elementor_output_buffer method should exist'
		);
	}

	/**
	 * Test that clean_elementor_output_buffer safely cleans the buffer.
	 */
	public function test_clean_output_buffer_works() {
		// Record initial buffer level.
		$initial_level = ob_get_level();

		// Simulate an Elementor AJAX request.
		$_REQUEST['action'] = 'elementor_save_builder';

		// Create plugin instance and simulate the full flow.
		$plugin = wp_mcp_ai();

		// Call suppress_debug_in_elementor_ajax to set up the buffer tracking.
		$plugin->suppress_debug_in_elementor_ajax();

		// Now a buffer should be started.
		$this->assertGreaterThan( $initial_level, ob_get_level(), 'Buffer should be started' );

		// Add some content to the buffer.
		echo 'Test content';

		// Call the cleanup method.
		$plugin->clean_elementor_output_buffer();

		// Verify the buffer was cleaned and we're back to the initial level.
		$this->assertEquals( $initial_level, ob_get_level(), 'Output buffer should be cleaned back to initial level' );

		// Clean up.
		unset( $_REQUEST['action'] );

		// Make sure we're back to a clean state.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}
}
