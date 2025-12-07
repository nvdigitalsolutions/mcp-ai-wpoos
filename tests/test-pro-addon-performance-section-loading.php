<?php
/**
 * Test Pro Addon Performance Section Loading
 *
 * Verifies that the Performance Monitoring section is loaded correctly
 * when the Pro addon is active.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Pro addon Performance section loading
 */
class Test_Pro_Addon_Performance_Section_Loading extends WP_UnitTestCase {

	/**
	 * Test that Performance section class is loaded when Pro addon is active
	 */
	public function test_performance_section_class_loaded_when_pro_active() {
		// Check if Pro addon constant is defined (indicating Pro is active).
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon is not active. Skipping test.' );
		}

		// Verify that the Performance section class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Section_Performance' ),
			'WP_MCP_AI_Section_Performance class should exist when Pro addon is active'
		);
	}

	/**
	 * Test that Performance section can be instantiated
	 */
	public function test_performance_section_can_be_instantiated() {
		// Check if Pro addon constant is defined.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon is not active. Skipping test.' );
		}

		// Skip if class doesn't exist (Pro sections not loaded).
		if ( ! class_exists( 'WP_MCP_AI_Section_Performance' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Section_Performance class not loaded.' );
		}

		// Create an instance of the section.
		$section = new WP_MCP_AI_Section_Performance();

		// Verify instance was created.
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Performance',
			$section,
			'Should be able to instantiate WP_MCP_AI_Section_Performance'
		);
	}

	/**
	 * Test that container returns Performance section when Pro is active
	 */
	public function test_container_returns_performance_section_when_pro_active() {
		// Check if Pro addon constant is defined.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon is not active. Skipping test.' );
		}

		// Get container instance.
		$container = wp_mcp_ai_container();

		// Get performance section from container.
		$section = $container->get( 'section.performance' );

		// Verify section is not null.
		$this->assertNotNull(
			$section,
			'Container should return Performance section instance when Pro is active'
		);

		// Verify it's the correct class.
		$this->assertInstanceOf(
			'WP_MCP_AI_Section_Performance',
			$section,
			'Container should return instance of WP_MCP_AI_Section_Performance'
		);
	}

	/**
	 * Test that Performance section has required methods
	 */
	public function test_performance_section_has_required_methods() {
		// Check if Pro addon constant is defined.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon is not active. Skipping test.' );
		}

		// Verify that the class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Section_Performance' ),
			'WP_MCP_AI_Section_Performance class should exist'
		);

		// Verify required methods exist.
		$required_methods = array(
			'get_id',
			'get_title',
			'get_tab',
			'get_description',
			'render',
			'ajax_run_test',
			'ajax_get_metrics',
			'ajax_export_results',
		);

		foreach ( $required_methods as $method ) {
			$this->assertTrue(
				method_exists( 'WP_MCP_AI_Section_Performance', $method ),
				sprintf( 'WP_MCP_AI_Section_Performance should have method %s', $method )
			);
		}
	}

	/**
	 * Test that container returns null for Performance section when Pro is not active
	 */
	public function test_container_returns_null_when_pro_not_active() {
		// This test is only relevant if Pro is NOT active.
		if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon is active. Skipping test.' );
		}

		// Get container instance.
		$container = wp_mcp_ai_container();

		// Get performance section from container.
		$section = $container->get( 'section.performance' );

		// Verify section is null when Pro is not active.
		$this->assertNull(
			$section,
			'Container should return null for Performance section when Pro is not active'
		);
	}
}
