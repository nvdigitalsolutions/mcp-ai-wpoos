<?php
/**
 * Tests for WP_MCP_AI_Tool_Import_Products_From_Excel class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Import Products from Excel tool.
 *
 * @group tools
 * @group pro
 * @group excel
 * @group import
 */
class WP_MCP_AI_Tool_Import_Products_From_Excel_Tests extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Import_Products_From_Excel
	 */
	protected $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Test file path.
	 *
	 * @var string
	 */
	protected $test_file_path;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable Pro features.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_regulatory_registration_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create test user with appropriate capabilities.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );

		// Load the tool class.
		$tool_file = WP_MCP_AI_PATH . 'addons/pro/includes/tools/regulatory-registration/class-wp-mcp-ai-tool-import-products-from-excel.php';
		if ( file_exists( $tool_file ) ) {
			require_once $tool_file;
			$this->tool = new WP_MCP_AI_Tool_Import_Products_From_Excel();
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test file if it exists.
		if ( $this->test_file_path && file_exists( $this->test_file_path ) ) {
			// Using unlink here is acceptable for test cleanup of temporary files.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $this->test_file_path );
		}

		parent::tearDown();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		$this->assertEquals( 'import_products_from_excel', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Excel', $this->tool->get_name() );
		$this->assertStringContainsString( 'XLSX', $this->tool->get_description() );
	}

	/**
	 * Test parameter schema structure.
	 */
	public function test_parameter_schema() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'file_path', $schema['properties'] );
		$this->assertArrayHasKey( 'field_mapping', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'file_path', $schema['required'] );
		$this->assertContains( 'field_mapping', $schema['required'] );
	}

	/**
	 * Test regulatory field mappings in schema.
	 */
	public function test_regulatory_fields_in_schema() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		$schema         = $this->tool->get_parameters_schema();
		$field_mapping  = $schema['properties']['field_mapping'];
		$mapping_fields = $field_mapping['properties'];

		// Check for key regulatory fields.
		$required_fields = array(
			'supplier_reference',
			'item_name',
			'item_group',
			'brand',
			'loa',
			'manufacturer_declaration',
			'art_works',
			'sample_import_license',
			'formula_certificate',
			'certificate_of_analysis',
			'free_sale_certificate',
			'cos_no',
			'registration_certificate_status',
		);

		foreach ( $required_fields as $field ) {
			$this->assertArrayHasKey( $field, $mapping_fields, "Field '$field' should be in schema" );
		}
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'database-write', $flags );
		$this->assertContains( 'file-upload', $flags );
		$this->assertContains( 'destructive', $flags );
	}

	/**
	 * Test missing file path error.
	 */
	public function test_missing_file_path_error() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		$result = $this->tool->execute(
			array(
				'field_mapping' => array( 'item_name' => 'A' ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_param', $result->get_error_code() );
	}

	/**
	 * Test missing field mapping error.
	 */
	public function test_missing_field_mapping_error() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		$result = $this->tool->execute(
			array(
				'file_path' => '/tmp/test.xlsx',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_param', $result->get_error_code() );
	}

	/**
	 * Test file not found error.
	 */
	public function test_file_not_found_error() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		$result = $this->tool->execute(
			array(
				'file_path'     => '/tmp/nonexistent-file.xlsx',
				'field_mapping' => array( 'item_name' => 'A' ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_file_not_found', $result->get_error_code() );
	}

	/**
	 * Test invalid file extension error.
	 */
	public function test_invalid_file_extension_error() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		// Create a temporary text file.
		$test_file = tempnam( sys_get_temp_dir(), 'test' ) . '.txt';
		// Using file_put_contents here is acceptable for creating test files.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $test_file, 'test content' );
		$this->test_file_path = $test_file;

		$result = $this->tool->execute(
			array(
				'file_path'     => $test_file,
				'field_mapping' => array( 'item_name' => 'A' ),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_file', $result->get_error_code() );
	}

	/**
	 * Test permission check.
	 */
	public function test_permission_check() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		// Create user without edit_posts capability.
		$user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		$result = $this->tool->execute(
			array(
				'file_path'     => '/tmp/test.xlsx',
				'field_mapping' => array( 'item_name' => 'A' ),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test PhpSpreadsheet dependency check.
	 */
	public function test_phpspreadsheet_dependency() {
		// PhpSpreadsheet should be available after composer install.
		$this->assertTrue(
			class_exists( 'PhpOffice\PhpSpreadsheet\IOFactory' ),
			'PhpSpreadsheet should be installed via composer'
		);
	}

	/**
	 * Test tool availability check.
	 */
	public function test_tool_availability() {
		// Tool should be available when regulatory toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_regulatory_registration_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		$available = WP_MCP_AI_Tool_Import_Products_From_Excel::is_available();
		$this->assertTrue( $available, 'Tool should be available when regulatory toolkit is enabled' );

		// Tool should not be available when disabled.
		$settings['enable_regulatory_registration_toolkit'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		$available = WP_MCP_AI_Tool_Import_Products_From_Excel::is_available();
		$this->assertFalse( $available, 'Tool should not be available when regulatory toolkit is disabled' );
	}

	/**
	 * Helper to create a simple test Excel file.
	 *
	 * @return string Path to created file.
	 */
	private function create_test_excel_file() {
		if ( ! class_exists( 'PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			$this->markTestSkipped( 'PhpSpreadsheet not available' );
		}

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();

		// Set headers.
		$sheet->setCellValue( 'A1', 'Supplier Reference' );
		$sheet->setCellValue( 'B1', 'Item Name' );
		$sheet->setCellValue( 'C1', 'Brand' );
		$sheet->setCellValue( 'D1', 'LOA' );
		$sheet->setCellValue( 'E1', 'COS No.' );

		// Add test data.
		$sheet->setCellValue( 'A2', 'TEST001' );
		$sheet->setCellValue( 'B2', 'Test Product 1' );
		$sheet->setCellValue( 'C2', 'Test Brand' );
		$sheet->setCellValue( 'D2', 'Available' );
		$sheet->setCellValue( 'E2', 'COS/12345' );

		$sheet->setCellValue( 'A3', 'TEST002' );
		$sheet->setCellValue( 'B3', 'Test Product 2' );
		$sheet->setCellValue( 'C3', 'Test Brand' );
		$sheet->setCellValue( 'D3', 'Available' );
		$sheet->setCellValue( 'E3', 'COS/12346' );

		// Write to temporary file.
		$temp_file = tempnam( sys_get_temp_dir(), 'test_excel_' ) . '.xlsx';
		$writer    = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
		$writer->save( $temp_file );

		$this->test_file_path = $temp_file;
		return $temp_file;
	}

	/**
	 * Test successful import with real Excel file.
	 */
	public function test_successful_import() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		if ( ! class_exists( 'PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			$this->markTestSkipped( 'PhpSpreadsheet not available' );
		}

		$test_file = $this->create_test_excel_file();

		$result = $this->tool->execute(
			array(
				'file_path'     => $test_file,
				'field_mapping' => array(
					'supplier_reference' => 'A',
					'item_name'          => 'B',
					'brand'              => 'C',
					'loa'                => 'D',
					'cos_no'             => 'E',
				),
				'start_row'     => 2,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 2, $result['imported'] );
		$this->assertEquals( 0, $result['skipped'] );
		$this->assertEmpty( $result['errors'] );

		// Verify products were created.
		$products = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_product',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$this->assertCount( 2, $products );

		// Verify metadata.
		$product_id = $products[0];
		$this->assertEquals( 'TEST002', get_post_meta( $product_id, 'supplier_reference', true ) );
		$this->assertEquals( 'Available', get_post_meta( $product_id, 'loa', true ) );
		$this->assertEquals( 'COS/12346', get_post_meta( $product_id, 'cos_no', true ) );
	}

	/**
	 * Test duplicate detection.
	 */
	public function test_duplicate_detection() {
		if ( ! $this->tool ) {
			$this->markTestSkipped( 'Tool class not available' );
		}

		if ( ! class_exists( 'PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			$this->markTestSkipped( 'PhpSpreadsheet not available' );
		}

		// Create a product first.
		$product_id = wp_insert_post(
			array(
				'post_title'  => 'Existing Product',
				'post_type'   => 'mcp_ai_reg_product',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $product_id, 'supplier_reference', 'TEST001' );

		$test_file = $this->create_test_excel_file();

		$result = $this->tool->execute(
			array(
				'file_path'       => $test_file,
				'field_mapping'   => array(
					'supplier_reference' => 'A',
					'item_name'          => 'B',
					'brand'              => 'C',
				),
				'start_row'       => 2,
				'skip_duplicates' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 1, $result['imported'] ); // Only TEST002 should be imported.
		$this->assertEquals( 1, $result['skipped'] );  // TEST001 should be skipped.
	}
}
