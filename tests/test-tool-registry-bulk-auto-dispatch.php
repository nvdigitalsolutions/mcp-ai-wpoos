<?php
/**
 * Tests for the auto-async bulk-dispatch path in WP_MCP_AI_Tool_Registry.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/fixtures/class-phase2-test-bulk-tool.php';
require_once __DIR__ . '/fixtures/class-phase2-test-inline-tool.php';

/**
 * Tests for execute_tool()'s auto-async dispatch path (Phase 2).
 *
 * @covers WP_MCP_AI_Tool_Registry::execute_tool
 */
class Test_Tool_Registry_Bulk_Auto_Dispatch extends WP_UnitTestCase {

	/**
	 * Reset filters and unregister fixtures between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_bulk_auto_async_enabled' );
		remove_all_filters( 'wp_mcp_ai_bulk_async_threshold' );
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->unregister_tool( 'phase2_test_bulk_tool' );
		$registry->unregister_tool( 'phase2_test_inline_tool' );
		parent::tear_down();
	}

	/**
	 * Bulk-interface tools above the threshold get dispatched to the queue.
	 */
	public function test_bulk_tool_above_threshold_dispatches_async() {
		add_filter( 'wp_mcp_ai_bulk_auto_async_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_bulk_async_threshold',
			static function () {
				return 100;
			}
		);

		$tool     = new Phase2_Test_Bulk_Tool( 5000 );
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $tool );

		$result = $registry->execute_tool( 'phase2_test_bulk_tool', array() );

		$this->assertIsArray( $result );

		// When the async job queue is available the call is dispatched;
		// otherwise it falls back to inline execution — both are valid.
		if ( class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->assertArrayHasKey( 'async', $result );
			$this->assertTrue( $result['async'] );
			$this->assertSame( 5000, $result['estimated_rows'] );
			$this->assertGreaterThan( 0, $result['job_id'] );
			$this->assertFalse( $tool->was_executed_inline, 'Tool execute() must not run when dispatched async.' );
		} else {
			$this->assertArrayHasKey( 'inline', $result );
			$this->assertTrue( $result['inline'] );
			$this->assertTrue( $tool->was_executed_inline );
		}
	}

	/**
	 * Bulk-interface tools below the threshold execute inline.
	 */
	public function test_bulk_tool_below_threshold_runs_inline() {
		add_filter( 'wp_mcp_ai_bulk_auto_async_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_bulk_async_threshold',
			static function () {
				return 100;
			}
		);

		$tool     = new Phase2_Test_Bulk_Tool( 50 );
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $tool );

		$result = $registry->execute_tool( 'phase2_test_bulk_tool', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'inline', $result );
		$this->assertTrue( $result['inline'] );
		$this->assertTrue( $tool->was_executed_inline );
	}

	/**
	 * When auto-async is disabled (default), no dispatch happens even for huge estimates.
	 */
	public function test_auto_async_disabled_skips_dispatch() {
		$tool     = new Phase2_Test_Bulk_Tool( 99999 );
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $tool );

		$result = $registry->execute_tool( 'phase2_test_bulk_tool', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'inline', $result );
		$this->assertTrue( $tool->was_executed_inline );
	}

	/**
	 * Tools that don't implement the bulk interface are never auto-dispatched.
	 */
	public function test_non_bulk_tool_never_dispatched() {
		add_filter( 'wp_mcp_ai_bulk_auto_async_enabled', '__return_true' );

		$tool     = new Phase2_Test_Inline_Tool();
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->register_tool( $tool );

		$result = $registry->execute_tool( 'phase2_test_inline_tool', array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $tool->was_executed_inline );
	}
}
