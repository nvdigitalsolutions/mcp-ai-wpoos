<?php
/**
 * Tests for the Extract PDF Text tool.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Extract PDF Text tool test case.
 */
class Test_Extract_PDF_Text_Tool extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Extract_PDF_Text
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-extract-pdf-text.php';
		$this->tool = new WP_MCP_AI_Tool_Extract_PDF_Text();

		// A capable user for the default paths.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'extract_pdf_text', $this->tool->get_slug() );
		$this->assertStringContainsString( 'Extract text content from PDF', $this->tool->get_description() );
	}

	/**
	 * Missing input must return the missing_input error.
	 */
	public function test_missing_input_returns_error() {
		$result = $this->tool->execute( array(), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_input', $result->get_error_code() );
	}

	/**
	 * A nonexistent attachment must return file_not_found.
	 */
	public function test_nonexistent_attachment_returns_file_not_found() {
		$result = $this->tool->execute( array( 'attachment_id' => 9999999 ), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * A non-PDF attachment must return invalid_file_type.
	 */
	public function test_non_pdf_attachment_returns_invalid_file_type() {
		$upload = wp_upload_bits( 'fake-doc.pdf', null, 'This is not a PDF file.' );
		$this->assertFalse( $upload['error'] );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'fake-doc.pdf',
			),
			$upload['file']
		);

		$result = $this->tool->execute( array( 'attachment_id' => $attachment_id ), array() );

		wp_delete_attachment( $attachment_id, true );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_file_type', $result->get_error_code() );
	}

	/**
	 * A guest (no read capability) must be denied.
	 */
	public function test_permission_denied_for_guest() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute( array( 'attachment_id' => 123 ), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );
	}

	/**
	 * The CLI probe must report a missing binary as unavailable without fataling
	 * (regression: the old shell_exec() probe fataled on exec-disabled hosts).
	 */
	public function test_cli_probe_returns_false_for_missing_binary() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'is_cli_tool_available' );
		$method->setAccessible( true );

		$available = $method->invoke( $this->tool, 'definitely-not-a-real-binary-xyz-12345' );

		$this->assertFalse( $available );
	}

	/**
	 * The guarded CLI runner must report a failing command without fataling.
	 */
	public function test_run_cli_command_reports_missing_binary() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'run_cli_command' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, 'definitely-not-a-real-binary-xyz-12345 2>&1' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'output', $result );
		$this->assertArrayHasKey( 'return_code', $result );
		$this->assertArrayHasKey( 'disabled', $result );

		if ( ! $result['disabled'] ) {
			$this->assertNotSame( 0, $result['return_code'] );
		}
	}

	/**
	 * The Node service path must degrade gracefully when the service file or
	 * the node binary is missing (never call exec() unguarded).
	 */
	public function test_node_service_missing_returns_error() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'extract_with_node_service' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, sys_get_temp_dir() . '/missing.pdf', 0 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'service_not_found', 'node_service_failed', 'node_service_unavailable' )
		);
	}

	/**
	 * The ocr_provider enum must stay aligned with the OCR service providers.
	 */
	public function test_ocr_provider_enum_aligns_with_service() {
		$schema = $this->tool->get_parameters_schema();

		$expected = array( 'auto', 'openai', 'gemini', 'anthropic', 'ollama', 'tesseract', 'unlimited_ocr', 'deepseek_ocr' );

		$this->assertSame( $expected, $schema['properties']['ocr_provider']['enum'] );
	}
}
