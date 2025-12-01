<?php
/**
 * Tests for Elementor Performance Trends widget chart rendering.
 *
 * Verifies that the Performance Trends widget generates unique canvas IDs
 * to prevent Chart.js "canvas already in use" errors when multiple widgets
 * are present or when Elementor re-renders widgets in the editor.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Elementor Performance Trends widget chart functionality.
 */
class WP_MCP_AI_Elementor_Performance_Trends_Chart_Test extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Set up admin user for permission checks.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Load Elementor widget class if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Elementor_Performance_Trends_Widget' ) ) {
			$plugin_dir = dirname( __DIR__ );
			require_once $plugin_dir . '/includes/elementor/class-wp-mcp-ai-elementor-performance-trends-widget.php';
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		parent::tear_down();
	}

	/**
	 * Test that widget generates unique canvas IDs for different instances.
	 */
	public function test_unique_canvas_ids_for_multiple_instances() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		// Create mock widget instances with different IDs.
		$widget1 = $this->get_mock_widget( 'widget-1' );
		$widget2 = $this->get_mock_widget( 'widget-2' );

		// Capture output from both widgets.
		ob_start();
		$this->render_widget_output( $widget1 );
		$output1 = ob_get_clean();

		ob_start();
		$this->render_widget_output( $widget2 );
		$output2 = ob_get_clean();

		// Verify both outputs contain canvas elements.
		$this->assertStringContainsString(
			'<canvas id=',
			$output1,
			'Widget 1 should contain a canvas element'
		);
		$this->assertStringContainsString(
			'<canvas id=',
			$output2,
			'Widget 2 should contain a canvas element'
		);

		// Verify canvas IDs are different.
		preg_match( '/canvas id="([^"]+)"/', $output1, $matches1 );
		preg_match( '/canvas id="([^"]+)"/', $output2, $matches2 );

		$this->assertNotEmpty( $matches1, 'Widget 1 should have a canvas ID' );
		$this->assertNotEmpty( $matches2, 'Widget 2 should have a canvas ID' );

		$canvas_id_1 = $matches1[1];
		$canvas_id_2 = $matches2[1];

		$this->assertNotEquals(
			$canvas_id_1,
			$canvas_id_2,
			'Canvas IDs should be unique for different widget instances'
		);

		// Verify IDs follow the expected pattern.
		$this->assertStringContainsString(
			'wp-mcp-ai-trends-chart-',
			$canvas_id_1,
			'Canvas ID should start with wp-mcp-ai-trends-chart-'
		);
		$this->assertStringContainsString(
			'wp-mcp-ai-trends-chart-',
			$canvas_id_2,
			'Canvas ID should start with wp-mcp-ai-trends-chart-'
		);
	}

	/**
	 * Test that widget includes chart cleanup code.
	 */
	public function test_chart_cleanup_code_present() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		$widget = $this->get_mock_widget( 'test-widget' );

		ob_start();
		$this->render_widget_output( $widget );
		$output = ob_get_clean();

		// Verify cleanup code is present.
		$this->assertStringContainsString(
			'window.wpMcpAiCharts',
			$output,
			'Output should contain global chart registry'
		);

		$this->assertStringContainsString(
			'.destroy()',
			$output,
			'Output should contain chart destroy method call'
		);

		$this->assertStringContainsString(
			'delete window.wpMcpAiCharts',
			$output,
			'Output should contain code to delete chart from registry'
		);
	}

	/**
	 * Test that canvas ID is properly escaped in JavaScript.
	 */
	public function test_canvas_id_properly_escaped_in_js() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		$widget = $this->get_mock_widget( 'test-widget' );

		ob_start();
		$this->render_widget_output( $widget );
		$output = ob_get_clean();

		// Verify that canvas ID is referenced in JavaScript.
		$this->assertStringContainsString(
			"getElementById('wp-mcp-ai-trends-chart-",
			$output,
			'Canvas ID should be referenced in getElementById call'
		);

		// Verify proper escaping (no unescaped quotes).
		preg_match( "/getElementById\('([^']+)'\)/", $output, $matches );
		$this->assertNotEmpty( $matches, 'Should find getElementById with canvas ID' );

		if ( ! empty( $matches[1] ) ) {
			$canvas_id = $matches[1];
			// Verify no dangerous characters in ID.
			$this->assertDoesNotMatchRegularExpression(
				'/["\']/',
				$canvas_id,
				'Canvas ID should not contain quotes'
			);
		}
	}

	/**
	 * Test that widget requires manage_options capability.
	 */
	public function test_widget_requires_manage_options_capability() {
		// Skip if Elementor is not available.
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor is not available' );
			return;
		}

		// Set current user to subscriber (no manage_options).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$widget = $this->get_mock_widget( 'test-widget' );

		ob_start();
		$this->render_widget_output( $widget );
		$output = ob_get_clean();

		// Should show permission error, not the chart.
		$this->assertStringContainsString(
			'You do not have permission',
			$output,
			'Should show permission error for users without manage_options'
		);

		$this->assertStringNotContainsString(
			'<canvas',
			$output,
			'Should not render canvas for users without permission'
		);
	}

	/**
	 * Helper method to create a mock widget with a specific ID.
	 *
	 * @param string $widget_id The widget ID to use.
	 * @return object Mock widget instance.
	 */
	private function get_mock_widget( $widget_id ) {
		// Create a mock widget that extends the actual widget class.
		$widget = $this->getMockBuilder( WP_MCP_AI_Elementor_Performance_Trends_Widget::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_id', 'get_settings_for_display' ) )
			->getMock();

		$widget->method( 'get_id' )->willReturn( $widget_id );
		$widget->method( 'get_settings_for_display' )->willReturn(
			array(
				'title'        => 'Test Performance Trends',
				'component'    => 'rest_api',
				'time_period'  => '-7 days',
				'chart_height' => 300,
			)
		);

		return $widget;
	}

	/**
	 * Helper method to render widget output.
	 *
	 * @param object $widget Mock widget instance.
	 */
	private function render_widget_output( $widget ) {
		// Use reflection to call the protected render method.
		$reflection = new ReflectionClass( $widget );
		$method     = $reflection->getMethod( 'render' );
		$method->setAccessible( true );
		$method->invoke( $widget );
	}
}
