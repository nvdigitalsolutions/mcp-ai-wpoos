<?php
/**
 * Test Pro Providers Settings Section
 *
 * @package WP_MCP_AI
 */

/**
 * Test WP_MCP_AI_Section_Pro_Providers class.
 */
class WP_MCP_AI_Pro_Providers_Section_Test extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		unset( $_POST['subtab'] );
		unset( $_GET['subtab'] );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that the section can be instantiated without fatal error.
	 *
	 * This test addresses the issue:
	 * "Fatal error: Class WP_MCP_AI_Section_Pro_Providers contains 1 abstract method
	 * and must therefore be declared abstract or implement the remaining methods
	 * (WP_MCP_AI_Settings_Section::render)"
	 */
	public function test_section_can_be_instantiated() {
		$section = new WP_MCP_AI_Section_Pro_Providers();
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Pro_Providers', $section );
	}

	/**
	 * Test that the render method exists and is public.
	 */
	public function test_render_method_exists() {
		$section    = new WP_MCP_AI_Section_Pro_Providers();
		$reflection = new ReflectionClass( $section );

		$this->assertTrue( $reflection->hasMethod( 'render' ) );

		$method = $reflection->getMethod( 'render' );
		$this->assertTrue( $method->isPublic() );
	}

	/**
	 * Test that get_active_subtab method exists and is protected.
	 */
	public function test_get_active_subtab_method_exists() {
		$section    = new WP_MCP_AI_Section_Pro_Providers();
		$reflection = new ReflectionClass( $section );

		$this->assertTrue( $reflection->hasMethod( 'get_active_subtab' ) );

		$method = $reflection->getMethod( 'get_active_subtab' );
		$this->assertTrue( $method->isProtected() );
	}

	/**
	 * Test section ID.
	 */
	public function test_get_id() {
		$section = new WP_MCP_AI_Section_Pro_Providers();
		$this->assertEquals( 'pro_providers', $section->get_id() );
	}

	/**
	 * Test section title.
	 */
	public function test_get_title() {
		$section = new WP_MCP_AI_Section_Pro_Providers();
		$this->assertNotEmpty( $section->get_title() );
	}

	/**
	 * Test section tab.
	 */
	public function test_get_tab() {
		$section = new WP_MCP_AI_Section_Pro_Providers();
		$this->assertEquals( 'providers', $section->get_tab() );
	}

	/**
	 * Test that fields are defined.
	 */
	public function test_get_fields_returns_array() {
		$section = new WP_MCP_AI_Section_Pro_Providers();
		$fields  = $section->get_fields();

		$this->assertIsArray( $fields );
		$this->assertNotEmpty( $fields );
	}

	/**
	 * Test that subtab groups are defined.
	 */
	public function test_get_subtab_groups() {
		$section    = new WP_MCP_AI_Section_Pro_Providers();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$groups = $method->invoke( $section );

		$this->assertIsArray( $groups );
		$this->assertNotEmpty( $groups );
		$this->assertArrayHasKey( 'embedded', $groups );
	}

	/**
	 * Test that embedded subtab saves correctly.
	 */
	public function test_embedded_subtab_saves_correctly() {
		$section = new WP_MCP_AI_Section_Pro_Providers();

		$_POST['subtab_pro_providers'] = 'embedded';
		$input                         = array(
			'enable_embedded' => '1',
			'embedded_model'  => 'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC',
		);

		$sanitized = $section->sanitize( $input );

		$this->assertArrayHasKey( 'enable_embedded', $sanitized );
		$this->assertTrue( $sanitized['enable_embedded'] );
		$this->assertArrayHasKey( 'embedded_model', $sanitized );
		$this->assertEquals( 'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC', $sanitized['embedded_model'] );
	}
}
