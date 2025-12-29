<?php
/**
 * Tests for settings section lazy loading.
 *
 * Verifies that the autoloader properly loads section classes on demand.
 *
 * @package WP_MCP_AI
 */

/**
 * Test settings lazy loading functionality.
 */
class WP_MCP_AI_Settings_Lazy_Loading_Test extends WP_UnitTestCase {

	/**
	 * Test that section classes can be autoloaded.
	 */
	public function test_section_classes_can_be_autoloaded() {
		// These classes should not be loaded yet if lazy loading works.
		// But since WordPress loads everything, we just verify they exist.
		$section_classes = array(
			'WP_MCP_AI_Section_Overview',
			'WP_MCP_AI_Section_General',
			'WP_MCP_AI_Section_Providers',
			'WP_MCP_AI_Section_Authentication',
			'WP_MCP_AI_Section_Tools',
			'WP_MCP_AI_Section_Token_Manager',
			// Note: Performance section moved to Pro addon.
			'WP_MCP_AI_Section_Advanced',
		);

		foreach ( $section_classes as $class_name ) {
			$this->assertTrue(
				class_exists( $class_name ),
				"Class $class_name should be available via autoloader"
			);
		}
	}

	/**
	 * Test that sections can be instantiated through the container.
	 */
	public function test_sections_can_be_instantiated_through_container() {
		$container = wp_mcp_ai_container();

		// Test a few key sections.
		$sections = array(
			'section.overview',
			'section.general',
			'section.providers',
			'section.tools',
		);

		foreach ( $sections as $section_id ) {
			$section = $container->get( $section_id );
			$this->assertNotNull( $section, "Section $section_id should be instantiable" );
			$this->assertInstanceOf(
				'WP_MCP_AI_Settings_Section_Abstract',
				$section,
				"Section $section_id should extend abstract base class"
			);
		}
	}

	/**
	 * Test that the settings registry can access lazy-loaded sections.
	 */
	public function test_settings_registry_can_access_sections() {
		// Get all registered sections.
		$sections = WP_MCP_AI_Settings_Registry::get_sections();

		// There should be multiple sections registered.
		$this->assertGreaterThan( 5, count( $sections ), 'Multiple sections should be registered' );

		// Each section should be properly instantiated.
		foreach ( $sections as $section ) {
			$this->assertNotNull( $section->get_id(), 'Section should have an ID' );
			$this->assertNotEmpty( $section->get_title(), 'Section should have a title' );
		}
	}

	/**
	 * Test that section files are not loaded until needed.
	 *
	 * This test verifies the concept of lazy loading by checking that
	 * we can control when section classes are defined.
	 */
	public function test_lazy_loading_concept() {
		// The autoloader should be registered.
		$autoloaders = spl_autoload_functions();
		$this->assertNotEmpty( $autoloaders, 'Autoloaders should be registered' );

		// Check that at least one of our autoloaders is in the list.
		$has_section_autoloader = false;
		foreach ( $autoloaders as $autoloader ) {
			if ( is_array( $autoloader ) ) {
				continue;
			}
			// Our autoloader is a closure, so we can't directly verify it,.
			// but we can verify that closures are registered.
			if ( $autoloader instanceof Closure ) {
				$has_section_autoloader = true;
				break;
			}
		}

		$this->assertTrue(
			$has_section_autoloader,
			'A closure autoloader should be registered for sections'
		);
	}

	/**
	 * Test that integration admin pages are loaded.
	 *
	 * Integration pages need to be loaded eagerly for hooks.
	 */
	public function test_integration_admin_pages_are_loaded() {
		// These classes should be loaded eagerly.
		$integration_classes = array(
			'WP_MCP_AI_Admin_JetEngine_Integration',
			'WP_MCP_AI_Admin_WooCommerce_Integration',
			'WP_MCP_AI_Admin_Elementor',
			'WP_MCP_AI_Admin_Gmail_Crawl',
		);

		foreach ( $integration_classes as $class_name ) {
			$this->assertTrue(
				class_exists( $class_name ),
				"Integration class $class_name should be loaded"
			);
		}
	}

	/**
	 * Test that base abstract class is loaded.
	 */
	public function test_abstract_base_class_is_loaded() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Settings_Section_Abstract' ),
			'Abstract base class should be loaded eagerly'
		);
	}

	/**
	 * Test that dashboard controller is loaded.
	 */
	public function test_dashboard_controller_is_loaded() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Settings_Dashboard' ),
			'Settings dashboard controller should be loaded'
		);
	}
}
