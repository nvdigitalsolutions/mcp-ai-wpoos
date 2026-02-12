<?php
/**
 * Test Client Semantic Search Schema
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for the client_semantic_search tool schema.
 */
class Test_WP_MCP_AI_Tool_Client_Semantic_Search_Schema extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Client_Semantic_Search
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-client-semantic-search.php';
		$this->tool = new WP_MCP_AI_Tool_Client_Semantic_Search();
	}

	/**
	 * Test that the parameter schema is properly structured for OpenAI.
	 */
	public function test_parameters_schema_structure() {
		$schema = $this->tool->get_parameters_schema();

		// Check basic structure.
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check text parameter exists.
		$this->assertArrayHasKey( 'text', $schema['properties'] );
	}

	/**
	 * Test that the text parameter uses oneOf for union types.
	 */
	public function test_text_parameter_uses_oneof() {
		$schema = $this->tool->get_parameters_schema();
		$text_schema = $schema['properties']['text'];

		// The text parameter should use oneOf, not a type array.
		$this->assertArrayHasKey( 'oneOf', $text_schema );
		$this->assertIsArray( $text_schema['oneOf'] );
		$this->assertCount( 2, $text_schema['oneOf'] );
	}

	/**
	 * Test that string type is properly defined.
	 */
	public function test_string_type_defined() {
		$schema = $this->tool->get_parameters_schema();
		$text_schema = $schema['properties']['text'];
		$oneof_schemas = $text_schema['oneOf'];

		// Find the string schema.
		$string_schema = null;
		foreach ( $oneof_schemas as $oneof_schema ) {
			if ( isset( $oneof_schema['type'] ) && 'string' === $oneof_schema['type'] ) {
				$string_schema = $oneof_schema;
				break;
			}
		}

		$this->assertNotNull( $string_schema, 'String type should be defined in oneOf' );
		$this->assertEquals( 'string', $string_schema['type'] );
	}

	/**
	 * Test that array type includes items definition.
	 */
	public function test_array_type_has_items() {
		$schema = $this->tool->get_parameters_schema();
		$text_schema = $schema['properties']['text'];
		$oneof_schemas = $text_schema['oneOf'];

		// Find the array schema.
		$array_schema = null;
		foreach ( $oneof_schemas as $oneof_schema ) {
			if ( isset( $oneof_schema['type'] ) && 'array' === $oneof_schema['type'] ) {
				$array_schema = $oneof_schema;
				break;
			}
		}

		$this->assertNotNull( $array_schema, 'Array type should be defined in oneOf' );
		$this->assertEquals( 'array', $array_schema['type'] );

		// CRITICAL: Array type must have items defined for OpenAI schema validation.
		$this->assertArrayHasKey( 'items', $array_schema, 'Array type must have items field for OpenAI' );
		$this->assertIsArray( $array_schema['items'] );
		$this->assertArrayHasKey( 'type', $array_schema['items'] );
		$this->assertEquals( 'string', $array_schema['items']['type'] );
	}

	/**
	 * Test that the schema has a description.
	 */
	public function test_text_parameter_has_description() {
		$schema = $this->tool->get_parameters_schema();
		$text_schema = $schema['properties']['text'];

		$this->assertArrayHasKey( 'description', $text_schema );
		$this->assertNotEmpty( $text_schema['description'] );
	}

	/**
	 * Test that text is a required parameter.
	 */
	public function test_text_is_required() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertContains( 'text', $schema['required'] );
	}
}
