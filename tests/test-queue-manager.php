<?php
/**
 * Tests for Queue Manager.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Queue Manager functionality.
 *
 * @group rabbitmq
 */
class Test_Queue_Manager extends WP_UnitTestCase {

	/**
	 * Test manager returns singleton instance.
	 */
	public function test_get_instance_returns_singleton() {
		if ( ! class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Queue_Manager class not loaded.' );
		}

		$instance1 = WP_MCP_AI_Queue_Manager::get_instance();
		$instance2 = WP_MCP_AI_Queue_Manager::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same singleton instance.' );
	}

	/**
	 * Test execution mode constants are defined.
	 */
	public function test_execution_mode_constants() {
		if ( ! class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Queue_Manager class not loaded.' );
		}

		$this->assertEquals( 'sync', WP_MCP_AI_Queue_Manager::MODE_SYNC );
		$this->assertEquals( 'queue', WP_MCP_AI_Queue_Manager::MODE_QUEUE );
		$this->assertEquals( 'queue_async', WP_MCP_AI_Queue_Manager::MODE_QUEUE_ASYNC );
		$this->assertEquals( 'parallel', WP_MCP_AI_Queue_Manager::MODE_PARALLEL );
	}

	/**
	 * Test priority constants are defined.
	 */
	public function test_priority_constants() {
		if ( ! class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Queue_Manager class not loaded.' );
		}

		$this->assertEquals( 'high', WP_MCP_AI_Queue_Manager::PRIORITY_HIGH );
		$this->assertEquals( 'normal', WP_MCP_AI_Queue_Manager::PRIORITY_NORMAL );
		$this->assertEquals( 'async', WP_MCP_AI_Queue_Manager::PRIORITY_LOW );
	}

	/**
	 * Test threshold constants are defined.
	 */
	public function test_threshold_constants() {
		if ( ! class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Queue_Manager class not loaded.' );
		}

		$this->assertEquals( 2000, WP_MCP_AI_Queue_Manager::QUICK_TOOL_THRESHOLD );
		$this->assertEquals( 10000, WP_MCP_AI_Queue_Manager::ASYNC_TOOL_THRESHOLD );
	}

	/**
	 * Test queue availability returns false when RabbitMQ not configured.
	 */
	public function test_is_queue_available_without_rabbitmq() {
		if ( ! class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Queue_Manager class not loaded.' );
		}

		$manager = WP_MCP_AI_Queue_Manager::get_instance();

		// Without RabbitMQ configured, should return false.
		$this->assertFalse( $manager->is_queue_available() );
	}

	/**
	 * Test execution mode defaults to sync when queue unavailable.
	 */
	public function test_get_execution_mode_defaults_to_sync() {
		if ( ! class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Queue_Manager class not loaded.' );
		}

		$manager = WP_MCP_AI_Queue_Manager::get_instance();

		$mode = $manager->get_execution_mode( 'test_tool', array(), array() );

		$this->assertEquals( WP_MCP_AI_Queue_Manager::MODE_SYNC, $mode );
	}

	/**
	 * Test queue stats return expected structure when unavailable.
	 */
	public function test_get_queue_stats_when_unavailable() {
		if ( ! class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Queue_Manager class not loaded.' );
		}

		$manager = WP_MCP_AI_Queue_Manager::get_instance();
		$stats   = $manager->get_queue_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'available', $stats );
		$this->assertFalse( $stats['available'] );
	}

	/**
	 * Test can_parallelize returns false for unknown tools.
	 */
	public function test_can_parallelize_unknown_tool() {
		if ( ! class_exists( 'WP_MCP_AI_Queue_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Queue_Manager class not loaded.' );
		}

		$manager = WP_MCP_AI_Queue_Manager::get_instance();

		$this->assertFalse( $manager->can_parallelize( 'nonexistent_tool_12345' ) );
	}
}
