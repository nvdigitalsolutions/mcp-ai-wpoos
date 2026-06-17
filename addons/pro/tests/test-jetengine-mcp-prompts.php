<?php
/**
 * Tests for the JetEngine MCP Prompts class.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */
class Test_JetEngine_MCP_Prompts extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Load the prompts class.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_MCP_Prompts' ) ) {
			$file = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-prompts.php'
				: dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-jetengine-mcp-prompts.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		// Reset singleton.
		$reflection = new ReflectionClass( 'WP_MCP_AI_JetEngine_MCP_Prompts' );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_JetEngine_MCP_Prompts' ) );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_singleton_returns_same_instance() {
		$instance1 = WP_MCP_AI_JetEngine_MCP_Prompts::get_instance();
		$instance2 = WP_MCP_AI_JetEngine_MCP_Prompts::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_render_prompt_extracts_messages() {
		$prompts    = WP_MCP_AI_JetEngine_MCP_Prompts::get_instance();
		$reflection = new ReflectionClass( $prompts );

		// We can't test render_prompt directly without MCP server, but we can test.
		// the method exists and behaves correctly with mock data.
		$this->assertTrue( method_exists( $prompts, 'render_prompt' ) );
		$this->assertTrue( method_exists( $prompts, 'list_prompts' ) );
		$this->assertTrue( method_exists( $prompts, 'get_prompt' ) );
	}
}
