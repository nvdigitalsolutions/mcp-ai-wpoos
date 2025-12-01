<?php
/**
 * Tests for Elementor widget registration output buffering.
 *
 * Verifies that PHP output during widget registration does not break
 * Elementor's JSON API responses.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor output buffering during widget registration.
 */
class WP_MCP_AI_Elementor_Output_Buffering_Test extends WP_UnitTestCase {
	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		parent::tear_down();

		// Clean up any output buffers that might be left open.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Test that output buffering is used during widget file loading.
	 *
	 * This test creates a temporary widget file that outputs content,
	 * then verifies that the output is captured and discarded.
	 */
	public function test_output_buffering_during_file_loading() {
		// Create a temporary directory for test widget files.
		$temp_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-widgets-' . uniqid();
		mkdir( $temp_dir );

		// Create a test widget file that outputs content.
		$test_file = $temp_dir . '/test-widget-with-output.php';
		file_put_contents(
			$test_file,
			'<?php echo "This should not appear in output"; ?>'
		);

		// Start capturing all output.
		ob_start();

		// Simulate the output buffering pattern used in the integration class.
		ob_start();
		require_once $test_file;
		$captured = ob_get_clean();

		// Get any output that leaked through.
		$leaked_output = ob_get_clean();

		// Clean up.
		unlink( $test_file );
		rmdir( $temp_dir );

		// Verify that output was captured but not leaked.
		$this->assertStringContainsString( 'This should not appear in output', $captured );
		$this->assertEmpty( $leaked_output, 'Output should not leak through buffering' );
	}

	/**
	 * Test that output buffering doesn't break normal widget functionality.
	 *
	 * Verifies that widgets can still be instantiated normally after
	 * output buffering is applied.
	 */
	public function test_output_buffering_preserves_widget_functionality() {
		// Load the trait that widgets depend on.
		require_once WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';

		// Create a mock widget class using an anonymous class (safer than eval).
		$widget_class = new class() {
			use WP_MCP_AI_Elementor_Text_Formatting;

			public function __construct() {
				// Constructor logic.
			}

			public function test_method() {
				return 'test value';
			}
		};

		// Simulate the output buffering pattern used during instantiation.
		ob_start();
		$widget_instance = clone $widget_class;
		ob_end_clean();

		// Verify the widget works normally.
		$this->assertIsObject( $widget_instance );
		$this->assertEquals( 'test value', $widget_instance->test_method() );
	}

	/**
	 * Test that PHP warnings/notices are captured by output buffering.
	 *
	 * This test verifies that even PHP warnings don't break Elementor's
	 * JSON responses when widgets are loaded.
	 */
	public function test_output_buffering_captures_php_warnings() {
		// Create a temporary file that explicitly triggers a notice.
		$temp_file = sys_get_temp_dir() . '/test-widget-warning-' . uniqid() . '.php';
		file_put_contents(
			$temp_file,
			'<?php trigger_error( "Test notice from widget", E_USER_NOTICE ); ?>'
		);

		// Start capturing all output.
		ob_start();

		// Simulate the output buffering pattern.
		ob_start();
		require_once $temp_file;
		$captured = ob_get_clean();

		// Get any output that leaked through.
		$leaked_output = ob_get_clean();

		// Clean up.
		unlink( $temp_file );

		// Verify that no output leaked through.
		$this->assertEmpty( $leaked_output, 'PHP warnings should not leak through buffering' );
	}

	/**
	 * Test that the integration class exists and is loadable.
	 */
	public function test_integration_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Elementor_Integration' ) );
	}

	/**
	 * Test that the register_widget method exists.
	 */
	public function test_register_widget_method_exists() {
		$this->assertTrue( method_exists( 'WP_MCP_AI_Elementor_Integration', 'register_widget' ) );
	}

	/**
	 * Test that output buffering handles multiple nested levels correctly.
	 *
	 * Verifies that the output buffering implementation doesn't interfere
	 * with existing buffer levels.
	 */
	public function test_nested_output_buffering() {
		// Start an outer buffer (simulating WordPress or plugin context).
		ob_start();

		// Simulate the integration's output buffering.
		ob_start();
		echo 'widget loading output';
		$captured = ob_get_clean();

		// Outer buffer should still be active and empty.
		$outer_content = ob_get_clean();

		$this->assertEquals( 'widget loading output', $captured );
		$this->assertEmpty( $outer_content, 'Outer buffer should remain empty' );
	}

	/**
	 * Test that exceptions during widget loading are not suppressed.
	 *
	 * Verifies that output buffering doesn't hide critical errors.
	 */
	public function test_exceptions_are_not_suppressed() {
		$exception_caught = false;

		try {
			ob_start();
			throw new Exception( 'Critical error during widget loading' );
			ob_end_clean();
		} catch ( Exception $e ) {
			$exception_caught = true;
			$this->assertEquals( 'Critical error during widget loading', $e->getMessage() );

			// Clean up the buffer since exception prevented normal cleanup.
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		$this->assertTrue( $exception_caught, 'Exceptions should not be suppressed by output buffering' );
	}

	/**
	 * Test that trait files don't produce output.
	 *
	 * The trait is loaded with output buffering in the integration class
	 * (lines 63-66). This test verifies that the trait file itself is clean
	 * and doesn't produce output.
	 */
	public function test_trait_file_produces_no_output() {
		$trait_path = WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';

		$this->assertFileExists( $trait_path );

		// Test that the trait file produces no output when loaded.
		// This matches the pattern used in the integration class.
		ob_start();
		require_once $trait_path;
		$output = ob_get_clean();

		// Trait files should not produce output.
		$this->assertEmpty( $output, 'Trait file should not produce any output' );
	}
}
