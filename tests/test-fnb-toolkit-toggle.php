<?php
/**
 * Tests for the Food & Beverage Toolkit toggle in settings.
 *
 * Verifies that `enable_fnb_toolkit` is defined as a field in the Tools
 * section AND listed in the "Pro Features" subtab group — the checkbox
 * renders only when both are wired (see render() in
 * WP_MCP_AI_Section_Tools), which was the original "toolkit not showing
 * up in the pro tools list" bug.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test that the Food & Beverage Toolkit checkbox is properly registered.
 */
class Test_Fnb_Toolkit_Toggle extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Get a reflected instance of the Tools section with private/protected
	 * methods accessible.
	 *
	 * @return array{0: WP_MCP_AI_Section_Tools, 1: ReflectionClass} Section instance and reflection.
	 */
	private function get_section_reflection() {
		require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-tools.php';

		$section = new WP_MCP_AI_Section_Tools();

		return array( $section, new ReflectionClass( $section ) );
	}

	/**
	 * Test that the enable_fnb_toolkit field exists in the Tools section.
	 */
	public function test_fnb_toolkit_field_exists() {
		list( $section, $reflection ) = $this->get_section_reflection();

		$get_fields = $reflection->getMethod( 'get_fields' );
		$get_fields->setAccessible( true );
		$fields = $get_fields->invoke( $section );

		$this->assertArrayHasKey( 'enable_fnb_toolkit', $fields, 'F&B toolkit field should be defined' );

		$field = $fields['enable_fnb_toolkit'];
		$this->assertEquals( 'checkbox', $field['type'], 'Field should be a checkbox' );
		$this->assertArrayHasKey( 'label', $field, 'Field should have a label' );
		$this->assertArrayHasKey( 'checkbox_label', $field, 'Field should have a checkbox label' );
		$this->assertArrayHasKey( 'description', $field, 'Field should have a description' );
		$this->assertFalse( $field['default'], 'Field should default to false' );
	}

	/**
	 * Test that the F&B toolkit field is included in the Features subtab.
	 *
	 * This is the regression guard: render() only outputs fields listed in
	 * the active subtab group, so a field missing here never renders.
	 */
	public function test_fnb_toolkit_in_features_subtab() {
		list( $section, $reflection ) = $this->get_section_reflection();

		$get_subtab_groups = $reflection->getMethod( 'get_subtab_groups' );
		$get_subtab_groups->setAccessible( true );
		$subtab_groups = $get_subtab_groups->invoke( $section );

		$this->assertArrayHasKey( 'features', $subtab_groups, 'Features subtab should exist' );

		$features_fields = $subtab_groups['features']['fields'];
		$this->assertContains( 'enable_fnb_toolkit', $features_fields, 'F&B toolkit field should be in Features subtab' );
	}

	/**
	 * Test that the F&B toolkit has a memory requirement defined.
	 */
	public function test_fnb_toolkit_memory_requirement() {
		list( $section, $reflection ) = $this->get_section_reflection();

		$get_memory = $reflection->getMethod( 'get_toolkit_memory_requirements' );
		$get_memory->setAccessible( true );
		$memory_requirements = $get_memory->invoke( $section );

		$this->assertArrayHasKey( 'enable_fnb_toolkit', $memory_requirements, 'F&B toolkit should have memory requirement' );

		$memory = $memory_requirements['enable_fnb_toolkit'];
		$this->assertIsInt( $memory, 'Memory requirement should be an integer' );
		$this->assertGreaterThan( 0, $memory, 'Memory requirement should be greater than 0' );
	}

	/**
	 * Test that the F&B toolkit setting can be saved and read back.
	 */
	public function test_fnb_toolkit_setting_can_be_saved() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_fnb_toolkit' => true ) );

		$saved = get_option( 'wp_mcp_ai_settings' );
		$this->assertArrayHasKey( 'enable_fnb_toolkit', $saved );
		$this->assertTrue( $saved['enable_fnb_toolkit'] );

		update_option( 'wp_mcp_ai_settings', array( 'enable_fnb_toolkit' => false ) );

		$saved = get_option( 'wp_mcp_ai_settings' );
		$this->assertFalse( $saved['enable_fnb_toolkit'] );
	}
}
