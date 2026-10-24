<?php
/**
 * Unit tests for the tool schema helper (proposal 066 integer-bound sweep).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Tool_Schema_Helper_Test extends WP_UnitTestCase {

	/**
	 * The helper class must be loadable in the test environment.
	 */
	public function test_helper_class_is_available() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Schema_Helper' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-schema-helper.php';
		}

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Schema_Helper' ) );
	}

	/**
	 * Object_id() declares a positive ID bounded by the JSON safe-integer ceiling.
	 */
	public function test_object_id_fragment() {
		$fragment = WP_MCP_AI_Tool_Schema_Helper::object_id( 'The post to update.' );

		$this->assertSame( 'integer', $fragment['type'] );
		$this->assertSame( 1, $fragment['minimum'] );
		$this->assertSame( WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER, $fragment['maximum'] );
		$this->assertSame( 'The post to update.', $fragment['description'] );
	}

	/**
	 * Integer() bounds only the ceiling — 0 and negatives stay legal for
	 * sentinel-carrying fields.
	 */
	public function test_integer_fragment_has_no_minimum() {
		$fragment = WP_MCP_AI_Tool_Schema_Helper::integer( 'A count.' );

		$this->assertSame( 'integer', $fragment['type'] );
		$this->assertArrayNotHasKey( 'minimum', $fragment );
		$this->assertSame( WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER, $fragment['maximum'] );
	}

	/**
	 * Timestamp() is the sentinel-safe integer bound.
	 */
	public function test_timestamp_fragment() {
		$fragment = WP_MCP_AI_Tool_Schema_Helper::timestamp( 'Unix timestamp.' );

		$this->assertSame( 'integer', $fragment['type'] );
		$this->assertArrayNotHasKey( 'minimum', $fragment );
		$this->assertSame( WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER, $fragment['maximum'] );
	}

	/**
	 * MAX_SAFE_INTEGER is 2^53 - 1 — the exact-representation ceiling that
	 * the auditor's unsafe-integer hazard guards against.
	 */
	public function test_max_safe_integer_value() {
		$this->assertSame( 9007199254740991, WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER );
	}

	/**
	 * Fragments satisfy the auditor: a schema built from them reports no
	 * unsafe-integer finding.
	 */
	public function test_fragments_clear_the_auditor() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Schema_Auditor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-schema-auditor.php';
		}

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => WP_MCP_AI_Tool_Schema_Helper::object_id( 'Post ID.' ),
				'stamp'   => WP_MCP_AI_Tool_Schema_Helper::timestamp( 'Timestamp.' ),
			),
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'fragment_tool', $schema ), 'code' );

		$this->assertNotContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_UNSAFE_INTEGER, $codes );
	}
}
