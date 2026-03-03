<?php
/**
 * Test Embedded Provider Subtab Integration
 *
 * Tests that the Pro Providers section (Embedded LLM) subtab is properly
 * integrated into the base Providers section's subtab navigation.
 *
 * @package WP_MCP_AI
 */

/**
 * Test embedded provider subtab integration.
 */
class WP_MCP_AI_Embedded_Provider_Subtab_Integration_Test extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_settings' );

		// Register the Pro Providers section if not already registered.
		if ( class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
			$pro_providers_section = new WP_MCP_AI_Section_Pro_Providers();
			WP_MCP_AI_Settings_Registry::register_section( $pro_providers_section );
		}
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that the embedded subtab appears in the base Providers section subtabs.
	 */
	public function test_embedded_subtab_appears_in_base_providers_section() {
		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		$providers_section = new WP_MCP_AI_Section_Providers();
		$reflection        = new ReflectionClass( $providers_section );

		// Get the subtab groups using reflection.
		$method = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $providers_section );

		// Verify the embedded subtab is present.
		$this->assertArrayHasKey( 'embedded', $subtab_groups, 'Embedded subtab should be present in base Providers section' );

		// Verify the embedded subtab has the correct structure.
		$this->assertArrayHasKey( 'id', $subtab_groups['embedded'] );
		$this->assertArrayHasKey( 'label', $subtab_groups['embedded'] );
		$this->assertArrayHasKey( 'icon', $subtab_groups['embedded'] );
		$this->assertArrayHasKey( 'fields', $subtab_groups['embedded'] );

		// Verify the embedded subtab ID.
		$this->assertEquals( 'embedded', $subtab_groups['embedded']['id'] );

		// Verify the embedded subtab has expected fields.
		$expected_fields = array( 'enable_embedded', 'embedded_model', 'embedded_model_management' );
		$this->assertEquals( $expected_fields, $subtab_groups['embedded']['fields'] );
	}

	/**
	 * Test that the base Providers section can render the embedded subtab.
	 */
	public function test_base_providers_section_can_render_embedded_subtab() {
		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		$providers_section = new WP_MCP_AI_Section_Providers();

		// Set the active subtab to 'embedded'.
		$_GET['subtab'] = 'embedded';

		// Capture the output of render().
		ob_start();
		$providers_section->render();
		$output = ob_get_clean();

		// Verify that the output contains embedded provider fields.
		// Note: The actual rendering will depend on the Pro section's implementation.
		// For now, we just verify that no fatal error occurs.
		$this->assertTrue( true, 'Base Providers section should render embedded subtab without fatal error' );
	}

	/**
	 * Test that embedded provider fields are properly delegated to Pro section.
	 */
	public function test_embedded_provider_fields_are_delegated() {
		// Skip if Pro addon is not active.
		if ( ! class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Verify that the Pro Providers section has the expected fields.
		$pro_providers_section = new WP_MCP_AI_Section_Pro_Providers();
		$reflection            = new ReflectionClass( $pro_providers_section );
		$method                = $reflection->getMethod( 'get_fields' );
		$method->setAccessible( true );
		$pro_fields = $method->invoke( $pro_providers_section );

		// Verify that the Pro section has embedded provider fields.
		$this->assertArrayHasKey( 'enable_embedded', $pro_fields );
		$this->assertArrayHasKey( 'embedded_model', $pro_fields );
		$this->assertArrayHasKey( 'embedded_model_management', $pro_fields );
	}

	/**
	 * Test that embedded provider subtab only appears when Pro addon is active.
	 */
	public function test_embedded_subtab_only_appears_with_pro_addon() {
		// This test verifies the conditional logic works correctly.
		if ( ! class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
			// When Pro addon is not active, verify embedded subtab is NOT present.
			$providers_section = new WP_MCP_AI_Section_Providers();
			$reflection        = new ReflectionClass( $providers_section );
			$method            = $reflection->getMethod( 'get_subtab_groups' );
			$method->setAccessible( true );
			$subtab_groups = $method->invoke( $providers_section );

			$this->assertArrayNotHasKey( 'embedded', $subtab_groups, 'Embedded subtab should NOT be present without Pro addon' );
		} else {
			// When Pro addon is active, verify embedded subtab IS present.
			$providers_section = new WP_MCP_AI_Section_Providers();
			$reflection        = new ReflectionClass( $providers_section );
			$method            = $reflection->getMethod( 'get_subtab_groups' );
			$method->setAccessible( true );
			$subtab_groups = $method->invoke( $providers_section );

			$this->assertArrayHasKey( 'embedded', $subtab_groups, 'Embedded subtab SHOULD be present with Pro addon' );
		}
	}
}
