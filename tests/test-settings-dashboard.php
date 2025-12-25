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
		$this->assertArrayHasKey( 'token_manager', $tabs );
		$this->assertArrayHasKey( 'security', $tabs );
		$this->assertArrayHasKey( 'advanced', $tabs );
	}

	/**
	 * Test that token manager tab is correctly configured.
	 */
	public function test_token_manager_tab_is_configured() {
		$tabs = WP_MCP_AI_Settings_Registry::get_tabs();

		$this->assertArrayHasKey( 'token_manager', $tabs );
		$this->assertEquals( 'Token Manager', $tabs['token_manager']['title'] );
		$this->assertEquals( 'dashicons-chart-bar', $tabs['token_manager']['icon'] );
	}

	/**
	 * Test that token manager section is registered.
	 */
	public function test_token_manager_section_is_registered() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'token_manager' );

		$this->assertInstanceOf( 'WP_MCP_AI_Section_Token_Manager', $section );
		$this->assertEquals( 'token_manager', $section->get_id() );
		$this->assertEquals( 'token_manager', $section->get_tab() );
		$this->assertEquals( 'Token Usage Manager', $section->get_title() );
	}

	/**
	 * Test that token manager section has proper fields.
	 */
	public function test_token_manager_section_fields() {
		$section = new WP_MCP_AI_Section_Token_Manager();
		$fields  = $section->get_fields();

		// Token manager is a custom section with no standard fields.
		$this->assertIsArray( $fields );
		$this->assertEmpty( $fields );
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
	 * Test that checkboxes are properly saved when unchecked.
	 */
	public function test_section_sanitizes_unchecked_checkboxes() {
		$section = new WP_MCP_AI_Section_Authentication();

		// Simulate form submission where checkbox is checked.
		$input_checked = array(
			'enable_auth0_github_bridge' => '1',
		);
		$sanitized     = $section->sanitize( $input_checked );
		$this->assertTrue( $sanitized['enable_auth0_github_bridge'] );

		// Simulate form submission where checkbox is unchecked (not in POST data).
		$input_unchecked = array();
		$sanitized       = $section->sanitize( $input_unchecked );
		$this->assertFalse( $sanitized['enable_auth0_github_bridge'] );
	}

	/**
	 * Test that the new dashboard loads.
	 */
	public function test_new_dashboard_loads() {
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

		$this->assertTrue( $menu_registered, 'NV oOS menu should be registered as a top-level menu item' );
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
		$this->assertArrayHasKey( 'wp-mcp-ai-dashboard', $submenu, 'NV oOS should have submenu items' );

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

	/**
	 * Test that sanitize_settings clears the settings cache.
	 */
	public function test_sanitize_settings_clears_cache() {
		// Set up the settings dashboard.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// First, populate the cache by getting settings.
		$initial_settings = WP_MCP_AI_Admin_Settings::get_settings();
		$this->assertIsArray( $initial_settings );

		// Now sanitize settings (which should clear the cache).
		$input     = array( 'enable_logging' => '1' );
		$sanitized = $dashboard->sanitize_settings( $input );

		// The cache should have been cleared.
		// We can verify this by checking that the static cache property is null.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Admin_Settings' );
		$cache_prop = $reflection->getProperty( 'settings_cache' );
		$cache_prop->setAccessible( true );
		$cache = $cache_prop->getValue();

		$this->assertNull( $cache, 'Settings cache should be cleared after sanitize_settings is called' );
	}

	/**
	 * Test that dashboard assets use WP_MCP_AI_PATH and WP_MCP_AI_URL constants.
	 *
	 * This test verifies that the enqueue_assets method uses the plugin constants
	 * instead of calculating paths with dirname(__FILE__), ensuring consistency
	 * across nested directory structures.
	 */
	public function test_dashboard_assets_use_constants() {
		// Set current admin screen to the dashboard page.
		set_current_screen( 'toplevel_page_wp-mcp-ai-dashboard' );

		// Create dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Hook into wp_enqueue_scripts to capture enqueued assets.
		$enqueued_scripts = array();
		$enqueued_styles  = array();

		add_filter(
			'script_loader_src',
			function ( $src, $handle ) use ( &$enqueued_scripts ) {
				$enqueued_scripts[ $handle ] = $src;
				return $src;
			},
			10,
			2
		);

		add_filter(
			'style_loader_src',
			function ( $src, $handle ) use ( &$enqueued_styles ) {
				$enqueued_styles[ $handle ] = $src;
				return $src;
			},
			10,
			2
		);

		// Call enqueue_assets method.
		$dashboard->enqueue_assets( 'toplevel_page_wp-mcp-ai-dashboard' );

		// Verify that scripts are enqueued with URLs from WP_MCP_AI_URL constant.
		$this->assertArrayHasKey( 'wp-mcp-ai-ajax-error-service', $enqueued_scripts, 'AJAX error service script should be enqueued' );
		$this->assertArrayHasKey( 'wp-mcp-ai-dashboard', $enqueued_scripts, 'Dashboard script should be enqueued' );

		// Verify that style is enqueued.
		$this->assertArrayHasKey( 'wp-mcp-ai-dashboard', $enqueued_styles, 'Dashboard style should be enqueued' );

		// Verify URLs contain the WP_MCP_AI_URL constant value.
		$this->assertStringContainsString( WP_MCP_AI_URL . 'assets/js/ajax-error-service.js', $enqueued_scripts['wp-mcp-ai-ajax-error-service'], 'Script should use WP_MCP_AI_URL constant' );
		$this->assertStringContainsString( WP_MCP_AI_URL . 'assets/js/settings-dashboard.js', $enqueued_scripts['wp-mcp-ai-dashboard'], 'Script should use WP_MCP_AI_URL constant' );
		$this->assertStringContainsString( WP_MCP_AI_URL . 'assets/css/settings-dashboard.css', $enqueued_styles['wp-mcp-ai-dashboard'], 'Style should use WP_MCP_AI_URL constant' );

		// Verify that asset files exist at the WP_MCP_AI_PATH location.
		$this->assertFileExists( WP_MCP_AI_PATH . 'assets/css/settings-dashboard.css', 'Dashboard CSS file should exist' );
		$this->assertFileExists( WP_MCP_AI_PATH . 'assets/js/ajax-error-service.js', 'AJAX error service JS file should exist' );
		$this->assertFileExists( WP_MCP_AI_PATH . 'assets/js/settings-dashboard.js', 'Dashboard JS file should exist' );
	}

	/**
	 * Test that admin footer text is hidden on the settings dashboard.
	 */
	public function test_hide_admin_footer_text_on_settings_dashboard() {
		// Create settings dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Mock the screen object for the settings dashboard.
		set_current_screen( 'toplevel_page_wp-mcp-ai-dashboard' );

		// Test that footer text is hidden.
		$result = $dashboard->hide_admin_footer_text( 'Thank you for creating with WordPress.' );
		$this->assertSame( '', $result );

		// Clean up.
		set_current_screen( 'dashboard' );
	}

	/**
	 * Test that admin footer text is not hidden on other pages.
	 */
	public function test_hide_admin_footer_text_preserves_text_on_other_pages() {
		// Create settings dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Mock the screen object for a different page.
		set_current_screen( 'dashboard' );

		// Test that footer text is preserved.
		$original_text = 'Thank you for creating with WordPress.';
		$result        = $dashboard->hide_admin_footer_text( $original_text );
		$this->assertSame( $original_text, $result );
	}

	/**
	 * Test that update footer text is hidden on the settings dashboard.
	 */
	public function test_hide_update_footer_text_on_settings_dashboard() {
		// Create settings dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Mock the screen object for the settings dashboard.
		set_current_screen( 'toplevel_page_wp-mcp-ai-dashboard' );

		// Test that version footer text is hidden.
		$result = $dashboard->hide_update_footer_text( 'Version 6.8.3' );
		$this->assertSame( '', $result );

		// Clean up.
		set_current_screen( 'dashboard' );
	}

	/**
	 * Test that update footer text is not hidden on other pages.
	 */
	public function test_hide_update_footer_text_preserves_text_on_other_pages() {
		// Create settings dashboard instance.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Mock the screen object for a different page.
		set_current_screen( 'dashboard' );

		// Test that version footer text is preserved.
		$original_text = 'Version 6.8.3';
		$result        = $dashboard->hide_update_footer_text( $original_text );
		$this->assertSame( $original_text, $result );
	}

	/**
	 * Test that get_asset_file returns minified version in production mode.
	 */
	public function test_get_asset_file_returns_minified_in_production() {
		// Ensure SCRIPT_DEBUG is not set.
		if ( defined( 'SCRIPT_DEBUG' ) ) {
			$this->markTestSkipped( 'SCRIPT_DEBUG is enabled, skipping production test' );
		}

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_asset_file' );
		$method->setAccessible( true );

		// Test with a JS file that has a minified version.
		$result = $method->invoke( $dashboard, 'assets/js/settings-dashboard.js' );

		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertArrayHasKey( 'version', $result );
		$this->assertStringContainsString( 'settings-dashboard.min.js', $result['url'] );
	}

	/**
	 * Test that get_asset_file returns unminified version when minified doesn't exist.
	 */
	public function test_get_asset_file_falls_back_to_unminified() {
		// Ensure SCRIPT_DEBUG is not set.
		if ( defined( 'SCRIPT_DEBUG' ) ) {
			$this->markTestSkipped( 'SCRIPT_DEBUG is enabled, skipping production test' );
		}

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_asset_file' );
		$method->setAccessible( true );

		// Test with a JS file that doesn't have a minified version.
		$result = $method->invoke( $dashboard, 'assets/js/storage-util.js' );

		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertArrayHasKey( 'version', $result );
		// Should fall back to unminified.
		$this->assertStringContainsString( 'storage-util.js', $result['url'] );
		$this->assertStringNotContainsString( '.min.', $result['url'] );
	}

	/**
	 * Test that get_asset_file handles CSS files correctly.
	 */
	public function test_get_asset_file_handles_css_files() {
		// Ensure SCRIPT_DEBUG is not set.
		if ( defined( 'SCRIPT_DEBUG' ) ) {
			$this->markTestSkipped( 'SCRIPT_DEBUG is enabled, skipping production test' );
		}

		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'get_asset_file' );
		$method->setAccessible( true );

		// Test with a CSS file that has a minified version.
		$result = $method->invoke( $dashboard, 'assets/css/settings-dashboard.css' );

		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertArrayHasKey( 'version', $result );
		$this->assertStringContainsString( 'settings-dashboard.min.css', $result['url'] );
	}
}
