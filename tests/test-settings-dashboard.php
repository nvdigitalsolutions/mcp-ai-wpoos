<?php
/**
 * Tests for Settings Dashboard
 *
 * @package WP_MCP_AI
 */

/**
 * Test Settings Dashboard functionality.
 */
class Test_Settings_Dashboard extends WP_UnitTestCase {

	/**
	 * Test that the settings registry can register sections.
	 */
	public function test_registry_registers_sections() {
		// Create a mock section.
		$section = $this->getMockBuilder( 'WP_MCP_AI_Settings_Section' )
			->setMethods( array( 'get_id', 'get_title', 'get_tab', 'get_fields', 'render' ) )
			->getMockForAbstractClass();

		$section->method( 'get_id' )->willReturn( 'test_section' );
		$section->method( 'get_tab' )->willReturn( 'test_tab' );

		// Register the section.
		WP_MCP_AI_Settings_Registry::register_section( $section );

		// Verify it was registered.
		$sections = WP_MCP_AI_Settings_Registry::get_sections();
		$this->assertArrayHasKey( 'test_section', $sections );
	}

	/**
	 * Test that sections are returned for a specific tab.
	 */
	public function test_get_sections_by_tab() {
		// Get general tab sections.
		$sections = WP_MCP_AI_Settings_Registry::get_sections( 'general' );

		// Should have at least the general section.
		$this->assertNotEmpty( $sections );
		$this->assertContainsOnlyInstancesOf( 'WP_MCP_AI_Settings_Section', $sections );
	}

	/**
	 * Test that tabs are defined.
	 */
	public function test_tabs_are_defined() {
		$tabs = WP_MCP_AI_Settings_Registry::get_tabs();

		$this->assertIsArray( $tabs );
		$this->assertArrayHasKey( 'general', $tabs );
		$this->assertArrayHasKey( 'providers', $tabs );
		$this->assertArrayHasKey( 'authentication', $tabs );
		$this->assertArrayHasKey( 'tools', $tabs );
		$this->assertArrayHasKey( 'integrations', $tabs );
		$this->assertArrayHasKey( 'security', $tabs );
		$this->assertArrayHasKey( 'advanced', $tabs );
	}

	/**
	 * Test URL validation.
	 */
	public function test_validator_validates_urls() {
		// Valid URL.
		$result = WP_MCP_AI_Settings_Validator::validate_url( 'https://example.com' );
		$this->assertTrue( $result );

		// Invalid URL.
		$result = WP_MCP_AI_Settings_Validator::validate_url( 'not-a-url' );
		$this->assertInstanceOf( 'WP_Error', $result );

		// Empty URL (should be valid for optional fields).
		$result = WP_MCP_AI_Settings_Validator::validate_url( '' );
		$this->assertTrue( $result );
	}

	/**
	 * Test email validation.
	 */
	public function test_validator_validates_emails() {
		// Valid email.
		$result = WP_MCP_AI_Settings_Validator::validate_email( 'test@example.com' );
		$this->assertTrue( $result );

		// Invalid email.
		$result = WP_MCP_AI_Settings_Validator::validate_email( 'not-an-email' );
		$this->assertInstanceOf( 'WP_Error', $result );

		// Empty email (should be valid for optional fields).
		$result = WP_MCP_AI_Settings_Validator::validate_email( '' );
		$this->assertTrue( $result );
	}

