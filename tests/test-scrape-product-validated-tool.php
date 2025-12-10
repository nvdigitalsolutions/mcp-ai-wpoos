<?php
/**
 * Tests for Scrape Product Validated Tool
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Tool_Scrape_Product_Validated
 *
 * Tests for the validated scrape_product tool using Symfony Validator.
 */
class Test_WP_MCP_AI_Tool_Scrape_Product_Validated extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Scrape_Product_Validated
	 */
	private $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Skip if PHP < 8.0 (Symfony Validator attributes require PHP 8.0+).
		if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
			$this->markTestSkipped( 'Symfony Validator requires PHP 8.0+' );
		}

		// Load dependencies.
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once dirname( __DIR__ ) . '/includes/validators/class-wp-mcp-ai-validated-tool.php';
		require_once dirname( __DIR__ ) . '/includes/validators/arguments/class-scrape-product-arguments.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-scrape-product-validated.php';

		// Create test user with upload_files capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Scrape_Product_Validated();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'scrape_product_validated', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
		$this->assertStringContainsString( 'Validated', $this->tool->get_name() );
	}

	/**
	 * Test parameter schema is inherited from original tool.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertArrayHasKey( 'html_file', $schema['properties'] );
		$this->assertArrayHasKey( 'download_images', $schema['properties'] );
	}

	/**
	 * Test validation fails with invalid URL.
	 */
	public function test_validation_fails_with_invalid_url() {
		$arguments = array(
			'url' => 'not-a-valid-url',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with selector that's too long.
	 */
	public function test_validation_fails_with_selector_too_long() {
		$arguments = array(
			'url'            => 'https://example.com/product',
			'title_selector' => str_repeat( 'a', 501 ),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with non-boolean download_images.
	 */
	public function test_validation_fails_with_non_boolean_download_images() {
		$arguments = array(
			'url'             => 'https://example.com/product',
			'download_images' => 'yes',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with non-boolean extract_structured_data.
	 */
	public function test_validation_fails_with_non_boolean_extract_structured_data() {
		$arguments = array(
			'url'                     => 'https://example.com/product',
			'extract_structured_data' => 'true',
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with non-string url.
	 */
	public function test_validation_fails_with_non_string_url() {
		$arguments = array(
			'url' => 12345,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with non-string html_file.
	 */
	public function test_validation_fails_with_non_string_html_file() {
		$arguments = array(
			'html_file' => array( 'file' => 'test.html' ),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with non-string title_selector.
	 */
	public function test_validation_fails_with_non_string_title_selector() {
		$arguments = array(
			'url'            => 'https://example.com/product',
			'title_selector' => 123,
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test validation fails with non-string price_selector.
	 */
	public function test_validation_fails_with_non_string_price_selector() {
		$arguments = array(
			'url'            => 'https://example.com/product',
			'price_selector' => array( 'selector' => '.price' ),
		);

		$context = array( 'user_id' => $this->user_id );
		$result  = $this->tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_validation_failed', $result->get_error_code() );
	}

	/**
	 * Test capability flags are delegated.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();
		$this->assertIsArray( $flags );
	}
}
