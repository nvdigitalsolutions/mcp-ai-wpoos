<?php
/**
 * Test Pro Providers Section Registration
 *
 * Tests that the Pro Providers section is properly registered with the
 * Settings Registry when the Pro addon is active.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Pro Providers section registration.
 */
class WP_MCP_AI_Pro_Providers_Section_Registration_Test extends WP_UnitTestCase {

	/**
	 * Test that Pro Providers section is NOT registered as standalone when Pro is active.
	 *
	 * The Pro Providers section should be instantiable from the container but NOT
	 * registered in the Settings Registry. This prevents duplicate rendering while
	 * still allowing the base Providers section to merge its subtabs.
	 */
	public function test_pro_providers_section_not_registered_as_standalone() {
		// Verify Pro addon constant is defined.
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_VERSION' ),
			'WP_MCP_AI_PRO_VERSION should be defined when Pro addon is active'
		);

		// Get the section from the container.
		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.pro_providers' );

		// Verify section was created successfully by container.
		$this->assertNotNull(
			$section,
			'Container should return pro_providers section when Pro is active'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Pro_Providers',
			$section,
			'Section should be instance of WP_MCP_AI_Section_Pro_Providers'
		);

		// Verify section is NOT registered with the Settings Registry.
		// It should only provide subtabs to the base Providers section, not render as standalone.
		$registered_section = WP_MCP_AI_Settings_Registry::get_section( 'pro_providers' );
		$this->assertNull(
			$registered_section,
			'Pro Providers section should NOT be registered in Settings Registry (prevents duplicate rendering)'
		);
	}

	/**
	 * Test that Pro Providers subtabs are merged into base Providers section.
	 *
	 * The Pro Providers section should not appear as a standalone section.
	 * Instead, its subtabs (like 'embedded') should be merged into the base
	 * Providers section's subtab navigation.
	 */
	public function test_pro_providers_subtabs_merged_into_base_section() {
		// Get all sections for the providers tab.
		$providers_sections = WP_MCP_AI_Settings_Registry::get_sections( 'providers' );

		// Verify the pro_providers section is NOT in the list (no standalone rendering).
		$found_pro_providers = false;
		foreach ( $providers_sections as $section ) {
			if ( $section->get_id() === 'pro_providers' ) {
				$found_pro_providers = true;
				break;
			}
		}

		$this->assertFalse(
			$found_pro_providers,
			'Pro Providers section should NOT be found as standalone section (subtabs are merged into base Providers)'
		);

		// Verify the base Providers section exists.
		$base_providers_found = false;
		foreach ( $providers_sections as $section ) {
			if ( $section->get_id() === 'providers' ) {
				$base_providers_found = true;
				break;
			}
		}

		$this->assertTrue(
			$base_providers_found,
			'Base Providers section should be found in providers tab'
		);
	}

	/**
	 * Test that Pro Integrations section is also registered when Pro is active.
	 */
	public function test_pro_integrations_section_registered_when_pro_active() {
		// Get the section from the container.
		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.pro_integrations' );

		// Verify section was created successfully.
		$this->assertNotNull(
			$section,
			'Container should return pro_integrations section when Pro is active'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Pro_Integrations',
			$section,
			'Section should be instance of WP_MCP_AI_Section_Pro_Integrations'
		);

		// Verify section is registered with the Settings Registry.
		$registered_section = WP_MCP_AI_Settings_Registry::get_section( 'pro_integrations' );
		$this->assertNotNull(
			$registered_section,
			'Pro Integrations section should be registered in Settings Registry'
		);
	}

	/**
	 * Test that base providers section still exists.
	 */
	public function test_base_providers_section_exists() {
		// Get the base providers section.
		$section = WP_MCP_AI_Settings_Registry::get_section( 'providers' );

		$this->assertNotNull(
			$section,
			'Base providers section should be registered'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Providers',
			$section,
			'Section should be instance of WP_MCP_AI_Section_Providers'
		);
		$this->assertEquals(
			'providers',
			$section->get_tab(),
			'Base providers section should be on the providers tab'
		);
		$this->assertEquals(
			10,
			$section->get_priority(),
			'Base providers section should have priority 10'
		);
	}

	/**
	 * Test that providers tab has only one section (base Providers).
	 *
	 * With the Pro addon active, the providers tab should have only the base
	 * Providers section. The Pro Providers subtabs are merged into it rather
	 * than appearing as a separate section.
	 */
	public function test_providers_tab_has_single_section() {
		// Get all sections for the providers tab.
		$providers_sections = WP_MCP_AI_Settings_Registry::get_sections( 'providers' );

		// Count sections with tab='providers'.
		$providers_tab_count = count( $providers_sections );

		// Should have exactly 1 section (base Providers with merged Pro subtabs).
		$this->assertEquals(
			1,
			$providers_tab_count,
			'Providers tab should have exactly 1 section (Pro subtabs are merged, not standalone)'
		);

		// Verify it's the base Providers section.
		$first_section = reset( $providers_sections );
		$this->assertEquals(
			'providers',
			$first_section->get_id(),
			'The single providers tab section should be the base Providers section'
		);
	}
}