	/**
	 * Test number validation.
	 */
	public function test_validator_validates_numbers() {
		// Valid number.
		$result = WP_MCP_AI_Settings_Validator::validate_number( 50, 1, 100 );
		$this->assertTrue( $result );

		// Number too small.
		$result = WP_MCP_AI_Settings_Validator::validate_number( 0, 1, 100 );
		$this->assertInstanceOf( 'WP_Error', $result );

		// Number too large.
		$result = WP_MCP_AI_Settings_Validator::validate_number( 101, 1, 100 );
		$this->assertInstanceOf( 'WP_Error', $result );

		// Not a number.
		$result = WP_MCP_AI_Settings_Validator::validate_number( 'not-a-number' );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test section field rendering.
	 */
	public function test_section_renders_fields() {
		$section = new WP_MCP_AI_Section_General();

		$this->assertEquals( 'general', $section->get_id() );
		$this->assertEquals( 'general', $section->get_tab() );
		$this->assertIsArray( $section->get_fields() );
		$this->assertNotEmpty( $section->get_fields() );
	}

	/**
	 * Test providers section.
	 */
	public function test_providers_section_has_required_fields() {
		$section = new WP_MCP_AI_Section_Providers();
		$fields  = $section->get_fields();

		// Check for key provider fields.
		$this->assertArrayHasKey( 'openai_api_key', $fields );
		$this->assertArrayHasKey( 'gemini_api_key', $fields );
		$this->assertArrayHasKey( 'ollama_endpoint_url', $fields );
		$this->assertArrayHasKey( 'lm_studio_endpoint_url', $fields );
	}

	/**
	 * Test authentication section.
	 */
	public function test_authentication_section_has_required_fields() {
		$section = new WP_MCP_AI_Section_Authentication();
		$fields  = $section->get_fields();

		// Check for key auth fields.
		$this->assertArrayHasKey( 'auth0_domain', $fields );
		$this->assertArrayHasKey( 'auth0_audience', $fields );
		$this->assertArrayHasKey( 'enable_simple_jwt_login', $fields );
	}

	/**
	 * Test section validation.
	 */
	public function test_section_validates_input() {
		$section = new WP_MCP_AI_Section_General();

		// Valid input.
		$input  = array( 'max_history_messages' => 10 );
		$result = $section->validate( $input );
		$this->assertIsArray( $result );

		// Invalid input (out of range).
		$input  = array( 'max_history_messages' => 999 );
		$result = $section->validate( $input );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test section sanitization.
	 */
	public function test_section_sanitizes_input() {
		$section = new WP_MCP_AI_Section_General();

		$input     = array(
			'enable_logging'       => '1',
			'max_history_messages' => '10',
		);
		$sanitized = $section->sanitize( $input );

		$this->assertIsArray( $sanitized );
		$this->assertIsBool( $sanitized['enable_logging'] );
		$this->assertIsInt( $sanitized['max_history_messages'] );
	}

	/**
	 * Test that the new dashboard loads by default.
	 */
	public function test_new_dashboard_loads_by_default() {
		// By default, WP_MCP_AI_USE_OLD_SETTINGS should be false.
		$this->assertFalse( WP_MCP_AI_USE_OLD_SETTINGS );
		
		// The settings dashboard should be initialized.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Settings_Dashboard' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Settings_Registry' ) );
	}

	/**
	 * Test that the settings page is registered as a top-level menu.
	 */
	public function test_settings_page_registered_as_top_level_menu() {
		global $menu, $submenu;

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check that the menu is registered.
		$menu_registered = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $menu_item ) {
				if ( is_array( $menu_item ) && isset( $menu_item[2] ) && 'wp-mcp-ai-dashboard' === $menu_item[2] ) {
					$menu_registered = true;
					// Verify it has the correct icon.
					$this->assertEquals( 'dashicons-format-chat', $menu_item[6] );
					break;
				}
			}
		}

		$this->assertTrue( $menu_registered, 'WP oOS menu should be registered as a top-level menu item' );
	}

	/**
	 * Test that the General Settings submenu item is registered.
	 */
	public function test_general_settings_submenu_registered() {
		global $submenu;

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check that the submenu is registered under wp-mcp-ai-dashboard.
		$this->assertArrayHasKey( 'wp-mcp-ai-dashboard', $submenu, 'WP oOS should have submenu items' );

		// Find the General Settings submenu item.
		$general_settings_found = false;
		if ( isset( $submenu['wp-mcp-ai-dashboard'] ) && is_array( $submenu['wp-mcp-ai-dashboard'] ) ) {
			foreach ( $submenu['wp-mcp-ai-dashboard'] as $submenu_item ) {
				if ( is_array( $submenu_item ) && isset( $submenu_item[2] ) && 'wp-mcp-ai-dashboard' === $submenu_item[2] ) {
					$general_settings_found = true;
					// Verify the menu title.
					$this->assertEquals( 'General Settings', $submenu_item[0] );
					break;
				}
			}
		}

		$this->assertTrue( $general_settings_found, 'General Settings submenu item should be registered' );
	}
}
