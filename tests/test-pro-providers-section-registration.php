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
	 * Test that Pro Providers section is registered when Pro is active.
	 */
	public function test_pro_providers_section_registered_when_pro_active() {
		// Verify Pro addon constant is defined.
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_VERSION' ),
			'WP_MCP_AI_PRO_VERSION should be defined when Pro addon is active'
		);

		// Get the section from the container.
		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.pro_providers' );

		// Verify section was created successfully.
		$this->assertNotNull(
			$section,
			'Container should return pro_providers section when Pro is active'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Pro_Providers',
			$section,
			'Section should be instance of WP_MCP_AI_Section_Pro_Providers'
		);

		// Verify section is registered with the Settings Registry.
		$registered_section = WP_MCP_AI_Settings_Registry::get_section( 'pro_providers' );
		$this->assertNotNull(
			$registered_section,
			'Pro Providers section should be registered in Settings Registry'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Pro_Providers',
			$registered_section,
			'Registered section should be instance of WP_MCP_AI_Section_Pro_Providers'
		);
	}

	/**
	 * Test that Pro Providers section appears in providers tab.
	 */
	public function test_pro_providers_section_in_providers_tab() {
		// Get all sections for the providers tab.
		$providers_sections = WP_MCP_AI_Settings_Registry::get_sections( 'providers' );

		// Find the pro_providers section.
		$found_pro_providers = false;
		foreach ( $providers_sections as $section ) {
			if ( $section->get_id() === 'pro_providers' ) {
				$found_pro_providers = true;
				// Verify it's on the providers tab.
				$this->assertEquals(
					'providers',
					$section->get_tab(),
					'Pro Providers section should be on the providers tab'
				);
				// Verify it has the correct priority (should be after base providers).
				$this->assertEquals(
					15,
					$section->get_priority(),
					'Pro Providers section should have priority 15 (after base providers at 10)'
				);
				break;
			}
		}

		$this->assertTrue(
			$found_pro_providers,
			'Pro Providers section should be found in providers tab sections'
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
	 * Test that sections are sorted by priority in providers tab.
	 */
	public function test_providers_tab_sections_sorted_by_priority() {
		// Get all sections for the providers tab.
		$providers_sections = WP_MCP_AI_Settings_Registry::get_sections( 'providers' );

		// Verify we have at least 2 sections (base and pro).
		$this->assertGreaterThanOrEqual(
			2,
			count( $providers_sections ),
			'Providers tab should have at least base and pro sections'
		);

		// Verify sections are sorted by priority.
		$previous_priority = -1;
		foreach ( $providers_sections as $section ) {
			$current_priority = $section->get_priority();
			$this->assertGreaterThanOrEqual(
				$previous_priority,
				$current_priority,
				'Sections should be sorted by priority'
			);
			$previous_priority = $current_priority;
		}
	}
}
