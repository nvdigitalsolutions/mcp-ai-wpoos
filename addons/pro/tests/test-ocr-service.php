<?php
/**
 * Tests for OCR Service
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Test OCR Service class
 */
class Test_WP_MCP_AI_OCR_Service extends WP_UnitTestCase {

	/**
	 * OCR Service instance.
	 *
	 * @var WP_MCP_AI_OCR_Service
	 */
	private $ocr_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Load OCR service.
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		$this->ocr_service = new WP_MCP_AI_OCR_Service();
	}

	/**
	 * Test that OCR service class exists.
	 */
	public function test_ocr_service_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_OCR_Service' ) );
	}

	/**
	 * Test OCR service instantiation.
	 */
	public function test_ocr_service_instantiation() {
		$this->assertInstanceOf( 'WP_MCP_AI_OCR_Service', $this->ocr_service );
	}

	/**
	 * Test is_scanned_pdf method with non-existent file.
	 */
	public function test_is_scanned_pdf_with_nonexistent_file() {
		$result = $this->ocr_service->is_scanned_pdf( '/path/to/nonexistent.pdf' );
		
		// Should return true (assume scanned if can't read).
		$this->assertTrue( $result );
	}

	/**
	 * Test extract_text_from_image with non-existent file.
	 */
	public function test_extract_text_from_image_with_nonexistent_file() {
		$result = $this->ocr_service->extract_text_from_image( '/path/to/nonexistent.jpg' );
		
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test extract_text_from_pdf with non-existent file.
	 */
	public function test_extract_text_from_pdf_with_nonexistent_file() {
		$result = $this->ocr_service->extract_text_from_pdf( '/path/to/nonexistent.pdf' );
		
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test OCR provider determination.
	 */
	public function test_determine_best_provider() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'determine_best_provider' );
		$method->setAccessible( true );

		$provider = $method->invoke( $this->ocr_service );
		
		// Should return a valid provider name.
		$this->assertContains(
			$provider,
			array( 'openai', 'gemini', 'ollama', 'tesseract' )
		);
	}

	/**
	 * Test fallback providers list.
	 */
	public function test_get_fallback_providers() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'get_fallback_providers' );
		$method->setAccessible( true );

		$fallbacks = $method->invoke( $this->ocr_service, 'openai' );
		
		// Should return array without primary provider.
		$this->assertIsArray( $fallbacks );
		$this->assertNotContains( 'openai', $fallbacks );
		$this->assertContains( 'gemini', $fallbacks );
	}

	/**
	 * Test Sharp availability check.
	 */
	public function test_is_sharp_available() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'is_sharp_available' );
		$method->setAccessible( true );

		$available = $method->invoke( $this->ocr_service );
		
		// Should return boolean.
		$this->assertIsBool( $available );
	}

	/**
	 * Test PDF to images conversion with invalid file.
	 */
	public function test_convert_pdf_to_images_with_invalid_file() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'convert_pdf_to_images' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ocr_service, '/path/to/invalid.pdf', array() );
		
		// Should return WP_Error for invalid file.
		$this->assertInstanceOf( 'WP_Error', $result );
	}
}
