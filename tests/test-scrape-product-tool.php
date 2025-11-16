<?php
/**
 * Tests for the Scrape Product tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Tool_Scrape_Product.
 */
class Test_Scrape_Product_Tool extends WP_UnitTestCase {
	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Scrape_Product
	 */
	protected $tool;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-scrape-product.php';
		$this->tool = new WP_MCP_AI_Tool_Scrape_Product();
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertEquals( 'scrape_product', $this->tool->get_slug() );
	}

	/**
	 * Test tool name.
	 */
	public function test_get_name() {
		$this->assertEquals( 'Scrape Product', $this->tool->get_name() );
	}

	/**
	 * Test tool description.
	 */
	public function test_get_description() {
		$description = $this->tool->get_description();
		$this->assertStringContainsString( 'product', strtolower( $description ) );
		$this->assertStringContainsString( 'scrape', strtolower( $description ) );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_get_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'url', $schema['properties'] );
		$this->assertArrayHasKey( 'title_selector', $schema['properties'] );
		$this->assertArrayHasKey( 'subtitle_selector', $schema['properties'] );
		$this->assertArrayHasKey( 'description_selector', $schema['properties'] );
		$this->assertArrayHasKey( 'images_selector', $schema['properties'] );
		$this->assertArrayHasKey( 'download_images', $schema['properties'] );

		// Check required fields.
		$this->assertContains( 'url', $schema['required'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_get_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'network-dependent', $flags );
	}

	/**
	 * Test execution without permissions.
	 */
	public function test_execute_without_permission() {
		$arguments = array(
			'url' => 'https://example.com/product',
		);

		$context = array(
			'user_id' => 0,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test execution without URL.
	 */
	public function test_execute_without_url() {
		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$arguments = array();

		$context = array(
			'user_id' => $user_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_url', $result->get_error_code() );
	}

	/**
	 * Test execution with invalid URL.
	 */
	public function test_execute_with_invalid_url() {
		// Create a user with upload_files capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$arguments = array(
			'url' => 'not-a-valid-url',
		);

		$context = array(
			'user_id' => $user_id,
		);

		$result = $this->tool->execute( $arguments, $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_url', $result->get_error_code() );
	}

	/**
	 * Test CSS to XPath conversion.
	 */
	public function test_css_to_xpath_conversion() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'css_to_xpath' );
		$method->setAccessible( true );

		// Test ID selector.
		$xpath = $method->invoke( $this->tool, 'div#my-id' );
		$this->assertStringContainsString( '[@id="my-id"]', $xpath );

		// Test class selector.
		$xpath = $method->invoke( $this->tool, '.my-class' );
		$this->assertStringContainsString( 'contains', $xpath );
		$this->assertStringContainsString( 'my-class', $xpath );

		// Test element selector.
		$xpath = $method->invoke( $this->tool, 'div' );
		$this->assertStringContainsString( '//div', $xpath );
	}

	/**
	 * Test HTML parsing with mock data.
	 */
	public function test_parse_product_data() {
		$html = '<!DOCTYPE html>
<html>
<head><title>Test Product</title></head>
<body>
	<div class="swa-product-information__title swa-label-sans--default-strong">Swan Pendant</div>
	<div class="swa-product-information__subtitle swa-label-sans--default">White Crystal</div>
	<div class="swa-cms-copy__body swa-content-accordion__copy-body swa-content-accordion__panel-inner js-swa-content-accordion-panel-inner">
		<p>Beautiful swan pendant.</p>
		<p>Made with high quality materials.</p>
	</div>
	<div id="splide01-slide01">
		<img src="https://example.com/image1.jpg" alt="Product Image 1">
	</div>
	<div id="splide01-slide02">
		<img src="https://example.com/image2.jpg" alt="Product Image 2">
	</div>
</body>
</html>';

		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'parse_product_data' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->tool,
			$html,
			'.swa-product-information__title.swa-label-sans--default-strong',
			'.swa-product-information__subtitle.swa-label-sans--default',
			'.swa-cms-copy__body.swa-content-accordion__copy-body.swa-content-accordion__panel-inner.js-swa-content-accordion-panel-inner p',
			'splide-slides'
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'Swan Pendant', $result['title'] );
		$this->assertEquals( 'White Crystal', $result['subtitle'] );
		$this->assertStringContainsString( 'Beautiful swan pendant', $result['description'] );
		$this->assertStringContainsString( 'high quality materials', $result['description'] );

		// Should find both images when using splide-slides pattern.
		$this->assertIsArray( $result['image_urls'] );
		$this->assertCount( 2, $result['image_urls'] );
		$this->assertContains( 'https://example.com/image1.jpg', $result['image_urls'] );
		$this->assertContains( 'https://example.com/image2.jpg', $result['image_urls'] );
	}

	/**
	 * Test image extraction with lazy loading.
	 */
	public function test_extract_images_with_lazy_loading() {
		$html = '<!DOCTYPE html>
<html>
<body>
	<div id="splide01-slide01">
		<img data-src="https://example.com/lazy1.jpg" alt="Lazy Image 1">
	</div>
	<div id="splide02-slide01">
		<img data-splide-lazy="https://example.com/lazy2.jpg" alt="Lazy Image 2">
	</div>
	<div id="splide03-slide01">
		<img srcset="https://example.com/responsive.jpg 1x, https://example.com/responsive-2x.jpg 2x" alt="Responsive Image">
	</div>
</body>
</html>';

		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'parse_product_data' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->tool,
			$html,
			'.not-exists',
			'.not-exists',
			'.not-exists',
			'splide-slides'
		);

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['image_urls'] );

		// Should find all three images with different lazy loading attributes.
		$this->assertGreaterThanOrEqual( 3, count( $result['image_urls'] ) );
		$this->assertContains( 'https://example.com/lazy1.jpg', $result['image_urls'] );
		$this->assertContains( 'https://example.com/lazy2.jpg', $result['image_urls'] );
		$this->assertContains( 'https://example.com/responsive.jpg', $result['image_urls'] );
	}
}
