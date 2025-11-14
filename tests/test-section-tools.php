<?php
/**
 * Tests for Tools Section
 *
 * @package WP_MCP_AI
 */

/**
 * Test Tools Section functionality.
 */
class Test_Section_Tools extends WP_UnitTestCase {

	/**
	 * Test that tools section is registered.
	 */
	public function test_tools_section_is_registered() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
		$this->assertEquals( 'tools', $section->get_id() );
	}

	/**
	 * Test that tools section has correct tab.
	 */
	public function test_tools_section_has_correct_tab() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		$this->assertEquals( 'tools', $section->get_tab() );
	}

	/**
	 * Test that tools section has correct title.
	 */
	public function test_tools_section_has_correct_title() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		$this->assertEquals( 'Tools & Features Configuration', $section->get_title() );
	}

	/**
	 * Test that tools section has fields defined.
	 */
	public function test_tools_section_has_fields() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );
		$fields  = $section->get_fields();

		$this->assertIsArray( $fields );
		$this->assertNotEmpty( $fields );

		// Check for known fields.
		$this->assertArrayHasKey( 'enable_mesh_computing', $fields );
		$this->assertArrayHasKey( 'enable_federation', $fields );
		$this->assertArrayHasKey( 'enable_ai_media_library', $fields );
		$this->assertArrayHasKey( 'enable_ai_comments_moderation', $fields );
		$this->assertArrayHasKey( 'enable_site_creator', $fields );
	}

	/**
	 * Test that tools manager subtab is available.
	 */
	public function test_tools_manager_subtab_exists() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		$this->assertIsArray( $subtabs );
		$this->assertArrayHasKey( 'tools_manager', $subtabs );
		$this->assertEquals( 'Tools Manager', $subtabs['tools_manager']['label'] );
		$this->assertEquals( 'dashicons-list-view', $subtabs['tools_manager']['icon'] );
		$this->assertEmpty( $subtabs['tools_manager']['fields'], 'Tools Manager should have no form fields (custom rendering)' );
	}

	/**
	 * Test that all subtabs are correctly defined.
	 */
	public function test_all_subtabs_are_defined() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		// Check for expected subtabs.
		$expected_subtabs = array( 'tools_manager', 'features', 'media', 'comments', 'site_creator' );

		foreach ( $expected_subtabs as $expected_subtab ) {
			$this->assertArrayHasKey( $expected_subtab, $subtabs, "Subtab '$expected_subtab' should exist" );
		}
	}

	/**
	 * Test that tools_manager is the default active subtab.
	 */
	public function test_tools_manager_is_default_subtab() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_active_subtab' );
		$method->setAccessible( true );

		// Clear $_GET to ensure default is used.
		$_GET = array();

		$active_subtab = $method->invoke( $section );

		$this->assertEquals( 'tools_manager', $active_subtab, 'tools_manager should be the default active subtab' );
	}

	/**
	 * Test that tool dependency checking works.
	 */
	public function test_tool_dependency_checking() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'check_tool_dependencies' );
		$method->setAccessible( true );

		// Test a tool that should always be available (core WordPress tool).
		$result = $method->invoke( $section, 'search_content' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'available', $result );
		$this->assertArrayHasKey( 'missing', $result );
		$this->assertTrue( $result['available'], 'search_content should be available' );
		$this->assertEmpty( $result['missing'], 'search_content should have no missing dependencies' );

		// Test a tool that requires a plugin (assuming WooCommerce is not installed in test env).
		$result = $method->invoke( $section, 'get_woo_products' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'available', $result );
		$this->assertArrayHasKey( 'missing', $result );

		// In test environment, WooCommerce likely won't be available.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->assertFalse( $result['available'], 'get_woo_products should not be available without WooCommerce' );
			$this->assertContains( 'WooCommerce', $result['missing'], 'WooCommerce should be in missing dependencies' );
		}
	}

	/**
	 * Test that tool display name generation works.
	 */
	public function test_tool_display_name_generation() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_tool_display_name' );
		$method->setAccessible( true );

		// Test slug to display name conversion.
		$this->assertEquals( 'Search Content', $method->invoke( $section, 'search_content' ) );
		$this->assertEquals( 'Get Woo Products', $method->invoke( $section, 'get_woo_products' ) );
		$this->assertEquals( 'Enable Ai Media Library', $method->invoke( $section, 'enable_ai_media_library' ) );
	}

	/**
	 * Test that tools section can render without errors.
	 */
	public function test_tools_section_renders_without_errors() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Set up the query string to simulate tools_manager subtab.
		$_GET['subtab'] = 'tools_manager';

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Clear $_GET.
		unset( $_GET['subtab'] );

		// Check that output contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-tools-manager', $output, 'Should contain tools manager wrapper' );
		$this->assertStringContainsString( 'Tools Manager', $output, 'Should contain Tools Manager heading' );
		$this->assertStringContainsString( 'tool_search', $output, 'Should contain search input' );
		$this->assertStringContainsString( 'tool_group', $output, 'Should contain category filter' );
	}
}
