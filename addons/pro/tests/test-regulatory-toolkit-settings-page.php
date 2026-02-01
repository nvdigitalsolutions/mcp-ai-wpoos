<?php
/**
 * Test Regulatory Registration Toolkit Settings Page
 *
 * Verifies that the settings page renders correctly with proper form fields
 * and sanitization.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Regulatory Registration Toolkit Settings Page
 */
class Test_Regulatory_Toolkit_Settings_Page extends WP_UnitTestCase {

	/**
	 * Settings page instance
	 *
	 * @var WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page
	 */
	private $settings_page;

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the settings page class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-regulatory-registration-toolkit-settings-page.php';

		// Create instance.
		$this->settings_page = new WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page();
	}

	/**
	 * Test that the settings page class exists and has correct properties.
	 */
	public function test_settings_page_initialization() {
		$this->assertInstanceOf( 'WP_MCP_AI_Regulatory_Registration_Toolkit_Settings_Page', $this->settings_page );
	}

	/**
	 * Test that research functionality is enabled.
	 */
	public function test_has_research_enabled() {
		// Use reflection to check protected property.
		$reflection = new ReflectionClass( $this->settings_page );
		$property   = $reflection->getProperty( 'has_research' );
		$property->setAccessible( true );

		$this->assertTrue( $property->getValue( $this->settings_page ) );
	}

	/**
	 * Test that sanitize_settings properly sanitizes input.
	 */
	public function test_sanitize_settings() {
		$input = array(
			'default_regulatory_authority' => 'nmra',
			'enable_expiry_alerts'         => '1',
			'expiry_alert_days'            => '45',
			'enable_pdf_generation'        => '1',
			'enable_excel_export'          => '1',
			'enable_inci_validation'       => '1',
			'enable_hs_code_validation'    => '1',
			'enable_api_sync'              => '',
			'auto_generate_product_code'   => '1',
			'product_code_prefix'          => 'REG',
			'enable_research'              => '1',
		);

		$sanitized = $this->settings_page->sanitize_settings( $input );

		// Verify text fields are sanitized.
		$this->assertEquals( 'nmra', $sanitized['default_regulatory_authority'] );
		$this->assertEquals( 'REG', $sanitized['product_code_prefix'] );

		// Verify boolean fields.
		$this->assertTrue( $sanitized['enable_expiry_alerts'] );
		$this->assertTrue( $sanitized['enable_pdf_generation'] );
		$this->assertTrue( $sanitized['enable_excel_export'] );
		$this->assertTrue( $sanitized['enable_inci_validation'] );
		$this->assertTrue( $sanitized['enable_hs_code_validation'] );
		$this->assertTrue( $sanitized['auto_generate_product_code'] );
		$this->assertTrue( $sanitized['enable_research'] );

		// Verify numeric field.
		$this->assertEquals( 45, $sanitized['expiry_alert_days'] );
	}

	/**
	 * Test that expiry alert days are bounded correctly.
	 */
	public function test_sanitize_expiry_alert_days_bounds() {
		// Test minimum bound.
		$input     = array( 'expiry_alert_days' => '0' );
		$sanitized = $this->settings_page->sanitize_settings( $input );
		$this->assertEquals( 1, $sanitized['expiry_alert_days'] );

		// Test maximum bound.
		$input     = array( 'expiry_alert_days' => '500' );
		$sanitized = $this->settings_page->sanitize_settings( $input );
		$this->assertEquals( 365, $sanitized['expiry_alert_days'] );

		// Test valid value.
		$input     = array( 'expiry_alert_days' => '90' );
		$sanitized = $this->settings_page->sanitize_settings( $input );
		$this->assertEquals( 90, $sanitized['expiry_alert_days'] );
	}

	/**
	 * Test that product code prefix is limited to 10 characters.
	 */
	public function test_sanitize_product_code_prefix_length() {
		$input     = array( 'product_code_prefix' => 'VERYLONGPREFIX123' );
		$sanitized = $this->settings_page->sanitize_settings( $input );

		$this->assertEquals( 10, strlen( $sanitized['product_code_prefix'] ) );
		$this->assertEquals( 'VERYLONG', substr( $sanitized['product_code_prefix'], 0, 8 ) );
	}

	/**
	 * Test that tools list is populated.
	 */
	public function test_get_tools_list() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->settings_page );
		$method     = $reflection->getMethod( 'get_tools_list' );
		$method->setAccessible( true );

		$tools = $method->invoke( $this->settings_page );

		$this->assertIsArray( $tools );
		$this->assertNotEmpty( $tools );

		// Verify some expected tools exist.
		$this->assertArrayHasKey( 'create_reg_product', $tools );
		$this->assertArrayHasKey( 'list_registrations', $tools );
		$this->assertArrayHasKey( 'generate_pdf_dossier', $tools );
		$this->assertArrayHasKey( 'sync_with_nmra', $tools );
	}

	/**
	 * Test that render methods exist and are callable.
	 */
	public function test_render_methods_exist() {
		$render_methods = array(
			'render_default_regulatory_authority_field',
			'render_enable_expiry_alerts_field',
			'render_expiry_alert_days_field',
			'render_enable_pdf_generation_field',
			'render_enable_excel_export_field',
			'render_enable_inci_validation_field',
			'render_enable_hs_code_validation_field',
			'render_enable_api_sync_field',
			'render_auto_generate_product_code_field',
			'render_product_code_prefix_field',
		);

		foreach ( $render_methods as $method_name ) {
			$this->assertTrue(
				method_exists( $this->settings_page, $method_name ),
				"Method {$method_name} should exist"
			);
		}
	}

	/**
	 * Clean up after each test
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
