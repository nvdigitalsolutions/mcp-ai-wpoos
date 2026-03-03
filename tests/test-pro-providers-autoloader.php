<?php
/**
 * Test Pro Providers Autoloader
 *
 * Verifies that the production autoloader correctly handles Pro section classes
 * when the Pro addon is present.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Pro Providers autoloader functionality.
 */
class WP_MCP_AI_Pro_Providers_Autoloader_Test extends WP_UnitTestCase {

	/**
	 * Test that Pro sections are added to autoloader when Pro is active.
	 *
	 * This test verifies the fix for the autoloader not including Pro sections.
	 */
	public function test_autoloader_includes_pro_sections_when_pro_active() {
		// Verify Pro addon constant is defined.
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_VERSION' ),
			'WP_MCP_AI_PRO_VERSION should be defined when Pro addon is active'
		);

		// Test that Pro Providers class can be loaded via autoloader.
		// The class_exists() call with autoload=true should trigger the autoloader.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Section_Pro_Providers', true ),
			'Pro Providers section class should be autoloadable'
		);

		// Verify the class was actually loaded and is the correct type.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Section_Pro_Providers' );
		$this->assertTrue(
			$reflection->isSubclassOf( 'WP_MCP_AI_Settings_Section' ),
			'Pro Providers section should extend WP_MCP_AI_Settings_Section'
		);
	}

	/**
	 * Test that Performance section can be autoloaded.
	 */
	public function test_autoloader_includes_performance_section() {
		// Verify Pro is active.
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_VERSION' ),
			'WP_MCP_AI_PRO_VERSION should be defined'
		);

		// Test that Performance class can be loaded via autoloader.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Section_Performance', true ),
			'Performance section class should be autoloadable'
		);

		// Verify it's a valid settings section.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Section_Performance' );
		$this->assertTrue(
			$reflection->isSubclassOf( 'WP_MCP_AI_Settings_Section' ),
			'Performance section should extend WP_MCP_AI_Settings_Section'
		);
	}

	/**
	 * Test that Pro Integrations section can be autoloaded.
	 */
	public function test_autoloader_includes_pro_integrations_section() {
		// Verify Pro is active.
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_VERSION' ),
			'WP_MCP_AI_PRO_VERSION should be defined'
		);

		// Test that Pro Integrations class can be loaded via autoloader.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Section_Pro_Integrations', true ),
			'Pro Integrations section class should be autoloadable'
		);

		// Verify it's a valid settings section.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Section_Pro_Integrations' );
		$this->assertTrue(
			$reflection->isSubclassOf( 'WP_MCP_AI_Settings_Section' ),
			'Pro Integrations section should extend WP_MCP_AI_Settings_Section'
		);
	}

	/**
	 * Test that base sections still work with autoloader.
	 */
	public function test_autoloader_still_loads_base_sections() {
		// Test that base Providers class can be loaded via autoloader.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Section_Providers', true ),
			'Base Providers section class should be autoloadable'
		);

		// Verify it's a valid settings section.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Section_Providers' );
		$this->assertTrue(
			$reflection->isSubclassOf( 'WP_MCP_AI_Settings_Section' ),
			'Base Providers section should extend WP_MCP_AI_Settings_Section'
		);
	}

	/**
	 * Test that Pro section file paths are correct.
	 */
	public function test_pro_section_file_paths_exist() {
		// Verify Pro paths are defined.
		$this->assertTrue(
			defined( 'WP_MCP_AI_PRO_PATH' ),
			'WP_MCP_AI_PRO_PATH should be defined when Pro addon is active'
		);

		// Check that Pro section files actually exist.
		$pro_providers_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-pro-providers.php';
		$this->assertFileExists(
			$pro_providers_file,
			'Pro Providers section file should exist'
		);

		$performance_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-performance.php';
		$this->assertFileExists(
			$performance_file,
			'Performance section file should exist'
		);

		$pro_integrations_file = WP_MCP_AI_PRO_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-pro-integrations.php';
		$this->assertFileExists(
			$pro_integrations_file,
			'Pro Integrations section file should exist'
		);
	}

	/**
	 * Test that container can instantiate Pro sections via autoloader.
	 */
	public function test_container_can_instantiate_pro_sections() {
		// Get container instance.
		$container = wp_mcp_ai_container();

		// Test Pro Providers section.
		$pro_providers = $container->get( 'section.pro_providers' );
		$this->assertNotNull(
			$pro_providers,
			'Container should be able to get Pro Providers section'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Pro_Providers',
			$pro_providers,
			'Container should return correct Pro Providers instance'
		);

		// Test Performance section.
		$performance = $container->get( 'section.performance' );
		$this->assertNotNull(
			$performance,
			'Container should be able to get Performance section'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Performance',
			$performance,
			'Container should return correct Performance instance'
		);

		// Test Pro Integrations section.
		$pro_integrations = $container->get( 'section.pro_integrations' );
		$this->assertNotNull(
			$pro_integrations,
			'Container should be able to get Pro Integrations section'
		);
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Pro_Integrations',
			$pro_integrations,
			'Container should return correct Pro Integrations instance'
		);
	}

	/**
	 * Test that Pro sections are properly registered in Settings Registry or Container.
	 *
	 * Note: Pro Providers section is intentionally NOT registered in Settings Registry
	 * to prevent duplicate rendering. It's only available via the container.
	 */
	public function test_pro_sections_registered_correctly() {
		// Test Pro Providers section - should be in container but NOT in registry.
		$pro_providers = WP_MCP_AI_Settings_Registry::get_section( 'pro_providers' );
		$this->assertNull(
			$pro_providers,
			'Pro Providers section should NOT be registered in Settings Registry (prevents duplicate rendering)'
		);

		// But it should be available from the container.
		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container     = wp_mcp_ai_container();
			$pro_providers = $container->get( 'section.pro_providers' );
			$this->assertNotNull(
				$pro_providers,
				'Pro Providers section should be available from container'
			);
			$this->assertInstanceOf(
				'WP_MCP_AI_Section_Pro_Providers',
				$pro_providers,
				'Container should return correct Pro Providers instance'
			);
			$this->assertEquals(
				'providers',
				$pro_providers->get_tab(),
				'Pro Providers section should be on providers tab'
			);
		}

		// Test Performance section - should be in registry (renders standalone).
		$performance = WP_MCP_AI_Settings_Registry::get_section( 'performance' );
		$this->assertNotNull(
			$performance,
			'Performance section should be registered in Settings Registry'
		);

		// Test Pro Integrations section - should be in registry (renders standalone).
		$pro_integrations = WP_MCP_AI_Settings_Registry::get_section( 'pro_integrations' );
		$this->assertNotNull(
			$pro_integrations,
			'Pro Integrations section should be registered in Settings Registry'
		);
	}
}
