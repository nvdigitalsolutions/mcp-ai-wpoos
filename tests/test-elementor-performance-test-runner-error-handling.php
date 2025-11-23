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
	 * Test that widget JavaScript contains proper error handling for object responses
	 */
	public function test_widget_contains_error_object_handling() {
		// Load Elementor stubs if needed.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			require_once WP_MCP_AI_PATH . 'tests/helpers/elementor-stubs.php';
		}

		// Load required trait.
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';

		// Load the widget class.
		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-performance-test-runner-widget.php';

		// Create widget instance.
		$widget = new WP_MCP_AI_Elementor_Performance_Test_Runner_Widget();

		// Set up admin user to pass permission check.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set widget settings.
		$widget->set_settings( array(
			'title'         => 'Test Runner',
			'description'   => 'Test Description',
			'enabled_tests' => array( 'security' ),
			'show_results'  => 'yes',
		) );

		// Capture the output.
		ob_start();
		$widget->render();
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
		// Load Elementor stubs if needed.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			require_once WP_MCP_AI_PATH . 'tests/helpers/elementor-stubs.php';
		}

		// Load required trait.
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';

		// Load the widget class.
		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-performance-test-runner-widget.php';

		// Create widget instance.
		$widget = new WP_MCP_AI_Elementor_Performance_Test_Runner_Widget();

		// Set up admin user to pass permission check.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set widget settings.
		$widget->set_settings( array(
			'title'         => 'Test Runner',
			'enabled_tests' => array( 'security' ),
			'show_results'  => 'yes',
		) );

		// Capture the output.
		ob_start();
		$widget->render();
		$output = ob_get_clean();

		// Verify the output contains handling for string errors (else case).
		$this->assertStringContainsString(
			'else',
			$output,
			'Widget should have an else branch for non-object errors'
		);

		// The widget should still display string errors.
		$this->assertMatchesRegularExpression(
			'/response\.data\s*\+\s*[\'"]<\/p>/',
			$output,
			'Widget should handle string error responses for backward compatibility'
		);
	}

	/**
	 * Test that widget requires manage_options capability
	 */
	public function test_widget_requires_manage_options_capability() {
		// Load Elementor stubs if needed.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			require_once WP_MCP_AI_PATH . 'tests/helpers/elementor-stubs.php';
		}

		// Load required trait.
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';

		// Load the widget class.
		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-performance-test-runner-widget.php';

		// Create widget instance.
		$widget = new WP_MCP_AI_Elementor_Performance_Test_Runner_Widget();

		// Set up subscriber user (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set widget settings.
		$widget->set_settings( array(
			'title'         => 'Test Runner',
			'enabled_tests' => array( 'security' ),
		) );

		// Capture the output.
		ob_start();
		$widget->render();
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
		// Load Elementor stubs if needed.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			require_once WP_MCP_AI_PATH . 'tests/helpers/elementor-stubs.php';
		}

		// Load required trait.
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';

		// Load the widget class.
		require_once WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-performance-test-runner-widget.php';

		// Create widget instance.
		$widget = new WP_MCP_AI_Elementor_Performance_Test_Runner_Widget();

		// Set up admin user to pass permission check.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set widget settings with all test types.
		$widget->set_settings( array(
			'title'         => 'Test Runner',
			'enabled_tests' => array( 'stress', 'security', 'speed', 'optimization' ),
			'show_results'  => 'yes',
		) );

		// Capture the output.
		ob_start();
		$widget->render();
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
}
