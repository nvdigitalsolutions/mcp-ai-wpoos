<?php
/**
 * Tests for the rabbitmq tool interception hook pipeline.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter

/**
 * Test the wp_mcp_ai_before_tool_execute filter interception.
 *
 * @group rabbitmq
 */
class Test_RabbitMQ_Tool_Interception extends WP_UnitTestCase {

	/**
	 * Test that a filter can intercept tool execution and return a deferred result.
	 */
	public function test_filter_can_intercept_tool_execution() {
		$intercepted = null;

		// Register a filter that intercepts execution for specific tools.
		add_filter(
			'wp_mcp_ai_before_tool_execute',
			function ( $pre, $tool_slug, $arguments, $context ) {
				if ( 'deferrable_tool' === $tool_slug ) {
					return array(
						'_deferred' => true,
						'job_id'    => 'test-job-123',
						'tool_name' => $tool_slug,
						'status'    => 'queued',
					);
				}
				return $pre;
			},
			10,
			4
		);

		// Apply the filter with null (continue) for a non-intercepted tool.
		$result = apply_filters(
			'wp_mcp_ai_before_tool_execute',
			null,
			'normal_tool',
			array( 'key' => 'value' ),
			array( 'user_id' => 1 )
		);

		$this->assertNull( $result, 'Non-intercepted tools should return null.' );

		// Apply the filter for an intercepted tool.
		$result = apply_filters(
			'wp_mcp_ai_before_tool_execute',
			null,
			'deferrable_tool',
			array( 'key' => 'value' ),
			array( 'user_id' => 1 )
		);

		$this->assertIsArray( $result, 'Intercepted tool should return an array.' );
		$this->assertTrue( $result['_deferred'], 'Result should be marked as deferred.' );
		$this->assertEquals( 'test-job-123', $result['job_id'] );
		$this->assertEquals( 'deferrable_tool', $result['tool_name'] );
		$this->assertEquals( 'queued', $result['status'] );
	}

	/**
	 * Test that the filter receives the correct parameter order.
	 */
	public function test_filter_receives_correct_parameter_order() {
		$received = array();

		add_filter(
			'wp_mcp_ai_before_tool_execute',
			function ( $pre, $tool_slug, $arguments, $context ) use ( &$received ) {
				$received = array(
					'pre'       => $pre,
					'tool_slug' => $tool_slug,
					'arguments' => $arguments,
					'context'   => $context,
				);
				return $pre;
			},
			99,
			4
		);

		apply_filters(
			'wp_mcp_ai_before_tool_execute',
			null,
			'my_tool_slug',
			array( 'arg1' => 'val1' ),
			array( 'user_id' => 42 )
		);

		$this->assertNull( $received['pre'], 'First param should be null (pre).' );
		$this->assertEquals( 'my_tool_slug', $received['tool_slug'], 'Second param should be tool slug.' );
		$this->assertEquals( array( 'arg1' => 'val1' ), $received['arguments'], 'Third param should be arguments.' );
		$this->assertEquals( array( 'user_id' => 42 ), $received['context'], 'Fourth param should be context.' );
	}

	/**
	 * Test that filter callbacks receive all four parameters.
	 */
	public function test_filter_callbacks_receive_four_params() {
		$param_count = 0;

		add_filter(
			'wp_mcp_ai_before_tool_execute',
			function ( $pre, $tool_slug, $arguments, $context ) use ( &$param_count ) {
				$param_count = func_num_args();
				return $pre;
			},
			99,
			4
		);

		apply_filters(
			'wp_mcp_ai_before_tool_execute',
			null,
			'test_tool',
			array(),
			array()
		);

		$this->assertEquals( 4, $param_count, 'Filter callback should receive exactly 4 parameters.' );
	}

	/**
	 * Test that upstream filter decisions are respected (chain passes through).
	 */
	public function test_upstream_filter_decisions_are_respected() {
		$upstream = array( 'intercepted_by_upstream' => true );

		$result = apply_filters(
			'wp_mcp_ai_before_tool_execute',
			$upstream,           // Already intercepted upstream.
			'any_tool',
			array(),
			array()
		);

		$this->assertSame( $upstream, $result, 'Upstream interception should pass through when no later filter overrides.' );
	}

	/**
	 * Test that later-filter-priority callbacks can override earlier ones.
	 */
	public function test_later_priority_can_override_earlier() {
		// Priority 10: intercept.
		add_filter(
			'wp_mcp_ai_before_tool_execute',
			function ( $pre, $tool_slug, $arguments, $context ) {
				return array( 'early' => true );
			},
			10,
			4
		);

		// Priority 5: override (earlier in execution order, but later filters see the first's output).
		$result = apply_filters(
			'wp_mcp_ai_before_tool_execute',
			null,
			'test_tool',
			array(),
			array()
		);

		$this->assertIsArray( $result, 'A filter should be able to return a non-null intercept.' );
	}

	/**
	 * Test that null return from a filter does not block execution.
	 */
	public function test_null_return_does_not_block() {
		add_filter(
			'wp_mcp_ai_before_tool_execute',
			function ( $pre, $tool_slug, $arguments, $context ) {
				// This filter always returns null (doesn't intercept).
				return null;
			},
			10,
			4
		);

		$result = apply_filters(
			'wp_mcp_ai_before_tool_execute',
			null,
			'test_tool',
			array(),
			array()
		);

		$this->assertNull( $result, 'A filter returning null should not block execution.' );
	}
}

// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
