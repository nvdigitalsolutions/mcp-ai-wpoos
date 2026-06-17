<?php
/**
 * Tests for WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result().
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Phase 3 — agentic-loop output guard artifact-spill helper.
 *
 * @covers WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result
 */
class Test_Tool_Artifact_Helper_Wrap_Oversized extends WP_UnitTestCase {

	/**
	 * Reset hooks between tests.
	 */
	public function tear_down() {
		remove_all_actions( 'wp_mcp_ai_tool_output_truncated' );
		parent::tear_down();
	}

	/**
	 * Returns a JSON-encoded envelope (string) suitable for tool message body.
	 */
	public function test_returns_json_string_envelope() {
		$payload = str_repeat( 'X', 10000 );
		$json    = WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result( $payload, 'sample_tool' );

		$this->assertIsString( $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['truncated'] );
		$this->assertSame( 'agentic_output_budget_exceeded', $decoded['reason'] );
		$this->assertSame( 'sample_tool', $decoded['tool_name'] );
		$this->assertSame( 10000, $decoded['original_bytes'] );
		$this->assertNotEmpty( $decoded['artifact_id'] );
		$this->assertArrayHasKey( 'preview', $decoded );
	}

	/**
	 * The artifact is retrievable via the existing retrieve() method.
	 */
	public function test_artifact_is_retrievable() {
		$payload = 'hello world payload';
		$json    = WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result( $payload, 'sample_tool' );
		$decoded = json_decode( $json, true );

		$record = WP_MCP_AI_Tool_Artifact_Helper::retrieve( $decoded['artifact_id'] );
		$this->assertIsArray( $record );
		$this->assertSame( $payload, $record['payload'] );
		$this->assertSame( 'sample_tool', $record['tool_slug'] );
	}

	/**
	 * Preview is bounded to ~256 chars.
	 */
	public function test_preview_is_bounded() {
		$payload = str_repeat( 'A', 5000 );
		$json    = WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result( $payload, 'sample_tool' );
		$decoded = json_decode( $json, true );

		// Preview includes ellipsis suffix when truncated.
		$this->assertLessThanOrEqual( 300, strlen( $decoded['preview'] ) );
	}

	/**
	 * Fires the wp_mcp_ai_tool_output_truncated action with expected args.
	 */
	public function test_fires_tool_output_truncated_action() {
		$captured = array();
		add_action(
			'wp_mcp_ai_tool_output_truncated',
			static function ( $tool_name, $bytes, $artifact_id, $context ) use ( &$captured ) {
				$captured = array(
					'tool_name'   => $tool_name,
					'bytes'       => $bytes,
					'artifact_id' => $artifact_id,
					'context'     => $context,
				);
			},
			10,
			4
		);

		WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result(
			'big payload',
			'sample_tool',
			array( 'request_id' => 'req-42' )
		);

		$this->assertSame( 'sample_tool', $captured['tool_name'] );
		$this->assertSame( 11, $captured['bytes'] );
		$this->assertNotEmpty( $captured['artifact_id'] );
		$this->assertSame( 'req-42', $captured['context']['request_id'] );
	}

	/**
	 * Non-string input is JSON-encoded transparently.
	 */
	public function test_non_string_input_json_encoded() {
		$json    = WP_MCP_AI_Tool_Artifact_Helper::wrap_oversized_tool_result( array( 'a' => 1 ), 'sample_tool' );
		$decoded = json_decode( $json, true );
		$this->assertGreaterThan( 0, $decoded['original_bytes'] );
	}
}
