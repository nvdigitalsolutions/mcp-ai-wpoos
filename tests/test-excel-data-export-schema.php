<?php
/**
 * Tests for WP_MCP_AI_Tool_Excel_Data_Export schema validation.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Excel Data Export tool schema tests.
 *
 * @group tools
 * @group pro
 * @group excel-export
 */
class WP_MCP_AI_Tool_Excel_Data_Export_Schema_Tests extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Excel_Data_Export
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Check if the pro addon file exists.
		$pro_file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-excel-data-export.php';
		if ( ! file_exists( $pro_file ) ) {
			$this->markTestSkipped( 'Excel Data Export tool is not available.' );
		}

		// Load required traits.
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

		// Load the tool class.
		require_once $pro_file;

		$this->tool = new WP_MCP_AI_Tool_Excel_Data_Export();
	}

	/**
	 * Test that the schema has properly defined nested array items with anyOf.
	 * This fixes the "array schema missing items" error with OpenAI.
	 */
	public function test_schema_has_proper_nested_array_items() {
		$schema = $this->tool->get_parameters_schema();

		// Check the 'data' parameter exists.
		$this->assertArrayHasKey( 'data', $schema['properties'] );

		// Check data is an array.
		$this->assertEquals( 'array', $schema['properties']['data']['type'] );

		// Check data has items (array of arrays).
		$this->assertArrayHasKey( 'items', $schema['properties']['data'] );
		$data_items = $schema['properties']['data']['items'];

		// Check inner array has type.
		$this->assertArrayHasKey( 'type', $data_items );
		$this->assertEquals( 'array', $data_items['type'] );

		// Check inner array has items with anyOf.
		$this->assertArrayHasKey( 'items', $data_items );
		$inner_items = $data_items['items'];
		$this->assertArrayHasKey( 'anyOf', $inner_items, 'data.items.items should have anyOf for mixed types' );

		// Verify anyOf contains proper types.
		$anyof = $inner_items['anyOf'];
		$this->assertIsArray( $anyof );
		$this->assertGreaterThan( 0, count( $anyof ) );

		// Extract types from anyOf.
		$types = array();
		foreach ( $anyof as $type_def ) {
			$this->assertArrayHasKey( 'type', $type_def );
			$types[] = $type_def['type'];
		}

		// Verify expected types are present.
		$this->assertContains( 'string', $types, 'anyOf should include string type for text cells' );
		$this->assertContains( 'number', $types, 'anyOf should include number type for numeric cells' );
		$this->assertContains( 'boolean', $types, 'anyOf should include boolean type' );
		$this->assertContains( 'null', $types, 'anyOf should include null type for empty cells' );
	}

	/**
	 * Test basic schema structure is correct.
	 */
	public function test_parameter_schema_structure() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
	}

	/**
	 * Test that data is required.
	 */
	public function test_data_is_required() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'data', $schema['required'] );
	}

	/**
	 * Test that headers array has proper string items.
	 */
	public function test_headers_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'headers', $schema['properties'] );
		$headers = $schema['properties']['headers'];

		$this->assertEquals( 'array', $headers['type'] );
		$this->assertArrayHasKey( 'items', $headers );
		$this->assertEquals( 'string', $headers['items']['type'] );
	}

	/**
	 * Validate the entire schema recursively for OpenAI compatibility.
	 * Ensures no empty array items exist anywhere in the schema.
	 */
	public function test_schema_has_no_empty_arrays_recursive() {
		$schema = $this->tool->get_parameters_schema();

		$this->validate_no_empty_items_recursive( $schema, 'root' );
	}

	/**
	 * Helper method to recursively validate schema structure.
	 *
	 * @param mixed  $value Current value being validated.
	 * @param string $path  Path to current value for error messages.
	 */
	private function validate_no_empty_items_recursive( $value, $path ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		// Check if this is an 'items' key with value.
		if ( isset( $value['items'] ) ) {
			$items = $value['items'];

			// Empty array items are invalid for OpenAI (must have type or anyOf).
			if ( is_array( $items ) && empty( $items ) ) {
				$this->fail( "Found empty items array at path: $path - OpenAI requires items to have type or anyOf" );
			}

			// Recursively validate items if it's an array.
			if ( is_array( $items ) ) {
				$this->validate_no_empty_items_recursive( $items, $path . '.items' );
			}
		}

		// Recursively check all other array elements.
		foreach ( $value as $key => $sub_value ) {
			if ( is_array( $sub_value ) ) {
				$this->validate_no_empty_items_recursive( $sub_value, $path . '.' . $key );
			}
		}
	}
}
