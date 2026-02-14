<?php
/**
 * Tests for Document Generation Toolkit Functions.
 *
 * Comprehensive tests for document generation tools that were previously untested.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Test Document Generation Toolkit class.
 *
 * @group tools
 * @group pro
 * @group document-generation
 */
class Test_WP_MCP_AI_Document_Generation_Toolkit extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * List of document generation tools to test.
	 *
	 * @var array
	 */
	protected $tools_list = array(
		'WP_MCP_AI_Tool_Generate_PDF',
		'WP_MCP_AI_Tool_Generate_Word',
		'WP_MCP_AI_Tool_Generate_Excel',
		'WP_MCP_AI_Tool_Pro_PDF',
		'WP_MCP_AI_Tool_Pro_Word',
		'WP_MCP_AI_Tool_Generate_Invoice_PDF',
		'WP_MCP_AI_Tool_HTML_To_PDF',
		'WP_MCP_AI_Tool_Excel_Data_Import',
		'WP_MCP_AI_Tool_OCR_PDF_Text',
		'WP_MCP_AI_Tool_Pro_Document_OCR',
	);

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		// Create test user with appropriate capabilities.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		wp_set_current_user( $this->user_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Helper method to convert class name to file path.
	 *
	 * @param string $tool_class Tool class name.
	 * @return string File path to the tool class.
	 */
	protected function get_tool_file_path( $tool_class ) {
		return WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-' . strtolower( str_replace( '_', '-', $tool_class ) ) . '.php';
	}

	// ============================================================================
	// Generate PDF Tool Tests
	// ============================================================================

	/**
	 * Test generate_pdf tool exists and loads.
	 */
	public function test_generate_pdf_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-pdf.php';
		$this->assertFileExists( $tool_file, 'Generate PDF tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Generate_PDF' ), 'Generate PDF tool class should exist' );
	}

	/**
	 * Test generate_pdf tool metadata.
	 */
	public function test_generate_pdf_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-pdf.php';

		$tool = new WP_MCP_AI_Tool_Generate_PDF();

		$this->assertEquals( 'generate_pdf', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$this->assertStringContainsString( 'PDF', $tool->get_name() );
	}

	/**
	 * Test generate_pdf parameter schema.
	 */
	public function test_generate_pdf_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-pdf.php';

		$tool   = new WP_MCP_AI_Tool_Generate_PDF();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'content', $schema['required'] );
	}

	// ============================================================================
	// Generate Word Tool Tests
	// ============================================================================

	/**
	 * Test generate_word tool exists and loads.
	 */
	public function test_generate_word_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-word.php';
		$this->assertFileExists( $tool_file, 'Generate Word tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Generate_Word' ), 'Generate Word tool class should exist' );
	}

	/**
	 * Test generate_word tool metadata.
	 */
	public function test_generate_word_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-word.php';

		$tool = new WP_MCP_AI_Tool_Generate_Word();

		$this->assertEquals( 'generate_word', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$this->assertStringContainsString( 'Word', $tool->get_name() );
	}

	/**
	 * Test generate_word parameter schema.
	 */
	public function test_generate_word_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-word.php';

		$tool   = new WP_MCP_AI_Tool_Generate_Word();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'content', $schema['required'] );
	}

	// ============================================================================
	// Generate Excel Tool Tests
	// ============================================================================

	/**
	 * Test generate_excel tool exists and loads.
	 */
	public function test_generate_excel_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-excel.php';
		$this->assertFileExists( $tool_file, 'Generate Excel tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Generate_Excel' ), 'Generate Excel tool class should exist' );
	}

	/**
	 * Test generate_excel tool metadata.
	 */
	public function test_generate_excel_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-excel.php';

		$tool = new WP_MCP_AI_Tool_Generate_Excel();

		$this->assertEquals( 'generate_excel', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$this->assertStringContainsString( 'Excel', $tool->get_name() );
	}

	/**
	 * Test generate_excel parameter schema.
	 */
	public function test_generate_excel_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-excel.php';

		$tool   = new WP_MCP_AI_Tool_Generate_Excel();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'data', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'data', $schema['required'] );
	}

	// ============================================================================
	// Pro PDF Tool Tests
	// ============================================================================

	/**
	 * Test pro_pdf tool exists and loads.
	 */
	public function test_pro_pdf_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php';
		$this->assertFileExists( $tool_file, 'Pro PDF tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Pro_PDF' ), 'Pro PDF tool class should exist' );
	}

	/**
	 * Test pro_pdf tool metadata.
	 */
	public function test_pro_pdf_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php';

		$tool = new WP_MCP_AI_Tool_Pro_PDF();

		$this->assertEquals( 'pro_pdf_document', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test pro_pdf parameter schema.
	 */
	public function test_pro_pdf_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php';

		$tool   = new WP_MCP_AI_Tool_Pro_PDF();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'operation', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'operation', $schema['required'] );
	}

	/**
	 * Test pro_pdf capability flags.
	 */
	public function test_pro_pdf_capability_flags() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php';

		$tool = new WP_MCP_AI_Tool_Pro_PDF();

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertContains( 'pro', $flags );
		}
	}

	// ============================================================================
	// Pro Word Tool Tests
	// ============================================================================

	/**
	 * Test pro_word tool exists and loads.
	 */
	public function test_pro_word_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php';
		$this->assertFileExists( $tool_file, 'Pro Word tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Pro_Word' ), 'Pro Word tool class should exist' );
	}

	/**
	 * Test pro_word tool metadata.
	 */
	public function test_pro_word_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php';

		$tool = new WP_MCP_AI_Tool_Pro_Word();

		$this->assertEquals( 'pro_word_document', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test pro_word parameter schema.
	 */
	public function test_pro_word_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php';

		$tool   = new WP_MCP_AI_Tool_Pro_Word();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'operation', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'operation', $schema['required'] );
	}

	// ============================================================================
	// Generate Invoice PDF Tool Tests
	// ============================================================================

	/**
	 * Test generate_invoice_pdf tool exists and loads.
	 */
	public function test_generate_invoice_pdf_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-invoice-pdf.php';
		$this->assertFileExists( $tool_file, 'Generate Invoice PDF tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Generate_Invoice_PDF' ), 'Generate Invoice PDF tool class should exist' );
	}

	/**
	 * Test generate_invoice_pdf tool metadata.
	 */
	public function test_generate_invoice_pdf_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-invoice-pdf.php';

		$tool = new WP_MCP_AI_Tool_Generate_Invoice_PDF();

		$this->assertEquals( 'generate_invoice_pdf', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$this->assertStringContainsString( 'invoice', strtolower( $tool->get_name() ) );
	}

	/**
	 * Test generate_invoice_pdf parameter schema.
	 */
	public function test_generate_invoice_pdf_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-generate-invoice-pdf.php';

		$tool   = new WP_MCP_AI_Tool_Generate_Invoice_PDF();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'invoice_number', $schema['properties'] );
		$this->assertArrayHasKey( 'items', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
	}

	// ============================================================================
	// HTML to PDF Tool Tests
	// ============================================================================

	/**
	 * Test html_to_pdf tool exists and loads.
	 */
	public function test_html_to_pdf_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php';
		$this->assertFileExists( $tool_file, 'HTML to PDF tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_HTML_To_PDF' ), 'HTML to PDF tool class should exist' );
	}

	/**
	 * Test html_to_pdf tool metadata.
	 */
	public function test_html_to_pdf_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php';

		$tool = new WP_MCP_AI_Tool_HTML_To_PDF();

		$this->assertEquals( 'html_to_pdf', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test html_to_pdf parameter schema.
	 */
	public function test_html_to_pdf_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php';

		$tool   = new WP_MCP_AI_Tool_HTML_To_PDF();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'html', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'html', $schema['required'] );
	}

	// ============================================================================
	// Excel Data Import Tool Tests
	// ============================================================================

	/**
	 * Test excel_data_import tool exists and loads.
	 */
	public function test_excel_data_import_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-excel-data-import.php';
		$this->assertFileExists( $tool_file, 'Excel Data Import tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Excel_Data_Import' ), 'Excel Data Import tool class should exist' );
	}

	/**
	 * Test excel_data_import tool metadata.
	 */
	public function test_excel_data_import_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-excel-data-import.php';

		$tool = new WP_MCP_AI_Tool_Excel_Data_Import();

		$this->assertEquals( 'excel_data_import', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test excel_data_import parameter schema.
	 */
	public function test_excel_data_import_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-excel-data-import.php';

		$tool   = new WP_MCP_AI_Tool_Excel_Data_Import();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		// Should accept attachment_id or url.
		$this->assertTrue(
			isset( $schema['properties']['attachment_id'] ) || isset( $schema['properties']['url'] ),
			'Excel Data Import should accept attachment_id or url'
		);
	}

	// ============================================================================
	// OCR PDF Text Tool Tests
	// ============================================================================

	/**
	 * Test ocr_pdf_text tool exists and loads.
	 */
	public function test_ocr_pdf_text_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-ocr-pdf-text.php';
		$this->assertFileExists( $tool_file, 'OCR PDF Text tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_OCR_PDF_Text' ), 'OCR PDF Text tool class should exist' );
	}

	/**
	 * Test ocr_pdf_text tool metadata.
	 */
	public function test_ocr_pdf_text_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-ocr-pdf-text.php';

		$tool = new WP_MCP_AI_Tool_OCR_PDF_Text();

		$this->assertEquals( 'ocr_pdf_text', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$this->assertStringContainsString( 'OCR', $tool->get_name() );
	}

	/**
	 * Test ocr_pdf_text parameter schema.
	 */
	public function test_ocr_pdf_text_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-ocr-pdf-text.php';

		$tool   = new WP_MCP_AI_Tool_OCR_PDF_Text();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		// Should accept attachment_id or url for OCR processing.
		$this->assertTrue(
			isset( $schema['properties']['attachment_id'] ) || isset( $schema['properties']['url'] ),
			'OCR PDF Text should accept attachment_id or url'
		);
	}

	// ============================================================================
	// Pro Document OCR Tool Tests
	// ============================================================================

	/**
	 * Test pro_document_ocr tool exists and loads.
	 */
	public function test_pro_document_ocr_tool_exists() {
		$tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php';
		$this->assertFileExists( $tool_file, 'Pro Document OCR tool file should exist' );

		require_once $tool_file;
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Pro_Document_OCR' ), 'Pro Document OCR tool class should exist' );
	}

	/**
	 * Test pro_document_ocr tool metadata.
	 */
	public function test_pro_document_ocr_metadata() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php';

		$tool = new WP_MCP_AI_Tool_Pro_Document_OCR();

		$this->assertEquals( 'pro_document_ocr', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test pro_document_ocr parameter schema.
	 */
	public function test_pro_document_ocr_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php';

		$tool   = new WP_MCP_AI_Tool_Pro_Document_OCR();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'operation', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
	}

	/**
	 * Test pro_document_ocr capability flags.
	 */
	public function test_pro_document_ocr_capability_flags() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-document-ocr.php';

		$tool = new WP_MCP_AI_Tool_Pro_Document_OCR();

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			$this->assertIsArray( $flags );
			$this->assertContains( 'pro', $flags );
		}
	}

	// ============================================================================
	// Integration Tests
	// ============================================================================

	/**
	 * Test all document generation tools have required capability.
	 */
	public function test_all_tools_have_required_capability() {
		foreach ( $this->tools_list as $tool_class ) {
			$class_file = $this->get_tool_file_path( $tool_class );
			
			if ( file_exists( $class_file ) ) {
				require_once $class_file;
				
				if ( class_exists( $tool_class ) ) {
					$tool       = new $tool_class();
					$definition = $tool->get_definition();
					
					$this->assertArrayHasKey(
						'required_capability',
						$definition,
						"$tool_class should have required_capability defined"
					);
					$this->assertNotEmpty(
						$definition['required_capability'],
						"$tool_class should have a non-empty required_capability"
					);
				}
			}
		}
	}

	/**
	 * Test all document generation tools implement base interface.
	 */
	public function test_all_tools_implement_base_interface() {
		foreach ( $this->tools_list as $tool_class ) {
			$class_file = $this->get_tool_file_path( $tool_class );
			
			if ( file_exists( $class_file ) ) {
				require_once $class_file;
				
				if ( class_exists( $tool_class ) ) {
					$tool = new $tool_class();
					
					$this->assertTrue(
						method_exists( $tool, 'execute' ),
						"$tool_class should implement execute method"
					);
					$this->assertTrue(
						method_exists( $tool, 'get_slug' ),
						"$tool_class should implement get_slug method"
					);
					$this->assertTrue(
						method_exists( $tool, 'get_parameters_schema' ),
						"$tool_class should implement get_parameters_schema method"
					);
				}
			}
		}
	}
}
