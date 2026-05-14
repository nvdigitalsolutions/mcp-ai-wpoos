<?php
/**
 * Tests for the WP_MCP_AI_Tool_Lifecycle_Descriptor helper.
 *
 * Phase P4 of the Unix Theory Compliance Enhancement Proposal.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test-case for the Phase P4 lifecycle descriptor helper and back-compat behaviour.
 *
 * @group unix-theory
 * @group hooks
 */
class Test_Tool_Lifecycle_Descriptor extends WP_UnitTestCase {

	/**
	 * Success result with no `produces` field defaults to "array".
	 */
	public function test_build_with_array_result_returns_array_data_type() {
		$descriptor = WP_MCP_AI_Tool_Lifecycle_Descriptor::build(
			array(
				'success' => true,
				'data' => array( 'foo' => 'bar' ),
			),
			microtime( true ),
			'example_tool'
		);

		$this->assertTrue( $descriptor['success'] );
		$this->assertNull( $descriptor['error_code'] );
		$this->assertSame( 'array', $descriptor['data_type'] );
		$this->assertIsFloat( $descriptor['duration_ms'] );
		$this->assertGreaterThanOrEqual( 0.0, $descriptor['duration_ms'] );
	}

	/**
	 * Success result carrying `produces` is preserved (sanitized).
	 */
	public function test_build_respects_produces_field_in_result() {
		$descriptor = WP_MCP_AI_Tool_Lifecycle_Descriptor::build(
			array(
				'success' => true,
				'produces' => 'post_object',
				'data' => 1,
			),
			null,
			'example_tool'
		);

		$this->assertSame( 'post_object', $descriptor['data_type'] );
		$this->assertNull( $descriptor['duration_ms'] );
	}

	/**
	 * `produces` field is normalised via sanitize_key().
	 */
	public function test_build_sanitises_produces_field() {
		$descriptor = WP_MCP_AI_Tool_Lifecycle_Descriptor::build(
			array( 'produces' => 'Post Object!' ),
			null
		);

		$this->assertSame( 'post-object', $descriptor['data_type'] );
	}

	/**
	 * WP_Error results expose `error_code` and null `data_type`.
	 */
	public function test_build_with_wp_error_returns_failure_descriptor() {
		$descriptor = WP_MCP_AI_Tool_Lifecycle_Descriptor::build(
			new WP_Error( 'something_failed', 'Something failed.' ),
			microtime( true ),
			'example_tool'
		);

		$this->assertFalse( $descriptor['success'] );
		$this->assertSame( 'something_failed', $descriptor['error_code'] );
		$this->assertNull( $descriptor['data_type'] );
	}

	/**
	 * Scalar results get a coarse type label.
	 */
	public function test_build_with_scalar_results_labels_data_type() {
		$this->assertSame( 'string', WP_MCP_AI_Tool_Lifecycle_Descriptor::build( 'hello' )['data_type'] );
		$this->assertSame( 'int', WP_MCP_AI_Tool_Lifecycle_Descriptor::build( 42 )['data_type'] );
		$this->assertSame( 'bool', WP_MCP_AI_Tool_Lifecycle_Descriptor::build( true )['data_type'] );
		$this->assertSame( 'float', WP_MCP_AI_Tool_Lifecycle_Descriptor::build( 1.5 )['data_type'] );
		$this->assertSame( 'null', WP_MCP_AI_Tool_Lifecycle_Descriptor::build( null )['data_type'] );
	}

	/**
	 * Negative durations (clock skew) are clamped to zero.
	 */
	public function test_build_clamps_negative_duration_to_zero() {
		$future_start = microtime( true ) + 10.0;
		$descriptor   = WP_MCP_AI_Tool_Lifecycle_Descriptor::build( array(), $future_start, 'example_tool' );

		$this->assertSame( 0.0, $descriptor['duration_ms'] );
	}

	/**
	 * Null start time yields null duration.
	 */
	public function test_build_with_null_start_returns_null_duration() {
		$descriptor = WP_MCP_AI_Tool_Lifecycle_Descriptor::build( array(), null, 'example_tool' );
		$this->assertNull( $descriptor['duration_ms'] );
	}

	/**
	 * The descriptor filter receives the descriptor + raw result + slug + context.
	 */
	public function test_build_applies_filter() {
		$captured = array();

		$cb = function ( $descriptor, $result, $tool_slug, $context ) use ( &$captured ) {
			$captured            = array(
				'tool_slug' => $tool_slug,
				'context'   => $context,
			);
			$descriptor['bytes'] = 1234;
			return $descriptor;
		};

		add_filter( 'wp_mcp_ai_tool_lifecycle_descriptor', $cb, 10, 4 );

		$descriptor = WP_MCP_AI_Tool_Lifecycle_Descriptor::build(
			array( 'success' => true ),
			microtime( true ),
			'my_tool',
			array( 'assistant_id' => 99 )
		);

		remove_filter( 'wp_mcp_ai_tool_lifecycle_descriptor', $cb, 10 );

		$this->assertSame( 1234, $descriptor['bytes'] );
		$this->assertSame( 'my_tool', $captured['tool_slug'] );
		$this->assertSame( array( 'assistant_id' => 99 ), $captured['context'] );
	}

	/**
	 * Subscribers registered with accepted_args = 4 keep receiving only 4 args.
	 *
	 * This guards back-compat: the optional 5th argument MUST NOT break older
	 * listeners that don't ask for it.
	 */
	public function test_subscribers_with_four_args_keep_working() {
		$received = array();

		$cb = function ( $tool_slug, $arguments, $context, $result ) use ( &$received ) {
			$received = func_get_args();
		};

		add_action( 'wp_mcp_ai_after_tool_execution', $cb, 10, 4 );

		do_action(
			'wp_mcp_ai_after_tool_execution',
			'my_tool',
			array( 'arg' => 1 ),
			array( 'assistant_id' => 1 ),
			array( 'success' => true ),
			WP_MCP_AI_Tool_Lifecycle_Descriptor::build( array( 'success' => true ), microtime( true ), 'my_tool' )
		);

		remove_action( 'wp_mcp_ai_after_tool_execution', $cb, 10 );

		$this->assertCount( 4, $received );
		$this->assertSame( 'my_tool', $received[0] );
	}

	/**
	 * Subscribers registered with accepted_args = 5 receive the descriptor.
	 */
	public function test_subscribers_with_five_args_receive_descriptor() {
		$received_descriptor = null;

		$cb = function ( $tool_slug, $arguments, $context, $result, $descriptor ) use ( &$received_descriptor ) {
			$received_descriptor = $descriptor;
		};

		add_action( 'wp_mcp_ai_after_tool_execution', $cb, 10, 5 );

		$start = microtime( true );
		do_action(
			'wp_mcp_ai_after_tool_execution',
			'my_tool',
			array(),
			array(),
			array( 'success' => true ),
			WP_MCP_AI_Tool_Lifecycle_Descriptor::build( array( 'success' => true ), $start, 'my_tool' )
		);

		remove_action( 'wp_mcp_ai_after_tool_execution', $cb, 10 );

		$this->assertIsArray( $received_descriptor );
		$this->assertTrue( $received_descriptor['success'] );
		$this->assertSame( 'array', $received_descriptor['data_type'] );
		$this->assertIsFloat( $received_descriptor['duration_ms'] );
	}
}
