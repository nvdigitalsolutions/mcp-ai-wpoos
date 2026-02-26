<?php
/**
 * Tests for OpenAI schema sanitization functionality.
 *
 * @package WP_MCP_AI
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Test helper class to access protected methods.
 */
class WP_MCP_AI_OpenAI_Client_Schema_Test_Helper extends WP_MCP_AI_OpenAI_Client {
	/**
	 * Make sanitize_parameters_for_openai public for testing.
	 *
	 * @param array  $schema     Schema to sanitize.
	 * @param string $parent_key Parent key context.
	 * @return array
	 */
	public function public_sanitize_parameters_for_openai( array $schema, $parent_key = '' ) {
		return $this->sanitize_parameters_for_openai( $schema, $parent_key );
	}

	/**
	 * Make normalise_tools_for_payload public for testing.
	 *
	 * @param array $tools Tools to normalize.
	 * @return array
	 */
	public function public_normalise_tools_for_payload( array $tools ) {
		return $this->normalise_tools_for_payload( $tools );
	}
}

/**
 * Tests for OpenAI schema sanitization.
 */
class WP_MCP_AI_OpenAI_Schema_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Test that allOf is removed from root level.
	 */
	public function test_removes_allof_from_root_level() {
		$client = new WP_MCP_AI_OpenAI_Client_Schema_Test_Helper();

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'name' => array(
					'type' => 'string',
				),
			),
			'allOf'      => array(
				array(
					'required' => array( 'name' ),
				),
			),
		);

		$sanitized = $client->public_sanitize_parameters_for_openai( $schema );

		$this->assertArrayNotHasKey( 'allOf', $sanitized );
		$this->assertArrayHasKey( 'type', $sanitized );
		$this->assertSame( 'object', $sanitized['type'] );
		$this->assertArrayHasKey( 'properties', $sanitized );
	}

	/**
	 * Test that anyOf is removed from root level.
	 */
	public function test_removes_anyof_from_root_level() {
		$client = new WP_MCP_AI_OpenAI_Client_Schema_Test_Helper();

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'name' => array(
					'type' => 'string',
				),
			),
			'anyOf'      => array(
				array(
					'required' => array( 'name' ),
				),
			),
		);

		$sanitized = $client->public_sanitize_parameters_for_openai( $schema );

		$this->assertArrayNotHasKey( 'anyOf', $sanitized );
		$this->assertArrayHasKey( 'type', $sanitized );
		$this->assertSame( 'object', $sanitized['type'] );
	}

	/**
	 * Test that oneOf is removed from root level.
	 */
	public function test_removes_oneof_from_root_level() {
		$client = new WP_MCP_AI_OpenAI_Client_Schema_Test_Helper();

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'name' => array(
					'type' => 'string',
				),
			),
			'oneOf'      => array(
				array(
					'required' => array( 'name' ),
				),
			),
		);

		$sanitized = $client->public_sanitize_parameters_for_openai( $schema );

		$this->assertArrayNotHasKey( 'oneOf', $sanitized );
	}

	/**
	 * Test that 'not' is removed from root level.
	 */
	public function test_removes_not_from_root_level() {
		$client = new WP_MCP_AI_OpenAI_Client_Schema_Test_Helper();

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'name' => array(
					'type' => 'string',
				),
			),
			'not'        => array(
				'required' => array( 'invalid_field' ),
			),
		);

		$sanitized = $client->public_sanitize_parameters_for_openai( $schema );

		$this->assertArrayNotHasKey( 'not', $sanitized );
	}

	/**
	 * Test the get_import_duty schema specifically.
	 */
	public function test_sanitizes_get_import_duty_schema() {
		$client = new WP_MCP_AI_OpenAI_Client_Schema_Test_Helper();

		// Schema matching the get_import_duty tool.
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'country'     => array(
					'type'        => 'string',
					'description' => 'Destination country',
					'enum'        => array( 'united_states', 'jamaica', 'sri_lanka' ),
				),
				'hs_code'     => array(
					'type'        => 'string',
					'description' => 'HS or HTS code',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Product description',
				),
				'max_results' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10,
					'default' => 5,
				),
			),
			'required'             => array( 'country' ),
			'additionalProperties' => false,
			'allOf'                => array(
				array(
					'anyOf' => array(
						array(
							'required' => array( 'hs_code' ),
						),
						array(
							'required' => array( 'description' ),
						),
					),
				),
			),
		);

		$sanitized = $client->public_sanitize_parameters_for_openai( $schema );

		// Verify allOf was removed.
		$this->assertArrayNotHasKey( 'allOf', $sanitized, 'allOf should be removed from root level' );

		// Verify type is object.
		$this->assertArrayHasKey( 'type', $sanitized );
		$this->assertSame( 'object', $sanitized['type'] );

		// Verify properties are preserved.
		$this->assertArrayHasKey( 'properties', $sanitized );
		$this->assertCount( 4, $sanitized['properties'] );
		$this->assertArrayHasKey( 'country', $sanitized['properties'] );
		$this->assertArrayHasKey( 'hs_code', $sanitized['properties'] );
		$this->assertArrayHasKey( 'description', $sanitized['properties'] );
		$this->assertArrayHasKey( 'max_results', $sanitized['properties'] );

		// Verify enum in nested property is preserved.
		$this->assertArrayHasKey( 'enum', $sanitized['properties']['country'] );
		$this->assertCount( 3, $sanitized['properties']['country']['enum'] );

		// Verify required is preserved.
		$this->assertArrayHasKey( 'required', $sanitized );
		$this->assertContains( 'country', $sanitized['required'] );

		// Verify additionalProperties is preserved.
		$this->assertArrayHasKey( 'additionalProperties', $sanitized );
		$this->assertFalse( $sanitized['additionalProperties'] );
	}

	/**
	 * Test that type 'object' is added if missing at root level.
	 */
	public function test_adds_type_object_if_missing() {
		$client = new WP_MCP_AI_OpenAI_Client_Schema_Test_Helper();

		$schema = array(
			'properties' => array(
				'name' => array(
					'type' => 'string',
				),
			),
		);

		$sanitized = $client->public_sanitize_parameters_for_openai( $schema );

		$this->assertArrayHasKey( 'type', $sanitized );
		$this->assertSame( 'object', $sanitized['type'] );
	}

	/**
	 * Test that composition keywords are preserved in nested schemas.
	 */
	public function test_preserves_composition_keywords_in_nested_schemas() {
		$client = new WP_MCP_AI_OpenAI_Client_Schema_Test_Helper();

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'field' => array(
					'anyOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'number' ),
					),
				),
			),
			// Add anyOf at root to test it gets removed.
			'anyOf'      => array(
				array( 'required' => array( 'field' ) ),
			),
		);

		$sanitized = $client->public_sanitize_parameters_for_openai( $schema );

		// At root level, anyOf should be removed.
		$this->assertArrayNotHasKey( 'anyOf', $sanitized, 'anyOf should be removed from root level' );

		// But in nested property, anyOf should remain (OpenAI allows it there).
		$this->assertArrayHasKey( 'anyOf', $sanitized['properties']['field'], 'anyOf should be preserved in nested property' );
		$this->assertCount( 2, $sanitized['properties']['field']['anyOf'], 'nested anyOf options should be intact' );
	}

	/**
	 * Test that tools are properly normalized with schema sanitization.
	 */
	public function test_normalises_tools_with_schema_sanitization() {
		$client = new WP_MCP_AI_OpenAI_Client_Schema_Test_Helper();

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'test_tool',
					'description' => 'Test tool',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(
							'param1' => array(
								'type' => 'string',
							),
						),
						'allOf'      => array(
							array(
								'required' => array( 'param1' ),
							),
						),
					),
				),
			),
		);

		$normalized = $client->public_normalise_tools_for_payload( $tools );

		$this->assertCount( 1, $normalized );
		$this->assertArrayHasKey( 'function', $normalized[0] );
		$this->assertArrayHasKey( 'parameters', $normalized[0]['function'] );

		// Verify allOf was removed from the parameters schema.
		$this->assertArrayNotHasKey( 'allOf', $normalized[0]['function']['parameters'] );

		// Verify properties are preserved.
		$this->assertArrayHasKey( 'properties', $normalized[0]['function']['parameters'] );
	}
}
