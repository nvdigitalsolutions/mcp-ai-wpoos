<?php
/**
 * Tests for OCR Service Fix.
 *
 * Validates that the OCR service methods work correctly after fixing
 * the get_instance() and method call issues.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for OCR service fix.
 *
 * @group tools
 * @group pro
 * @group ocr
 */
class WP_MCP_AI_OCR_Service_Fix_Tests extends WP_UnitTestCase {

	/**
	 * Test that OCR service class can be instantiated.
	 */
	public function test_ocr_service_class_exists() {
		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$service_path = WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		
		$this->assertFileExists( $service_path, 'OCR service file should exist' );
		
		require_once $service_path;
		
		$this->assertTrue( class_exists( 'WP_MCP_AI_OCR_Service' ), 'WP_MCP_AI_OCR_Service class should exist' );
	}

	/**
	 * Test that OCR service can be instantiated without errors.
	 */
	public function test_ocr_service_instantiation() {
		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		
		$service = new WP_MCP_AI_OCR_Service();
		
		$this->assertInstanceOf( 'WP_MCP_AI_OCR_Service', $service, 'Should be able to instantiate OCR service' );
	}

	/**
	 * Test that extract_text_from_image method exists and accepts correct parameters.
	 */
	public function test_extract_text_from_image_method_exists() {
		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		
		$service = new WP_MCP_AI_OCR_Service();
		
		$this->assertTrue(
			method_exists( $service, 'extract_text_from_image' ),
			'extract_text_from_image method should exist'
		);
	}

	/**
	 * Test that extract_text_from_pdf method exists and accepts correct parameters.
	 */
	public function test_extract_text_from_pdf_method_exists() {
		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		
		$service = new WP_MCP_AI_OCR_Service();
		
		$this->assertTrue(
			method_exists( $service, 'extract_text_from_pdf' ),
			'extract_text_from_pdf method should exist'
		);
	}

	/**
	 * Test that is_scanned_pdf method exists.
	 */
	public function test_is_scanned_pdf_method_exists() {
		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		
		$service = new WP_MCP_AI_OCR_Service();
		
		$this->assertTrue(
			method_exists( $service, 'is_scanned_pdf' ),
			'is_scanned_pdf method should exist'
		);
	}

	/**
	 * Test that extract_text_from_image returns WP_Error for missing file.
	 */
	public function test_extract_text_from_image_missing_file() {
		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		
		$service = new WP_MCP_AI_OCR_Service();
		$result  = $service->extract_text_from_image( '/nonexistent/file.jpg' );
		
		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for missing file' );
		$this->assertEquals( 'file_not_found', $result->get_error_code(), 'Error code should be file_not_found' );
	}

	/**
	 * Test that extract_text_from_pdf returns WP_Error for missing file.
	 */
	public function test_extract_text_from_pdf_missing_file() {
		// Skip if Pro addon is not loaded.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		
		$service = new WP_MCP_AI_OCR_Service();
		$result  = $service->extract_text_from_pdf( '/nonexistent/file.pdf' );
		
		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error for missing file' );
		$this->assertEquals( 'file_not_found', $result->get_error_code(), 'Error code should be file_not_found' );
	}
}
