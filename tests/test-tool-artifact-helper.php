<?php
/**
 * Tests for WP_MCP_AI_Tool_Artifact_Helper.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test cases for the artifact-streaming helper.
 *
 * @covers WP_MCP_AI_Tool_Artifact_Helper
 */
class Test_Tool_Artifact_Helper extends WP_UnitTestCase {

	/**
	 * Tear down filters between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_max_inline_rows' );
		remove_all_filters( 'wp_mcp_ai_tool_max_items' );
		parent::tear_down();
	}

	/**
	 * Resolve_max_items() returns the requested value when within bounds.
	 */
	public function test_resolve_max_items_uses_requested_value() {
		$value = WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'media_library_optimizer', 250, 500 );
		$this->assertSame( 250, $value );
	}

	/**
	 * Resolve_max_items() falls back to the hard default when no value supplied.
	 */
	public function test_resolve_max_items_falls_back_to_default() {
		$value = WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'foo_tool', 0, 750 );
		$this->assertSame( 750, $value );
	}

	/**
	 * Resolve_max_items() honours the wp_mcp_ai_tool_max_items filter.
	 */
	public function test_resolve_max_items_filter_clamps_value() {
		add_filter(
			'wp_mcp_ai_tool_max_items',
			static function ( $value, $slug ) {
				return 'media_library_optimizer' === $slug ? 50 : $value;
			},
			10,
			2
		);

		$this->assertSame( 50, WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'media_library_optimizer', 9999, 500 ) );
		$this->assertSame( 100, WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'other_tool', 100, 500 ) );
	}

	/**
	 * Should_stream_to_artifact() respects the default 100-row threshold.
	 */
	public function test_should_stream_to_artifact_default_threshold() {
		$this->assertFalse( WP_MCP_AI_Tool_Artifact_Helper::should_stream_to_artifact( 100, 'foo' ) );
		$this->assertTrue( WP_MCP_AI_Tool_Artifact_Helper::should_stream_to_artifact( 101, 'foo' ) );
	}

	/**
	 * Should_stream_to_artifact() honours the wp_mcp_ai_max_inline_rows filter.
	 */
	public function test_should_stream_to_artifact_filter() {
		add_filter( 'wp_mcp_ai_max_inline_rows', static fn() => 5 );
		$this->assertFalse( WP_MCP_AI_Tool_Artifact_Helper::should_stream_to_artifact( 5, 'foo' ) );
		$this->assertTrue( WP_MCP_AI_Tool_Artifact_Helper::should_stream_to_artifact( 6, 'foo' ) );
	}

	/**
	 * Stream_to_artifact() returns a stable envelope shape.
	 */
	public function test_stream_to_artifact_returns_envelope() {
		$payload  = range( 1, 250 );
		$envelope = WP_MCP_AI_Tool_Artifact_Helper::stream_to_artifact( $payload, 'demo_tool' );

		$this->assertIsArray( $envelope );
		$this->assertTrue( $envelope['truncated'] );
		$this->assertSame( 250, $envelope['count'] );
		$this->assertGreaterThan( 0, $envelope['original_bytes'] );
		$this->assertNotEmpty( $envelope['artifact_id'] );
		$this->assertStringStartsWith( 'demo_tool_', $envelope['artifact_id'] );
		$this->assertStringContainsString( 'mcp-ai/v1/artifacts/', $envelope['artifact_url'] );
	}

	/**
	 * Stored artifact can be retrieved and deleted.
	 */
	public function test_stream_and_retrieve_round_trip() {
		$payload  = array( 'rows' => array( 'a', 'b', 'c' ) );
		$envelope = WP_MCP_AI_Tool_Artifact_Helper::stream_to_artifact( $payload, 'roundtrip', array( 'count' => 3 ) );

		$retrieved = WP_MCP_AI_Tool_Artifact_Helper::retrieve( $envelope['artifact_id'] );
		$this->assertIsArray( $retrieved );
		$this->assertSame( $payload, $retrieved['payload'] );
		$this->assertSame( 'roundtrip', $retrieved['tool_slug'] );
		$this->assertSame( 3, $retrieved['count'] );

		$this->assertTrue( WP_MCP_AI_Tool_Artifact_Helper::delete( $envelope['artifact_id'] ) );
		$this->assertNull( WP_MCP_AI_Tool_Artifact_Helper::retrieve( $envelope['artifact_id'] ) );
	}

	/**
	 * Invalid artifact ids are rejected by retrieve() and delete().
	 */
	public function test_invalid_artifact_id_rejected() {
		$this->assertNull( WP_MCP_AI_Tool_Artifact_Helper::retrieve( '../../etc/passwd' ) );
		$this->assertFalse( WP_MCP_AI_Tool_Artifact_Helper::delete( 'bad id with spaces' ) );
		$this->assertNull( WP_MCP_AI_Tool_Artifact_Helper::retrieve( '' ) );
	}

	/**
	 * Non-default summary and extras flow through.
	 */
	public function test_extras_and_custom_summary() {
		$envelope = WP_MCP_AI_Tool_Artifact_Helper::stream_to_artifact(
			array( 1, 2, 3 ),
			'extras_tool',
			array(
				'summary' => 'Custom summary line',
				'extra'   => array(
					'job_id'    => 42,
					'truncated' => false,
				),
			)
		);

		$this->assertSame( 'Custom summary line', $envelope['summary'] );
		$this->assertSame( 42, $envelope['job_id'] );
		// `extra` should be able to override existing keys.
		$this->assertFalse( $envelope['truncated'] );
	}
}
