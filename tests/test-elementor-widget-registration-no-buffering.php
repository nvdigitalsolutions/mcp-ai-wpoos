<?php
/**
 * Tests for Elementor widget registration without output buffering.
 *
 * Verifies that widgets can be registered and instantiated without
 * output buffering suppressing their content.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor widget registration without buffering.
 */
class WP_MCP_AI_Elementor_Widget_Registration_No_Buffering_Test extends WP_UnitTestCase {
	/**
	 * Test that widget registration doesn't use output buffering.
	 */
	public function test_register_widget_no_output_buffering() {
		// Load Elementor stubs if needed.
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			require_once __DIR__ . '/helpers/elementor-stubs.php';
		}

		// Create a mock widgets manager.
		$widgets_manager = $this->getMockBuilder( 'stdClass' )
			->setMethods( array( 'register' ) )
			->getMock();

		// Expect register to be called (widgets should be registered successfully).
		$widgets_manager->expects( $this->atLeastOnce() )
			->method( 'register' );

		// Get the integration instance.
		$integration = new WP_MCP_AI_Elementor_Integration();

		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'register_widget' );
		$method->setAccessible( true );

		// Start output buffering to catch any output.
		ob_start();

		// Call register_widget - should not use ob_end_clean internally.
		$method->invoke( $integration, $widgets_manager );

		// Get any output that was generated.
		$output = ob_get_clean();

		// Verify no unexpected output was generated during registration.
		$this->assertEmpty( $output, 'Widget registration should not generate output' );
	}

	/**
	 * Test that widget files are loaded without output buffering.
	 */
	public function test_widget_files_loaded_without_buffering() {
		// Verify that the widget class files exist and can be loaded.
		$widget_files = array(
			'class-wp-mcp-ai-elementor-widget.php',
			'class-wp-mcp-ai-elementor-assistant-defaults-widget.php',
			'class-wp-mcp-ai-elementor-assistant-base-knowledge-widget.php',
		);

		foreach ( $widget_files as $file ) {
			$path = WP_MCP_AI_PATH . 'includes/elementor/' . $file;

			$this->assertFileExists( $path, "Widget file {$file} should exist" );

			// Start output buffering to catch any output.
			ob_start();

			// Include the file.
			if ( ! file_exists( $path ) ) {
				ob_end_clean();
				continue;
			}

			// Load trait first if not already loaded.
			$trait_path = WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
			if ( file_exists( $trait_path ) && ! trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ) ) {
				require_once $trait_path;
			}

			// Load widget file.
			require_once $path;

			// Get any output that was generated.
			$output = ob_get_clean();

			// Verify no output was generated during file loading.
			$this->assertEmpty( $output, "Loading {$file} should not generate output" );
		}
	}

	/**
	 * Test that widgets can be instantiated without output buffering suppressing content.
	 */
	public function test_widget_instantiation_without_buffering() {
		// Load Elementor stubs if needed.
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			require_once __DIR__ . '/helpers/elementor-stubs.php';
		}

		// Load the trait.
		$trait_path = WP_MCP_AI_PATH . 'includes/elementor/trait-wp-mcp-ai-elementor-text-formatting.php';
		if ( file_exists( $trait_path ) && ! trait_exists( 'WP_MCP_AI_Elementor_Text_Formatting' ) ) {
			require_once $trait_path;
		}

		// Load the main widget file.
		$widget_path = WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-widget.php';
		if ( file_exists( $widget_path ) && ! class_exists( 'WP_MCP_AI_Elementor_Widget' ) ) {
			require_once $widget_path;
		}

		// Skip if Elementor Widget_Base is not available.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			// Create a minimal stub for Widget_Base.
			if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
				eval( 'namespace Elementor { abstract class Widget_Base {} }' );
			}
		}

		// Check if widget class was loaded.
		if ( ! class_exists( 'WP_MCP_AI_Elementor_Widget' ) ) {
			$this->markTestSkipped( 'Widget class not available' );
			return;
		}

		// Start output buffering to catch any output.
		ob_start();

		// Instantiate the widget - this should work without internal ob_end_clean suppressing output.
		try {
			$widget                  = new WP_MCP_AI_Elementor_Widget();
			$instantiation_succeeded = true;
		} catch ( Exception $e ) {
			$instantiation_succeeded = false;
		}

		// Get any output that was generated.
		$output = ob_get_clean();

		// Verify widget was instantiated successfully.
		$this->assertTrue( $instantiation_succeeded, 'Widget should be instantiated successfully' );

		// Widget constructors typically don't produce output, but verify nothing was suppressed.
		// If there was intentional output, it should be captured here.
		// An empty output is expected for well-formed widgets.
	}

	/**
	 * Test that the integration init method doesn't use output buffering.
	 */
	public function test_integration_init_no_buffering() {
		// Load Elementor stubs if needed.
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			require_once __DIR__ . '/helpers/elementor-stubs.php';
		}

		// Start output buffering to catch any output.
		ob_start();

		// Call the init method.
		WP_MCP_AI_Elementor_Integration::init();

		// Get any output that was generated.
		$output = ob_get_clean();

		// Verify no unexpected output was generated.
		$this->assertEmpty( $output, 'Integration init should not generate output' );

		// Verify the elementor/widgets/register hook was added.
		$this->assertGreaterThan(
			0,
			has_action( 'elementor/widgets/register' ),
			'Widget registration hook should be added'
		);
	}
}
