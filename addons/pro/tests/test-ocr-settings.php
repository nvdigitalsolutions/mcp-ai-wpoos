<?php
/**
 * Tests for OCR Settings
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Test OCR Settings class
 */
class Test_WP_MCP_AI_OCR_Settings extends WP_UnitTestCase {

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
		
		// Clear any existing settings.
		delete_option( 'wp_mcp_ai_document_generation_settings' );
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up settings.
		delete_option( 'wp_mcp_ai_document_generation_settings' );
		delete_option( 'wp_mcp_ai_settings' );
		
		parent::tearDown();
	}

	/**
	 * Test OCR provider setting is respected.
	 */
	public function test_ocr_provider_setting_is_respected() {
		// Set a specific provider.
		update_option(
			'wp_mcp_ai_document_generation_settings',
			array( 'ocr_provider' => 'gemini' )
		);

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'determine_best_provider' );
		$method->setAccessible( true );

		$provider = $method->invoke( $this->ocr_service );
		
		$this->assertEquals( 'gemini', $provider );
	}

	/**
	 * Test auto provider detection when setting is auto.
	 */
	public function test_auto_provider_detection() {
		// Set auto mode.
		update_option(
			'wp_mcp_ai_document_generation_settings',
			array( 'ocr_provider' => 'auto' )
		);

		// Configure OpenAI.
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'test-key' )
		);

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'determine_best_provider' );
		$method->setAccessible( true );

		$provider = $method->invoke( $this->ocr_service );
		
		// Should prefer OpenAI when available.
		$this->assertEquals( 'openai', $provider );
	}

	/**
	 * Test fallback provider setting is respected.
	 */
	public function test_fallback_provider_setting_is_respected() {
		// Set specific fallback.
		update_option(
			'wp_mcp_ai_document_generation_settings',
			array( 'ocr_fallback_provider' => 'tesseract' )
		);

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'get_fallback_providers' );
		$method->setAccessible( true );

		$fallbacks = $method->invoke( $this->ocr_service, 'openai' );
		
		// Should return only tesseract.
		$this->assertEquals( array( 'tesseract' ), $fallbacks );
	}

	/**
	 * Test no fallback when set to none.
	 */
	public function test_no_fallback_when_set_to_none() {
		// Set no fallback.
		update_option(
			'wp_mcp_ai_document_generation_settings',
			array( 'ocr_fallback_provider' => 'none' )
		);

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'get_fallback_providers' );
		$method->setAccessible( true );

		$fallbacks = $method->invoke( $this->ocr_service, 'openai' );
		
		// Should return empty array.
		$this->assertEmpty( $fallbacks );
	}

	/**
	 * Test auto fallback mode.
	 */
	public function test_auto_fallback_mode() {
		// Set auto fallback.
		update_option(
			'wp_mcp_ai_document_generation_settings',
			array( 'ocr_fallback_provider' => 'auto' )
		);

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'get_fallback_providers' );
		$method->setAccessible( true );

		$fallbacks = $method->invoke( $this->ocr_service, 'openai' );
		
		// Should return all providers except openai.
		$this->assertNotContains( 'openai', $fallbacks );
		$this->assertContains( 'gemini', $fallbacks );
		$this->assertContains( 'ollama', $fallbacks );
		$this->assertContains( 'tesseract', $fallbacks );
	}

	/**
	 * Test preprocessing setting is respected.
	 */
	public function test_preprocessing_setting_is_respected() {
		// Disable preprocessing.
		update_option(
			'wp_mcp_ai_document_generation_settings',
			array( 'ocr_preprocessing' => false )
		);

		// Get default options that would be used.
		$doc_settings = get_option( 'wp_mcp_ai_document_generation_settings', array() );
		$preprocess   = isset( $doc_settings['ocr_preprocessing'] ) ? (bool) $doc_settings['ocr_preprocessing'] : true;
		
		$this->assertFalse( $preprocess );
	}

	/**
	 * Test timeout setting is respected.
	 */
	public function test_timeout_setting_is_respected() {
		// Set custom timeout.
		update_option(
			'wp_mcp_ai_document_generation_settings',
			array( 'ocr_timeout' => 180 )
		);

		// Get default options that would be used.
		$doc_settings = get_option( 'wp_mcp_ai_document_generation_settings', array() );
		$timeout      = isset( $doc_settings['ocr_timeout'] ) ? absint( $doc_settings['ocr_timeout'] ) : 300;
		
		$this->assertEquals( 180, $timeout );
	}

	/**
	 * Test default timeout when not set.
	 */
	public function test_default_timeout_when_not_set() {
		// No settings configured.
		$doc_settings = get_option( 'wp_mcp_ai_document_generation_settings', array() );
		$timeout      = isset( $doc_settings['ocr_timeout'] ) ? absint( $doc_settings['ocr_timeout'] ) : 300;
		
		$this->assertEquals( 300, $timeout );
	}

	/**
	 * Test settings page class exists.
	 */
	public function test_settings_page_class_exists() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-document-generation-cpt-settings-page.php';
		
		$this->assertTrue( class_exists( 'WP_MCP_AI_Document_Generation_Settings_Page' ) );
	}
}
