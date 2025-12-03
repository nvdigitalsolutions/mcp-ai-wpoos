<?php
/**
 * Tests to verify site creator is hidden in base version.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that site creator features are properly hidden in base version.
 */
class WP_MCP_AI_Site_Creator_Base_Version_Test extends WP_UnitTestCase {

	/**
	 * Test that site creator subtab is not registered in base version.
	 */
	public function test_site_creator_subtab_not_registered_in_base_version() {
		// Simulate base version mode.
		$callback = function ( $is_base ) {
			return true;
		};
		add_filter( 'wp_mcp_ai_base_version', $callback, 999 );

		// Create the Tools section instance.
		$section = new WP_MCP_AI_Section_Tools();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		// Verify site_creator subtab does not exist.
		$this->assertArrayNotHasKey( 'site_creator', $subtab_groups, 'Site creator subtab should not be registered in base version' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_base_version', $callback, 999 );
	}

	/**
	 * Test that site creator subtab IS registered in full version.
	 */
	public function test_site_creator_subtab_registered_in_full_version() {
		// Simulate full version mode.
		$callback = function ( $is_base ) {
			return false;
		};
		add_filter( 'wp_mcp_ai_base_version', $callback, 999 );

		// Create the Tools section instance.
		$section = new WP_MCP_AI_Section_Tools();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		$subtab_groups = $method->invoke( $section );

		// Verify site_creator subtab exists.
		$this->assertArrayHasKey( 'site_creator', $subtab_groups, 'Site creator subtab should be registered in full version' );
		$this->assertEquals( 'Site Creator', $subtab_groups['site_creator']['label'], 'Site creator subtab should have correct label' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_base_version', $callback, 999 );
	}

	/**
	 * Test that site creator fields are not defined in base version.
	 */
	public function test_site_creator_fields_not_defined_in_base_version() {
		// Simulate base version mode.
		$callback = function ( $is_base ) {
			return true;
		};
		add_filter( 'wp_mcp_ai_base_version', $callback, 999 );

		// Create the Tools section instance.
		$section = new WP_MCP_AI_Section_Tools();
		$fields  = $section->get_fields();

		// Verify site creator fields do not exist.
		$this->assertArrayNotHasKey( 'enable_site_creator', $fields, 'enable_site_creator field should not be defined in base version' );
		$this->assertArrayNotHasKey( 'site_creator_allow_plugin_install', $fields, 'site_creator_allow_plugin_install field should not be defined in base version' );
		$this->assertArrayNotHasKey( 'site_creator_allow_theme_install', $fields, 'site_creator_allow_theme_install field should not be defined in base version' );
		$this->assertArrayNotHasKey( 'site_creator_allow_option_updates', $fields, 'site_creator_allow_option_updates field should not be defined in base version' );
		$this->assertArrayNotHasKey( 'site_creator_allow_wp_cli_tools', $fields, 'site_creator_allow_wp_cli_tools field should not be defined in base version' );
		$this->assertArrayNotHasKey( 'site_creator_allow_elementor_kit_import', $fields, 'site_creator_allow_elementor_kit_import field should not be defined in base version' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_base_version', $callback, 999 );
	}

	/**
	 * Test that site creator fields ARE defined in full version.
	 */
	public function test_site_creator_fields_defined_in_full_version() {
		// Simulate full version mode (default in test environment).
		$callback = function ( $is_base ) {
			return false;
		};
		add_filter( 'wp_mcp_ai_base_version', $callback, 999 );

		// Create the Tools section instance.
		$section = new WP_MCP_AI_Section_Tools();
		$fields  = $section->get_fields();

		// Verify site creator fields exist.
		$this->assertArrayHasKey( 'enable_site_creator', $fields, 'enable_site_creator field should be defined in full version' );
		$this->assertArrayHasKey( 'site_creator_allow_plugin_install', $fields, 'site_creator_allow_plugin_install field should be defined in full version' );
		$this->assertArrayHasKey( 'site_creator_allow_theme_install', $fields, 'site_creator_allow_theme_install field should be defined in full version' );
		$this->assertArrayHasKey( 'site_creator_allow_option_updates', $fields, 'site_creator_allow_option_updates field should be defined in full version' );
		$this->assertArrayHasKey( 'site_creator_allow_wp_cli_tools', $fields, 'site_creator_allow_wp_cli_tools field should be defined in full version' );
		$this->assertArrayHasKey( 'site_creator_allow_elementor_kit_import', $fields, 'site_creator_allow_elementor_kit_import field should be defined in full version' );

		// Verify field structure.
		$this->assertEquals( 'checkbox', $fields['enable_site_creator']['type'], 'enable_site_creator should be a checkbox field' );
		$this->assertEquals( 'Enable Site Creator', $fields['enable_site_creator']['label'], 'enable_site_creator should have correct label' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_base_version', $callback, 999 );
	}
}
