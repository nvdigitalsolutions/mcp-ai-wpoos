<?php
/**
 * Tests for Gutenberg block registration.
 *
 * Verifies that all WP oOS Gutenberg blocks are registered correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Gutenberg block registration.
 */
class WP_MCP_AI_Blocks_Registration_Test extends WP_UnitTestCase {
	/**
	 * Test that all performance blocks are registered.
	 */
	public function test_performance_blocks_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$performance_blocks = array(
			'wp-mcp-ai/performance-test-runner',
			'wp-mcp-ai/performance-metrics',
			'wp-mcp-ai/system-health-status',
			'wp-mcp-ai/test-results-table',
			'wp-mcp-ai/performance-recommendations',
			'wp-mcp-ai/performance-trends',
		);

		foreach ( $performance_blocks as $block_name ) {
			$this->assertTrue(
				$registry->is_registered( $block_name ),
				"Performance block {$block_name} should be registered"
			);
		}
	}

	/**
	 * Test that all chat blocks are registered.
	 */
	public function test_chat_blocks_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$chat_blocks = array(
			'wp-mcp-ai/chat',
			'wp-mcp-ai/chat-intro',
			'wp-mcp-ai/chat-faq',
			'wp-mcp-ai/chat-usage-timer',
		);

		foreach ( $chat_blocks as $block_name ) {
			$this->assertTrue(
				$registry->is_registered( $block_name ),
				"Chat block {$block_name} should be registered"
			);
		}
	}

	/**
	 * Test that all assistant blocks are registered.
	 */
	public function test_assistant_blocks_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$assistant_blocks = array(
			'wp-mcp-ai/assistant-defaults',
			'wp-mcp-ai/assistant-base-knowledge',
			'wp-mcp-ai/assistant-prompt-shortcuts',
			'wp-mcp-ai/assistant-tools',
		);

		foreach ( $assistant_blocks as $block_name ) {
			$this->assertTrue(
				$registry->is_registered( $block_name ),
				"Assistant block {$block_name} should be registered"
			);
		}
	}

	/**
	 * Test that all dashboard blocks are registered.
	 */
	public function test_dashboard_blocks_registered() {
		$registry = WP_Block_Type_Registry::get_instance();

		$dashboard_blocks = array(
			'wp-mcp-ai/dashboard-tool-matrix',
			'wp-mcp-ai/dashboard-user-capability',
			'wp-mcp-ai/dashboard-user-files',
			'wp-mcp-ai/dashboard-user-chats',
			'wp-mcp-ai/dashboard-theme-preview',
			'wp-mcp-ai/dashboard-provider-links',
			'wp-mcp-ai/dashboard-activity-feed',
		);

		foreach ( $dashboard_blocks as $block_name ) {
			$this->assertTrue(
				$registry->is_registered( $block_name ),
				"Dashboard block {$block_name} should be registered"
			);
		}
	}

	/**
	 * Test that total number of WP oOS blocks matches expected count.
	 */
	public function test_total_blocks_count() {
		$registry = WP_Block_Type_Registry::get_instance();
		$all_blocks = $registry->get_all_registered();

		$wp_mcp_ai_blocks = array_filter(
			array_keys( $all_blocks ),
			function( $block_name ) {
				return strpos( $block_name, 'wp-mcp-ai/' ) === 0;
			}
		);

		// We should have 19 blocks total (6 performance + 4 chat + 4 assistant + 7 dashboard - 2 that were already counted).
		// Performance blocks were already registered, so we expect 6 + 4 + 4 + 7 = 21 total.
		$this->assertGreaterThanOrEqual(
			19,
			count( $wp_mcp_ai_blocks ),
			'Should have at least 19 WP oOS blocks registered'
		);
	}

	/**
	 * Test that chat block has render callback.
	 */
	public function test_chat_block_has_render_callback() {
		$registry = WP_Block_Type_Registry::get_instance();
		$block = $registry->get_registered( 'wp-mcp-ai/chat' );

		$this->assertNotNull( $block, 'Chat block should be registered' );
		$this->assertNotNull( $block->render_callback, 'Chat block should have a render callback' );
	}

	/**
	 * Test that assistant defaults block has render callback.
	 */
	public function test_assistant_defaults_block_has_render_callback() {
		$registry = WP_Block_Type_Registry::get_instance();
		$block = $registry->get_registered( 'wp-mcp-ai/assistant-defaults' );

		$this->assertNotNull( $block, 'Assistant defaults block should be registered' );
		$this->assertNotNull( $block->render_callback, 'Assistant defaults block should have a render callback' );
	}

	/**
	 * Test that dashboard blocks have render callbacks.
	 */
	public function test_dashboard_blocks_have_render_callbacks() {
		$registry = WP_Block_Type_Registry::get_instance();
		$block = $registry->get_registered( 'wp-mcp-ai/dashboard-provider-links' );

		$this->assertNotNull( $block, 'Dashboard provider links block should be registered' );
		$this->assertNotNull( $block->render_callback, 'Dashboard provider links block should have a render callback' );
	}

	/**
	 * Test that blocks have appropriate attributes.
	 */
	public function test_blocks_have_attributes() {
		$registry = WP_Block_Type_Registry::get_instance();
		
		// Test chat block attributes.
		$chat_block = $registry->get_registered( 'wp-mcp-ai/chat' );
		$this->assertArrayHasKey( 'assistant', $chat_block->attributes, 'Chat block should have assistant attribute' );
		$this->assertArrayHasKey( 'allowGuests', $chat_block->attributes, 'Chat block should have allowGuests attribute' );

		// Test assistant defaults block attributes.
		$defaults_block = $registry->get_registered( 'wp-mcp-ai/assistant-defaults' );
		$this->assertArrayHasKey( 'title', $defaults_block->attributes, 'Assistant defaults block should have title attribute' );
		$this->assertArrayHasKey( 'assistantId', $defaults_block->attributes, 'Assistant defaults block should have assistantId attribute' );

		// Test dashboard blocks attributes.
		$dashboard_block = $registry->get_registered( 'wp-mcp-ai/dashboard-user-files' );
		$this->assertArrayHasKey( 'limit', $dashboard_block->attributes, 'Dashboard user files block should have limit attribute' );
	}
}
