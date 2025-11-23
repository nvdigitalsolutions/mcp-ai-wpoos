<?php
/**
 * Test Elementor Performance Test Runner Widget Error Handling
 *
 * Verifies that the Performance Test Runner widget properly handles
 * error responses from the AJAX endpoint, especially when the error
 * data is an object with message, details, cli_command, and setup_command.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Performance Test Runner Widget error handling
 */
class Test_Elementor_Performance_Test_Runner_Error_Handling extends WP_UnitTestCase {

	/**
	 * Widget instance for tests
	 *
	 * @var WP_MCP_AI_Elementor_Performance_Test_Runner_Widget
	 */
	private $widget;

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Load Elementor stubs if needed.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			require_once WP_MCP_AI_PATH . 'tests/helpers/elementor-stubs.php';
		}

		// Load required trait.
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';

		// Load the widget class.
		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-performance-test-runner-widget.php';

		// Create widget instance.
		$this->widget = new WP_MCP_AI_Elementor_Performance_Test_Runner_Widget();

		// Set up admin user to pass permission check.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Test that widget JavaScript contains proper error handling for object responses
	 */
	public function test_widget_contains_error_object_handling() {
		// Set widget settings.
		$this->widget->set_settings( array(
			'title'         => 'Test Runner',
			'description'   => 'Test Description',
			'enabled_tests' => array( 'security' ),
			'show_results'  => 'yes',
		) );

		// Capture the output.
		ob_start();
		$this->widget->render();
		$output = ob_get_clean();

		// Verify the output contains error handling for object responses.
		$this->assertStringContainsString(
			'typeof response.data === \'object\'',
			$output,
			'Widget should check if response.data is an object'
		);

		// Verify message extraction from object.
		$this->assertStringContainsString(
			'response.data.message',
			$output,
			'Widget should extract message property from error object'
		);

		// Verify the escapeHtml function exists for XSS prevention.
		$this->assertStringContainsString(
			'function escapeHtml',
			$output,
			'Widget should have escapeHtml function for XSS prevention'
		);

		// Verify that escapeHtml is used for error messages.
		$this->assertStringContainsString(
			'escapeHtml(errorMessage)',
			$output,
			'Widget should escape error messages to prevent XSS'
		);

		// Verify handling of details field.
		$this->assertStringContainsString(
			'response.data.details',
			$output,
			'Widget should handle details field from error object'
		);

		// Verify handling of cli_command field.
		$this->assertStringContainsString(
			'response.data.cli_command',
			$output,
			'Widget should handle cli_command field from error object'
		);

		// Verify handling of setup_command field.
		$this->assertStringContainsString(
			'response.data.setup_command',
			$output,
			'Widget should handle setup_command field from error object'
		);

		// Verify CSS classes for error details are present.
		$this->assertStringContainsString(
			'wp-mcp-ai-error-details',
			$output,
			'Widget should have CSS for error details'
		);

		$this->assertStringContainsString(
			'wp-mcp-ai-cli-command',
			$output,
			'Widget should have CSS for CLI command display'
		);

		$this->assertStringContainsString(
			'wp-mcp-ai-setup-command',
			$output,
			'Widget should have CSS for setup command display'
		);
	}

	/**
	 * Test that widget handles string error responses (backward compatibility)
	 */
	public function test_widget_handles_string_errors() {
		// Set widget settings.
		$this->widget->set_settings( array(
			'title'         => 'Test Runner',
			'enabled_tests' => array( 'security' ),
			'show_results'  => 'yes',
		) );

		// Capture the output.
		ob_start();
		$this->widget->render();
		$output = ob_get_clean();

		// Verify the output contains handling for string errors (else case).
		$this->assertStringContainsString(
			'else',
			$output,
			'Widget should have an else branch for non-object errors'
		);

		// The widget should still display string errors.
		$this->assertStringContainsString(
			'response.data',
			$output,
			'Widget should handle string error responses for backward compatibility'
		);
	}

	/**
	 * Test that widget requires manage_options capability
	 */
	public function test_widget_requires_manage_options_capability() {
		// Set up subscriber user (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set widget settings.
		$this->widget->set_settings( array(
			'title'         => 'Test Runner',
			'enabled_tests' => array( 'security' ),
		) );

		// Capture the output.
		ob_start();
		$this->widget->render();
		$output = ob_get_clean();

		// Verify the output shows permission denied message.
		$this->assertStringContainsString(
			'You do not have permission to run performance tests',
			$output,
			'Widget should deny access for users without manage_options capability'
		);

		// Verify test buttons are not present.
		$this->assertStringNotContainsString(
			'wp-mcp-ai-test-runner__button',
			$output,
			'Widget should not show test buttons to unauthorized users'
		);
	}

	/**
	 * Test that widget renders all enabled test types
	 */
	public function test_widget_renders_enabled_test_types() {
		// Set widget settings with all test types.
		$this->widget->set_settings( array(
			'title'         => 'Test Runner',
			'enabled_tests' => array( 'stress', 'security', 'speed', 'optimization' ),
			'show_results'  => 'yes',
		) );

		// Capture the output.
		ob_start();
		$this->widget->render();
		$output = ob_get_clean();

		// Verify all test types are rendered.
		$this->assertStringContainsString(
			'data-test-type="stress"',
			$output,
			'Widget should render stress test button'
		);

		$this->assertStringContainsString(
			'data-test-type="security"',
			$output,
			'Widget should render security test button'
		);

		$this->assertStringContainsString(
			'data-test-type="speed"',
			$output,
			'Widget should render speed test button'
		);

		$this->assertStringContainsString(
			'data-test-type="optimization"',
			$output,
			'Widget should render optimization test button'
		);

		// Verify results div is present when show_results is enabled.
		$this->assertStringContainsString(
			'wp-mcp-ai-test-runner__results',
			$output,
			'Widget should have results container when show_results is enabled'
		);
	}

	/**
	 * Test that widget handles output field in error responses
	 */
	public function test_widget_handles_output_field_in_errors() {
		// Set widget settings.
		$this->widget->set_settings( array(
			'title'         => 'Test Runner',
			'enabled_tests' => array( 'security' ),
			'show_results'  => 'yes',
		) );

		// Capture the output.
		ob_start();
		$this->widget->render();
		$output = ob_get_clean();

		// Verify the output field is checked in error responses.
		$this->assertStringContainsString(
			'response.data.output',
			$output,
			'Widget should check for output field in error responses'
		);

		// Verify the output is rendered in a details/summary element.
		$this->assertStringContainsString(
			'<details class="wp-mcp-ai-test-output">',
			$output,
			'Widget should use details element for test output'
		);

		// Verify there's a summary with user-friendly text.
		$this->assertStringContainsString(
			'<summary>',
			$output,
			'Widget should have summary element for test output'
		);

		// Verify the output is wrapped in a pre tag.
		$this->assertStringContainsString(
			'<pre>',
			$output,
			'Widget should wrap test output in pre tag for formatting'
		);

		// Verify output is escaped for security.
		$this->assertStringContainsString(
			'escapeHtml(response.data.output)',
			$output,
			'Widget should escape test output to prevent XSS'
		);

		// Verify CSS styling for test output.
		$this->assertStringContainsString(
			'.wp-mcp-ai-test-output',
			$output,
			'Widget should have CSS styling for test output'
		);

		// Verify test output has styling for pre tag.
		$this->assertStringContainsString(
			'.wp-mcp-ai-test-output pre',
			$output,
			'Widget should have CSS styling for test output pre tag'
		);
	}

	/**
	 * Test that output field handling works for all 4 test types
	 */
	public function test_output_field_works_for_all_test_types() {
		// Set widget settings with all 4 test types enabled.
		$this->widget->set_settings( array(
			'title'         => 'Test Runner',
			'enabled_tests' => array( 'stress', 'security', 'speed', 'optimization' ),
			'show_results'  => 'yes',
		) );

		// Capture the output.
		ob_start();
		$this->widget->render();
		$output = ob_get_clean();

		// Verify all 4 test type buttons are rendered.
		$test_types = array( 'stress', 'security', 'speed', 'optimization' );
		foreach ( $test_types as $test_type ) {
			$this->assertStringContainsString(
				'data-test-type="' . $test_type . '"',
				$output,
				sprintf( 'Widget should render %s test button', $test_type )
			);
		}

		// Verify that the AJAX handler is shared across all test types.
		// The JavaScript uses a generic event handler that reads data-test-type,
		// so the output field handling applies to ALL test types.
		$this->assertStringContainsString(
			'var testType = button.data(\'test-type\');',
			$output,
			'Widget should use generic test type handler for all tests'
		);

		// Verify the error handler (which includes output field handling) is in the shared success callback.
		$this->assertStringContainsString(
			'response.data.output',
			$output,
			'Widget should check output field in shared error handler for all test types'
		);

		// Count how many times the AJAX handler is defined - should be exactly 1 (shared handler).
		$ajax_handler_count = substr_count( $output, 'action: \'wp_mcp_ai_run_performance_test\'' );
		$this->assertEquals(
			1,
			$ajax_handler_count,
			'Widget should have exactly one AJAX handler shared by all test types'
		);

		// Verify the output handling is inside the AJAX success callback (not duplicated per test type).
		$output_handling_count = substr_count( $output, 'response.data.output' );
		$this->assertEquals(
			1,
			$output_handling_count,
			'Widget should have output handling in one shared location for all test types'
		);
	}
}
