<?php
/**
 * Tests for ChatOrchestrator tool schema normalization.
 *
 * Validates that malformed tool parameter schemas produced by legacy WordPress
 * tools (specifically a boolean `required` flag inside an individual property)
 * are normalized into valid JSON Schema accepted by strict providers such as
 * DeepSeek, which reject `"required": true` inside a property with the error
 * "true is not of type 'array'".
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Tests the private ChatOrchestrator::normalizeToolSchema() via reflection.
 */
class WP_MCP_AI_Chat_Orchestrator_Schema_Normalization_Test extends WP_UnitTestCase {

	/**
	 * Fully-qualified ChatOrchestrator class name under test.
	 */
	private const ORCHESTRATOR = 'Nvoos\\Core\\Application\\Chat\\ChatOrchestrator';

	/**
	 * Invoke the private static normalizeToolSchema() method.
	 *
	 * @param array $schema Schema to normalize.
	 * @return array Normalized schema.
	 */
	private function normalize( array $schema ) {
		if ( ! class_exists( self::ORCHESTRATOR ) ) {
			$this->markTestSkipped( 'ChatOrchestrator not available (lib/ absent).' );
		}

		$method = new ReflectionMethod( self::ORCHESTRATOR, 'normalizeToolSchema' );
		$method->setAccessible( true );

		return $method->invoke( null, $schema );
	}

	/**
	 * A boolean `required` inside a property is hoisted into the top-level array.
	 */
	public function test_hoists_boolean_required_from_property_to_top_level() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'workflow_results' => array(
					'type'        => 'object',
					'description' => 'Workflow execution results object with metrics.',
					'required'    => true,
				),
				'chart_type'       => array(
					'type'    => 'string',
					'enum'    => array( 'performance', 'completion', 'timing', 'all' ),
					'default' => 'performance',
				),
			),
			'required'   => array( 'workflow_results' ),
		);

		$normalized = $this->normalize( $schema );

		// The illegal per-property flag must be gone.
		$this->assertArrayNotHasKey( 'required', $normalized['properties']['workflow_results'] );

		// The intended requirement is preserved at the top level.
		$this->assertArrayHasKey( 'required', $normalized );
		$this->assertIsArray( $normalized['required'] );
		$this->assertContains( 'workflow_results', $normalized['required'] );
	}

	/**
	 * A boolean `required` produces a top-level array even when none existed.
	 */
	public function test_hoists_required_when_no_top_level_required_present() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'action' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);

		$normalized = $this->normalize( $schema );

		$this->assertArrayNotHasKey( 'required', $normalized['properties']['action'] );
		$this->assertArrayHasKey( 'required', $normalized );
		$this->assertSame( array( 'action' ), $normalized['required'] );
	}

	/**
	 * A false boolean `required` is dropped without marking the field required.
	 */
	public function test_ignores_false_required_flag() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'optional' => array(
					'type'     => 'string',
					'required' => false,
				),
			),
		);

		$normalized = $this->normalize( $schema );

		$this->assertArrayNotHasKey( 'required', $normalized['properties']['optional'] );
		$this->assertArrayNotHasKey( 'required', $normalized );
	}

	/**
	 * Non-boolean per-property `required` (already an array) is left untouched
	 * so we never mangle a property that legitimately nests a `required` list.
	 */
	public function test_leaves_array_required_in_property_untouched() {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'nested' => array(
					'type'       => 'object',
					'properties' => array(
						'x' => array( 'type' => 'string' ),
					),
					'required'   => array( 'x' ),
				),
			),
		);

		$normalized = $this->normalize( $schema );

		// The nested object's own `required` list must be preserved verbatim.
		$this->assertArrayHasKey( 'required', $normalized['properties']['nested'] );
		$this->assertSame( array( 'x' ), $normalized['properties']['nested']['required'] );
	}
}
