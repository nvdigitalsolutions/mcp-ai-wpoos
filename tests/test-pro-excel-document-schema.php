<?php
/**
 * Tests for WP_MCP_AI_Tool_Pro_Excel_Document schema validation.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Pro Excel Document tool schema tests.
 *
 * @group tools
 * @group pro
 * @group excel-document
 */
class WP_MCP_AI_Tool_Pro_Excel_Document_Schema_Tests extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Pro_Excel_Document
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Check if the pro addon file exists.
		$pro_file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-pro-excel-document.php';
		if ( ! file_exists( $pro_file ) ) {
			$this->markTestSkipped( 'Pro Excel Document tool is not available.' );
		}

		// Load the tool class.
		require_once $pro_file;

		$this->tool = new WP_MCP_AI_Tool_Pro_Excel_Document();
	}

	/**
	 * Test that the schema does not have empty array items.
	 * This was causing "[] is not of type 'object', 'boolean'" error with OpenAI.
	 */
	public function test_schema_has_no_empty_array_items() {
		$schema = $this->tool->get_parameters_schema();

		// Check the 'data' parameter.
		$this->assertArrayHasKey( 'data', $schema['properties'] );
		$data_items = $schema['properties']['data']['items'];
		$this->assertIsArray( $data_items );
		$this->assertArrayHasKey( 'items', $data_items );

		// The items should not be an empty array.
		$this->assertNotEmpty( $data_items['items'], 'data.items.items should not be empty' );

		// Check that it has proper type definition (anyOf for mixed types).
		$this->assertArrayHasKey( 'anyOf', $data_items['items'], 'data.items.items should have anyOf for mixed types' );
	}

	/**
	 * Test that the sheets parameter has properly defined array items.
	 */
	public function test_sheets_schema_has_proper_array_items() {
		$schema = $this->tool->get_parameters_schema();

		// Check the 'sheets' parameter.
		$this->assertArrayHasKey( 'sheets', $schema['properties'] );
		$sheets_items = $schema['properties']['sheets']['items'];
		$this->assertIsArray( $sheets_items );
		$this->assertArrayHasKey( 'properties', $sheets_items );
		$this->assertArrayHasKey( 'data', $sheets_items['properties'] );

		// Check nested data array structure.
		$sheet_data = $sheets_items['properties']['data'];
		$this->assertArrayHasKey( 'items', $sheet_data );

		$sheet_data_items = $sheet_data['items'];
		$this->assertArrayHasKey( 'items', $sheet_data_items );

		// The items should not be an empty array.
		$this->assertNotEmpty( $sheet_data_items['items'], 'sheets.items.data.items.items should not be empty' );

		// Check that it has proper type definition (anyOf for mixed types).
		$this->assertArrayHasKey( 'anyOf', $sheet_data_items['items'], 'sheets.items.data.items.items should have anyOf for mixed types' );
	}

	/**
	 * Test that anyOf contains valid types for cell values.
	 */
	public function test_anyof_contains_valid_cell_types() {
		$schema = $this->tool->get_parameters_schema();

		// Check data parameter anyOf.
		$data_anyof = $schema['properties']['data']['items']['items']['anyOf'];
		$this->assertIsArray( $data_anyof );
		$this->assertGreaterThan( 0, count( $data_anyof ) );

		// Check that anyOf includes common cell value types.
		$types = array();
		foreach ( $data_anyof as $type_def ) {
			$this->assertArrayHasKey( 'type', $type_def );
			$types[] = $type_def['type'];
		}

		$this->assertContains( 'string', $types, 'anyOf should include string type' );
		$this->assertContains( 'number', $types, 'anyOf should include number type' );
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
	 * Test that operation is required.
	 */
	public function test_operation_is_required() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'operation', $schema['required'] );
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

		// Check if this is an 'items' key with an empty array value.
		if ( isset( $value['items'] ) && is_array( $value['items'] ) ) {
			$items = $value['items'];

			// Empty array items are invalid for OpenAI.
			if ( empty( $items ) ) {
				$this->fail( "Found empty items array at path: $path" );
			}

			// Recursively validate items.
			$this->validate_no_empty_items_recursive( $items, $path . '.items' );
		}

		// Recursively check all other array elements.
		foreach ( $value as $key => $sub_value ) {
			if ( is_array( $sub_value ) ) {
				$this->validate_no_empty_items_recursive( $sub_value, $path . '.' . $key );
			}
		}
	}
}
