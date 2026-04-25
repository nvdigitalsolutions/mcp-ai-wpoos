<?php
/**
 * Tests for the tool execution observer.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observer tests.
 */
class Test_WP_MCP_AI_Tool_Execution_Observer extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		// Ensure the stock metrics are registered on a fresh registry
		// so collector.record() can find them. Order matters: reset
		// the collector AFTER the registry so it rebinds to the new
		// instance on first get_instance() call.
		WP_MCP_AI_Measurement_Registry::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Stock_Metrics::register( WP_MCP_AI_Measurement_Registry::get_instance() );

		WP_MCP_AI_Metric_Collector::get_instance()->clear_buffer();
		WP_MCP_AI_Tool_Execution_Observer::reset_instance();
		WP_MCP_AI_Tool_Execution_Observer::get_instance()->attach();
	}

	/**
	 * Teardown.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Tool_Execution_Observer::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Test success path emits count success duration.
	 */
	public function test_success_path_emits_count_success_duration() {
		do_action( 'wp_mcp_ai_before_tool_execution', 'example_tool', array(), array( 'assistant_id' => 7 ) );
		do_action( 'wp_mcp_ai_after_tool_execution', 'example_tool', array(), array( 'assistant_id' => 7 ), array( 'ok' => true ) );

		$buffered = WP_MCP_AI_Metric_Collector::get_instance()->buffered();
		$ids      = array_column( $buffered, 'id' );

		$this->assertContains( 'tool.execution.count', $ids );
		$this->assertContains( 'tool.execution.success.count', $ids );
		$this->assertContains( 'tool.execution.duration_ms', $ids );
		$this->assertNotContains( 'tool.execution.error.count', $ids );
	}

	/**
	 * Test error path emits error count.
	 */
	public function test_error_path_emits_error_count() {
		$err = new WP_Error( 'nope', 'nope' );
		do_action( 'wp_mcp_ai_before_tool_execution', 'example_tool', array(), array() );
		do_action( 'wp_mcp_ai_after_tool_execution', 'example_tool', array(), array(), $err );

		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );

		$this->assertContains( 'tool.execution.error.count', $ids );
		$this->assertNotContains( 'tool.execution.success.count', $ids );
	}

	/**
	 * Test context payload stays internal tier.
	 */
	public function test_context_payload_stays_internal_tier() {
		do_action( 'wp_mcp_ai_before_tool_execution', 'example_tool', array( 'secret' => 'leaking' ), array( 'assistant_id' => 7 ) );
		do_action( 'wp_mcp_ai_after_tool_execution', 'example_tool', array( 'secret' => 'leaking' ), array( 'assistant_id' => 7 ), 'ok' );

		$buffered = WP_MCP_AI_Metric_Collector::get_instance()->buffered();
		foreach ( $buffered as $event ) {
			$serialized = wp_json_encode( $event['context'] );
			$this->assertStringNotContainsString( 'secret', (string) $serialized, 'Observer context must not leak tool arguments.' );
			$this->assertStringNotContainsString( 'leaking', (string) $serialized );
		}
	}

	/**
	 * Test stack handles nested calls with same slug.
	 */
	public function test_stack_handles_nested_calls_with_same_slug() {
		$observer = WP_MCP_AI_Tool_Execution_Observer::get_instance();
		do_action( 'wp_mcp_ai_before_tool_execution', 'example_tool', array(), array() );
		do_action( 'wp_mcp_ai_before_tool_execution', 'example_tool', array(), array() );
		$this->assertSame( 2, $observer->depth() );
		do_action( 'wp_mcp_ai_after_tool_execution', 'example_tool', array(), array(), 'ok' );
		$this->assertSame( 1, $observer->depth() );
		do_action( 'wp_mcp_ai_after_tool_execution', 'example_tool', array(), array(), 'ok' );
		$this->assertSame( 0, $observer->depth() );
	}

	/**
	 * Test mismatched after does not underflow.
	 */
	public function test_mismatched_after_does_not_underflow() {
		$observer = WP_MCP_AI_Tool_Execution_Observer::get_instance();
		// After with no matching before — must not fatal or underflow.
		do_action( 'wp_mcp_ai_after_tool_execution', 'orphan_tool', array(), array(), 'ok' );
		$this->assertSame( 0, $observer->depth() );

		// Still produces the count/success metrics — a duration metric
		// is skipped because no start time was captured.
		$ids = array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );
		$this->assertContains( 'tool.execution.count', $ids );
		$this->assertNotContains( 'tool.execution.duration_ms', $ids );
	}

	/**
	 * Test interleaved different slugs pop correctly.
	 */
	public function test_interleaved_different_slugs_pop_correctly() {
		$observer = WP_MCP_AI_Tool_Execution_Observer::get_instance();
		do_action( 'wp_mcp_ai_before_tool_execution', 'a_tool', array(), array() );
		do_action( 'wp_mcp_ai_before_tool_execution', 'b_tool', array(), array() );
		// Close the outer first — simulates a misordered after from a
		// 3rd-party hook. The observer should locate `a_tool` on the
		// stack and remove it without disturbing `b_tool`.
		do_action( 'wp_mcp_ai_after_tool_execution', 'a_tool', array(), array(), 'ok' );
		$this->assertSame( 1, $observer->depth() );
		do_action( 'wp_mcp_ai_after_tool_execution', 'b_tool', array(), array(), 'ok' );
		$this->assertSame( 0, $observer->depth() );
	}

	/**
	 * Test filter disables observer.
	 */
	public function test_filter_disables_observer() {
		WP_MCP_AI_Tool_Execution_Observer::reset_instance();
		$filter = static function () {
			return false;
		};
		add_filter( 'wp_mcp_ai_tool_execution_observer_enabled', $filter );
		$attached = WP_MCP_AI_Tool_Execution_Observer::get_instance()->attach();
		$this->assertFalse( $attached );
		remove_filter( 'wp_mcp_ai_tool_execution_observer_enabled', $filter );
	}

	/**
	 * Test detach is idempotent and clears stack.
	 */
	public function test_detach_is_idempotent_and_clears_stack() {
		$observer = WP_MCP_AI_Tool_Execution_Observer::get_instance();
		do_action( 'wp_mcp_ai_before_tool_execution', 'example_tool', array(), array() );
		$observer->detach();
		$this->assertSame( 0, $observer->depth() );
		$observer->detach(); // second call is a no-op.
		$this->assertSame( 0, $observer->depth() );
	}

	/**
	 * Test in flight gauge reflects depth.
	 */
	public function test_in_flight_gauge_reflects_depth() {
		do_action( 'wp_mcp_ai_before_tool_execution', 'a', array(), array() );
		do_action( 'wp_mcp_ai_before_tool_execution', 'b', array(), array() );

		$gauges = array_filter(
			WP_MCP_AI_Metric_Collector::get_instance()->buffered(),
			static function ( $e ) {
				return 'tool.execution.in_flight' === $e['id']; }
		);
		$values = array_map(
			static function ( $e ) {
				return (float) $e['value'];
			},
			$gauges
		);
		$this->assertContains( 1.0, $values );
		$this->assertContains( 2.0, $values );
	}
}
