<?php
/**
 * Test AI Quick Actions Widget
 *
 * @package WP_MCP_AI
 */

/**
 * Test AI Quick Actions Widget functionality.
 */
class Test_Quick_Actions_Widget extends WP_UnitTestCase {
	/**
	 * Test that widget class exists.
	 */
	public function test_widget_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Elementor_Quick_Actions_Widget' ) );
	}

	/**
	 * Test that handler class exists.
	 */
	public function test_handler_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Quick_Actions_Handler' ) );
	}

	/**
	 * Test that handler singleton works.
	 */
	public function test_handler_singleton() {
		$handler1 = WP_MCP_AI_Quick_Actions_Handler::get_instance();
		$handler2 = WP_MCP_AI_Quick_Actions_Handler::get_instance();
		
		$this->assertSame( $handler1, $handler2, 'Handler should return the same instance' );
	}

	/**
	 * Test that widget has correct name.
	 */
	public function test_widget_name() {
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor not available' );
		}

		$widget = new WP_MCP_AI_Elementor_Quick_Actions_Widget();
		$this->assertEquals( 'wp_mcp_ai_quick_actions', $widget->get_name() );
	}

	/**
	 * Test that widget has correct title.
	 */
	public function test_widget_title() {
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor not available' );
		}

		$widget = new WP_MCP_AI_Elementor_Quick_Actions_Widget();
		$this->assertStringContainsString( 'Quick Actions', $widget->get_title() );
	}

	/**
	 * Test that widget has correct keywords.
	 */
	public function test_widget_keywords() {
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			$this->markTestSkipped( 'Elementor not available' );
		}

		$widget = new WP_MCP_AI_Elementor_Quick_Actions_Widget();
		$keywords = $widget->get_keywords();
		
		$this->assertIsArray( $keywords );
		$this->assertContains( 'ai', $keywords );
		$this->assertContains( 'quick', $keywords );
		$this->assertContains( 'actions', $keywords );
	}

	/**
	 * Test that AJAX action is registered.
	 */
	public function test_ajax_action_registered() {
		$this->assertTrue( has_action( 'wp_ajax_wp_mcp_ai_execute_quick_action' ) );
		$this->assertTrue( has_action( 'wp_ajax_nopriv_wp_mcp_ai_execute_quick_action' ) );
	}

	/**
	 * Test that assets are enqueued on appropriate pages.
	 */
	public function test_assets_enqueue_action() {
		$this->assertTrue( has_action( 'wp_enqueue_scripts' ) );
	}

	/**
	 * Test AJAX request without nonce fails.
	 */
	public function test_ajax_without_nonce_fails() {
		$_POST['tool'] = 'test_tool';
		
		try {
			$handler = WP_MCP_AI_Quick_Actions_Handler::get_instance();
			$handler->handle_execute_action();
			$this->fail( 'Expected wp_die to be called' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'nonce', $e->getMessage() );
		}
	}

	/**
	 * Test AJAX request without tool fails.
	 */
	public function test_ajax_without_tool_fails() {
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_quick_action' );
		
		$this->expectException( WPAjaxDieContinueException::class );
		
		$handler = WP_MCP_AI_Quick_Actions_Handler::get_instance();
		$handler->handle_execute_action();
	}

	/**
	 * Test file upload validation rejects invalid types.
	 */
	public function test_file_upload_validation() {
		$handler = WP_MCP_AI_Quick_Actions_Handler::get_instance();
		
		// Use reflection to access protected method
		$reflection = new ReflectionClass( $handler );
		$method = $reflection->getMethod( 'handle_file_upload' );
		$method->setAccessible( true );
		
		$file = array(
			'name'     => 'test.exe',
			'type'     => 'application/x-executable',
			'tmp_name' => '/tmp/test',
			'error'    => 0,
			'size'     => 1024,
		);
		
		$result = $method->invoke( $handler, $file );
		
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'invalid_file_type', $result->get_error_code() );
	}

	/**
	 * Test file upload validation rejects oversized files.
	 */
	public function test_file_upload_size_limit() {
		$handler = WP_MCP_AI_Quick_Actions_Handler::get_instance();
		
		// Use reflection to access protected method
		$reflection = new ReflectionClass( $handler );
		$method = $reflection->getMethod( 'handle_file_upload' );
		$method->setAccessible( true );
		
		$file = array(
			'name'     => 'test.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => '/tmp/test',
			'error'    => 0,
			'size'     => 99999999, // Way over 10MB limit
		);
		
		$result = $method->invoke( $handler, $file );
		
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'file_too_large', $result->get_error_code() );
	}

	/**
	 * Test is_image_tool method.
	 */
	public function test_is_image_tool() {
		$handler = WP_MCP_AI_Quick_Actions_Handler::get_instance();
		
		// Use reflection to access protected method
		$reflection = new ReflectionClass( $handler );
		$method = $reflection->getMethod( 'is_image_tool' );
		$method->setAccessible( true );
		
		$this->assertTrue( $method->invoke( $handler, 'generate_image' ) );
		$this->assertTrue( $method->invoke( $handler, 'analyze_image' ) );
		$this->assertFalse( $method->invoke( $handler, 'create_post' ) );
		$this->assertFalse( $method->invoke( $handler, 'transcribe_audio' ) );
	}

	/**
	 * Test that widget files are loaded correctly.
	 */
	public function test_widget_files_loaded() {
		$widget_file = WP_MCP_AI_PATH . 'includes/elementor/class-wp-mcp-ai-elementor-quick-actions-widget.php';
		$handler_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-quick-actions-handler.php';
		$css_file = WP_MCP_AI_PATH . 'assets/css/elementor-quick-actions-widget.css';
		$js_file = WP_MCP_AI_PATH . 'assets/js/elementor-quick-actions-widget.js';
		
		$this->assertFileExists( $widget_file );
		$this->assertFileExists( $handler_file );
		$this->assertFileExists( $css_file );
		$this->assertFileExists( $js_file );
	}

	/**
	 * Test that documentation files exist.
	 */
	public function test_documentation_exists() {
		$proposal_file = WP_MCP_AI_PATH . 'docs/ai-quick-actions-widget-proposal.md';
		$usage_file = WP_MCP_AI_PATH . 'docs/ai-quick-actions-widget-usage.md';
		
		$this->assertFileExists( $proposal_file );
		$this->assertFileExists( $usage_file );
	}
}
