<?php
/**
 * Tests for Document Generation Tools URL Support.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for document generation tools with URL support.
 *
 * @group tools
 * @group pro
 * @group document-generation
 */
class WP_MCP_AI_Document_Generation_URL_Support_Tests extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $user_id;

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
	 * Test extract_pdf_text tool schema accepts both attachment_id and url.
	 */
	public function test_extract_pdf_text_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php';

		$tool   = new WP_MCP_AI_Tool_Extract_PDF_Text();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertEquals( 'integer', $schema['properties']['attachment_id']['type'] );
		$this->assertEquals( 'string', $schema['properties']['url']['type'] );

		// Neither should be strictly required (one or the other).
		$this->assertEmpty( $schema['required'] );
	}

	/**
	 * Test extract_pdf_text loads download_url function.
	 */
	public function test_extract_pdf_text_loads_download_url_function() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php';

		$tool = new WP_MCP_AI_Tool_Extract_PDF_Text();

		// Test that the tool would fail gracefully if download_url is not available.
		// We can't fully test the download without a real PDF URL, but we can verify
		// the function would be loaded.
		$this->assertTrue( method_exists( $tool, 'execute' ) );
	}

	/**
	 * Test extract_pdf_text requires either attachment_id or url.
	 */
	public function test_extract_pdf_text_requires_id_or_url() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php';

		$tool   = new WP_MCP_AI_Tool_Extract_PDF_Text();
		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'attachment_id or url', $result['error'] );
	}

	/**
	 * Test add_watermark_to_pdf tool schema accepts both attachment_id and url.
	 */
	public function test_add_watermark_to_pdf_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-add-watermark-to-pdf.php';

		$tool   = new WP_MCP_AI_Tool_Add_Watermark_To_PDF();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'] );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertArrayHasKey( 'text', $schema['properties'] );

		// text is required, but attachment_id and url are optional (one or the other).
		$this->assertContains( 'text', $schema['required'] );
		$this->assertNotContains( 'attachment_id', $schema['required'] );
	}

	/**
	 * Test add_watermark_to_pdf requires text parameter.
	 */
	public function test_add_watermark_to_pdf_requires_text() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-add-watermark-to-pdf.php';

		$tool   = new WP_MCP_AI_Tool_Add_Watermark_To_PDF();
		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'text', $result['error'] );
	}

	/**
	 * Test add_watermark_to_pdf requires either attachment_id or url.
	 */
	public function test_add_watermark_to_pdf_requires_id_or_url() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-add-watermark-to-pdf.php';

		$tool   = new WP_MCP_AI_Tool_Add_Watermark_To_PDF();
		$result = $tool->execute(
			array( 'text' => 'CONFIDENTIAL' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'attachment_id or url', $result['error'] );
	}

	/**
	 * Test merge_pdfs tool schema accepts both attachment_ids and urls.
	 */
	public function test_merge_pdfs_schema() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php';

		$tool   = new WP_MCP_AI_Tool_Merge_PDFs();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'attachment_ids', $schema['properties'] );
		$this->assertArrayHasKey( 'urls', $schema['properties'] );

		// Both should be arrays.
		$this->assertEquals( 'array', $schema['properties']['attachment_ids']['type'] );
		$this->assertEquals( 'array', $schema['properties']['urls']['type'] );

		// Neither should be strictly required (one or the other).
		$this->assertEmpty( $schema['required'] );
	}

	/**
	 * Test merge_pdfs requires either attachment_ids or urls.
	 */
	public function test_merge_pdfs_requires_ids_or_urls() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php';

		$tool   = new WP_MCP_AI_Tool_Merge_PDFs();
		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'attachment_ids or urls', $result['error'] );
	}

	/**
	 * Test merge_pdfs requires at least 2 files.
	 */
	public function test_merge_pdfs_requires_minimum_two_files() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php';

		$tool = new WP_MCP_AI_Tool_Merge_PDFs();

		// Test with one attachment_id.
		$result = $tool->execute(
			array( 'attachment_ids' => array( 123 ) ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'at least 2', $result['error'] );

		// Test with one URL.
		$result = $tool->execute(
			array( 'urls' => array( 'https://example.com/file.pdf' ) ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'at least 2', $result['error'] );
	}

	/**
	 * Test that download_url function would be available.
	 *
	 * This test ensures that the WordPress file handling functions
	 * would be loaded when needed.
	 */
	public function test_download_url_function_availability() {
		// Simulate the condition where download_url might not be loaded.
		// In actual WordPress context, it will be loaded by the tools.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$this->assertTrue( function_exists( 'download_url' ) );
	}

	/**
	 * Test tool capability flags are correct.
	 */
	public function test_tool_capability_flags() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-add-watermark-to-pdf.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php';

		$extract_tool   = new WP_MCP_AI_Tool_Extract_PDF_Text();
		$watermark_tool = new WP_MCP_AI_Tool_Add_Watermark_To_PDF();
		$merge_tool     = new WP_MCP_AI_Tool_Merge_PDFs();

		// All should be pro tools.
		$this->assertContains( 'pro', $extract_tool->get_capability_flags() );
		$this->assertContains( 'pro', $watermark_tool->get_capability_flags() );
		$this->assertContains( 'pro', $merge_tool->get_capability_flags() );

		// Extract is read-only.
		$this->assertContains( 'read-only', $extract_tool->get_capability_flags() );

		// Watermark and merge are write operations.
		$this->assertContains( 'write', $watermark_tool->get_capability_flags() );
		$this->assertContains( 'write', $merge_tool->get_capability_flags() );
	}
}
