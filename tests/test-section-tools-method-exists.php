<?php
/**
 * Test that WP_MCP_AI_Section_Tools has handle_elementor_kit_import method
 *
 * This test verifies the fix for the fatal error where the method was
 * not found at runtime due to PR #2926 adding a filter that was misused.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for WP_MCP_AI_Section_Tools method existence.
 */
class Test_Section_Tools_Method_Exists extends WP_UnitTestCase {

	/**
	 * Test that handle_elementor_kit_import method exists on the section.
	 */
	public function test_handle_elementor_kit_import_method_exists() {
		// Get the section from container (this is how it's used in production).
		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Verify the section is the correct class.
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );

		// Verify the method exists.
		$this->assertTrue(
			method_exists( $section, 'handle_elementor_kit_import' ),
			'Method handle_elementor_kit_import should exist on WP_MCP_AI_Section_Tools'
		);

		// Verify the method is public (can be called as a hook callback).
		$reflection = new ReflectionMethod( $section, 'handle_elementor_kit_import' );
		$this->assertTrue(
			$reflection->isPublic(),
			'Method handle_elementor_kit_import should be public'
		);
	}

	/**
	 * Test that the method can be called as a callback.
	 */
	public function test_handle_elementor_kit_import_is_callable() {
		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Verify it's callable as an array callback (WordPress hook style).
		$callback = array( $section, 'handle_elementor_kit_import' );
		$this->assertTrue(
			is_callable( $callback ),
			'Method should be callable as array callback'
		);
	}

	/**
	 * Test that the hook is registered in the constructor.
	 */
	public function test_admin_init_hook_registered() {
		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Check if the action is registered.
		$has_action = has_action( 'admin_init', array( $section, 'handle_elementor_kit_import' ) );
		$this->assertNotFalse(
			$has_action,
			'admin_init hook should be registered for handle_elementor_kit_import'
		);
	}
}
