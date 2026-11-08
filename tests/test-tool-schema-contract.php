<?php
/**
 * Unit tests for WP_MCP_AI_Tool_Schema_Auditor::audit_contract().
 *
 * Covers the schema self-consistency pass added alongside the typed
 * signature work (Proposal 132, WS-6). Client-emission hazards live in
 * test-tool-schema-auditor.php; these are authoring-contract hazards.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Tool_Schema_Contract_Test extends WP_UnitTestCase {

	/**
	 * Load the auditor.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Schema_Auditor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-schema-auditor.php';
		}
	}

	/**
	 * Extract finding codes for assertions.
	 *
	 * @param array $findings Findings.
	 * @return string[]
	 */
	private function codes( array $findings ) {
		return array_values( array_unique( array_column( $findings, 'code' ) ) );
	}

	/**
	 * A consistent schema produces no findings.
	 */
	public function test_consistent_schema_is_clean() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'bytes'    => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'decimals' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 6,
				),
				'binary'   => array( 'type' => 'boolean' ),
				'plugin'   => array( 'type' => 'string' ),
				'filter'   => array(
					'anyOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'object' ),
					),
				),
			),
			'required'   => array( 'bytes' ),
		);

		$this->assertSame( array(), WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'clean_tool', $schema ) );
	}

	/**
	 * `required` naming an undeclared property is reported.
	 */
	public function test_required_key_not_declared_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'bytes' => array( 'type' => 'integer' ) ),
			'required'   => array( 'bytes', 'decimals' ),
		);

		$findings = WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', $schema );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_REQUIRED_UNDECLARED, $this->codes( $findings ) );
	}

	/**
	 * A property with neither type nor union is reported.
	 */
	public function test_typeless_property_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'mystery' => array( 'description' => 'No type.' ) ),
		);

		$findings = WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', $schema );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_PROPERTY_TYPE_MISSING, $this->codes( $findings ) );
	}

	/**
	 * Union properties are allowed to omit `type`.
	 */
	public function test_union_property_without_type_is_clean() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'id' => array(
					'anyOf' => array(
						array( 'type' => 'integer' ),
						array( 'type' => 'string' ),
					),
				),
			),
		);

		$this->assertSame( array(), WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', $schema ) );
	}

	/**
	 * Array properties must declare items.
	 */
	public function test_array_without_items_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'ids' => array( 'type' => 'array' ) ),
		);

		$findings = WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', $schema );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_ARRAY_ITEMS_MISSING, $this->codes( $findings ) );
	}

	/**
	 * Array properties with items are clean.
	 */
	public function test_array_with_items_is_clean() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
		);

		$this->assertSame( array(), WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', $schema ) );
	}

	/**
	 * A minimum above the maximum is reported.
	 */
	public function test_minimum_above_maximum_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 100,
					'maximum' => 1,
				),
			),
		);

		$findings = WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', $schema );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_MIN_ABOVE_MAX, $this->codes( $findings ) );
	}

	/**
	 * Enum values must match the declared type.
	 */
	public function test_enum_type_mismatch_is_flagged() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'mode' => array(
					'type' => 'string',
					'enum' => array( 'fast', 2 ),
				),
			),
		);

		$findings = WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', $schema );

		$this->assertContains( WP_MCP_AI_Tool_Schema_Auditor::FINDING_ENUM_TYPE_MISMATCH, $this->codes( $findings ) );
	}

	/**
	 * A well-typed enum is clean.
	 */
	public function test_matching_enum_is_clean() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'mode' => array(
					'type' => 'string',
					'enum' => array( 'fast', 'slow' ),
				),
			),
		);

		$this->assertSame( array(), WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', $schema ) );
	}

	/**
	 * Non-schema input degrades to no findings instead of throwing.
	 */
	public function test_non_schema_input_is_tolerated() {
		$this->assertSame( array(), WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', array() ) );
		$this->assertSame( array(), WP_MCP_AI_Tool_Schema_Auditor::audit_contract( 'tool', 'nope' ) );
	}

	/**
	 * CI gate: no registered tool may violate its own schema contract.
	 *
	 * Measured clean on 446 registered tools (2026-11-08). A failure here
	 * means a tool declares a `required` key it never defines, types an
	 * array without `items`, writes `minimum > maximum`, or ships an enum
	 * value that contradicts its `type` — all authoring bugs that otherwise
	 * surface as confusing runtime validation.
	 *
	 * Fix the tool schema rather than deleting this test.
	 */
	public function test_registry_schema_contract_is_clean() {
		$findings = WP_MCP_AI_Tool_Schema_Auditor::audit_registry_contract();

		$report = array();
		foreach ( $findings as $slug => $list ) {
			$report[] = $slug . ': ' . implode( ', ', array_unique( array_column( $list, 'code' ) ) );
		}

		$this->assertSame(
			array(),
			$findings,
			"Tool schema contract violations:\n  " . implode( "\n  ", $report )
		);
	}

	/**
	 * The registry-wide pass returns a slug-keyed map and never throws.
	 */
	public function test_registry_contract_audit_returns_map() {
		$findings = WP_MCP_AI_Tool_Schema_Auditor::audit_registry_contract();

		$this->assertIsArray( $findings );

		foreach ( $findings as $slug => $slug_findings ) {
			$this->assertIsString( $slug );
			$this->assertIsArray( $slug_findings );
			$this->assertNotEmpty( $slug_findings );
		}
	}
}
