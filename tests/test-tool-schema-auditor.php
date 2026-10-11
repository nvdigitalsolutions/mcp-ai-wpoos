<?php
/**
 * Unit tests for the MCP tool schema auditor (proposal 066).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Tool_Schema_Auditor_Test extends WP_UnitTestCase {

	/**
	 * The auditor class must be loadable in the test environment.
	 */
	public function test_auditor_class_is_available() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Schema_Auditor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-schema-auditor.php';
		}

		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Schema_Auditor' ) );
	}

	/**
	 * A clean schema audits to zero findings.
	 */
	public function test_clean_schema_has_no_findings() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
			),
		);

		$this->assertSame( array(), WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'clean_tool', $schema ) );
	}

	/**
	 * A missing root type is reported.
	 */
	public function test_missing_root_type_is_flagged() {
		$schema = array( 'properties' => array() );

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'untyped_tool', $schema ), 'code' );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_ROOT_TYPE_MISSING, $codes );
	}

	/**
	 * A root combinator next to properties is reported.
	 */
	public function test_root_combinator_with_properties_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'q' => array( 'type' => 'string' ) ),
			'anyOf'      => array(
				array( 'required' => array( 'q' ) ),
			),
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'combo_tool', $schema ), 'code' );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_ROOT_COMBINATOR, $codes );
	}

	/**
	 * Bracketed property names are reported.
	 */
	public function test_bracket_property_name_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'ids[]' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'bracket_tool', $schema ), 'code' );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_BRACKET_PROPERTY, $codes );
	}

	/**
	 * PrefixItems tuples are reported.
	 */
	public function test_prefix_items_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'row' => array(
					'type'        => 'array',
					'prefixItems' => array( array( 'type' => 'string' ) ),
				),
			),
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'tuple_tool', $schema ), 'code' );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_PREFIX_ITEMS, $codes );
	}

	/**
	 * An enum inside a combinator on a large schema is reported.
	 */
	public function test_enum_in_anyof_on_large_schema_is_flagged() {
		$padding = str_repeat( 'x', WP_MCP_AI_Tool_Schema_Auditor::LARGE_SCHEMA_BYTES );

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'kind' => array(
					'description' => $padding,
					'anyOf'       => array(
						array(
							'type' => 'string',
							'enum' => array( 'a', 'b' ),
						),
					),
				),
			),
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'enum_tool', $schema ), 'code' );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_ENUM_IN_ANYOF_LARGE, $codes );
	}

	/**
	 * An unbounded integer on an id-style field is reported.
	 */
	public function test_unbounded_integer_id_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array( 'type' => 'integer' ),
			),
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'int_tool', $schema ), 'code' );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_UNSAFE_INTEGER, $codes );
	}

	/**
	 * A bounded integer on an id-style field is not reported.
	 */
	public function test_bounded_integer_id_is_clean() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'order_id' => array(
					'type'    => 'integer',
					'maximum' => 999999,
				),
			),
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'int_tool', $schema ), 'code' );

		$this->assertNotContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_UNSAFE_INTEGER, $codes );
	}

	/**
	 * A slug over the length limit is reported.
	 */
	public function test_over_length_slug_is_flagged() {
		$slug   = str_repeat( 'a', 65 );
		$schema = array(
			'type'       => 'object',
			'properties' => array(),
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( $slug, $schema ), 'code' );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_SLUG_TOO_LONG, $codes );
	}

	/**
	 * A schema over the byte budget is reported.
	 */
	public function test_over_budget_schema_is_flagged() {
		$padding = str_repeat( 'x', WP_MCP_AI_Tool_Schema_Auditor::DEFAULT_MAX_SCHEMA_BYTES + 100 );

		$schema = array(
			'type'        => 'object',
			'properties'  => array( 'note' => array( 'type' => 'string' ) ),
			'description' => $padding,
		);

		$codes = wp_list_pluck( WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'big_tool', $schema ), 'code' );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_OVER_BUDGET, $codes );
	}

	/**
	 * Normalize_for_mcp injects a root type for untyped schemas.
	 */
	public function test_normalize_injects_root_type() {
		$schema = array( 'properties' => array() );

		$normalized = WP_MCP_AI_Tool_Schema_Auditor::normalize_for_mcp( 'untyped_tool', $schema );

		$this->assertSame( 'object', $normalized['type'] );
		$this->assertSame( array(), $normalized['properties'] );
	}

	/**
	 * Normalize_for_mcp rejects bracketed property names.
	 */
	public function test_normalize_rejects_bracket_property() {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'ids[]' => array( 'type' => 'array' ) ),
		);

		$result = WP_MCP_AI_Tool_Schema_Auditor::normalize_for_mcp( 'bracket_tool', $schema );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_schema_bracket_property', $result->get_error_code() );
	}

	/**
	 * Normalize_for_mcp rejects root combinators without a type.
	 */
	public function test_normalize_rejects_root_combinator_without_type() {
		$schema = array(
			'properties' => array( 'q' => array( 'type' => 'string' ) ),
			'anyOf'      => array( array( 'required' => array( 'q' ) ) ),
		);

		$result = WP_MCP_AI_Tool_Schema_Auditor::normalize_for_mcp( 'combo_tool', $schema );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_schema_root_combinator', $result->get_error_code() );
	}

	/**
	 * Normalize_for_mcp rejects schemas over the byte budget.
	 */
	public function test_normalize_rejects_over_budget_schema() {
		$schema = array(
			'type'        => 'object',
			'properties'  => array(),
			'description' => str_repeat( 'x', 500 ),
		);

		$result = WP_MCP_AI_Tool_Schema_Auditor::normalize_for_mcp( 'big_tool', $schema, 100 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_schema_over_budget', $result->get_error_code() );
	}

	/**
	 * Normalize_for_mcp rejects over-length slugs.
	 */
	public function test_normalize_rejects_over_length_slug() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(),
		);

		$result = WP_MCP_AI_Tool_Schema_Auditor::normalize_for_mcp( str_repeat( 'a', 65 ), $schema );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_schema_slug_too_long', $result->get_error_code() );
	}

	/**
	 * Slug_is_mcp_safe enforces the charset and length rules.
	 */
	public function test_slug_is_mcp_safe() {
		$this->assertTrue( WP_MCP_AI_Tool_Schema_Auditor::slug_is_mcp_safe( 'nvoos_get_profile' ) );
		$this->assertTrue( WP_MCP_AI_Tool_Schema_Auditor::slug_is_mcp_safe( 'create-post' ) );
		$this->assertFalse( WP_MCP_AI_Tool_Schema_Auditor::slug_is_mcp_safe( '' ) );
		$this->assertFalse( WP_MCP_AI_Tool_Schema_Auditor::slug_is_mcp_safe( 'fetch.page' ) );
		$this->assertFalse( WP_MCP_AI_Tool_Schema_Auditor::slug_is_mcp_safe( str_repeat( 'a', 65 ) ) );
	}

	/**
	 * Summarize aggregates counts and samples.
	 */
	public function test_summarize_aggregates_findings() {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'ids[]' => array( 'type' => 'array' ) ),
		);

		$findings = array(
			'one' => WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'one', $schema ),
			'two' => WP_MCP_AI_Tool_Schema_Auditor::audit_schema( 'two', $schema ),
		);

		$summary = WP_MCP_AI_Tool_Schema_Auditor::summarize( $findings );

		$this->assertSame( 2, $summary['slugs_affected'] );
		$this->assertSame( 2, $summary['counts'][ WP_MCP_AI_Tool_Schema_Auditor::FINDING_BRACKET_PROPERTY ] );
		$this->assertSame( array( 'one', 'two' ), $summary['samples'][ WP_MCP_AI_Tool_Schema_Auditor::FINDING_BRACKET_PROPERTY ] );
	}
}
