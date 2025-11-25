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
	 * Test WPCode tool dependency checking uses function_exists.
	 */
	public function test_wpcode_tool_dependency_check() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'check_tool_dependencies' );
		$method->setAccessible( true );

		// Test WPCode tool dependency.
		$result = $method->invoke( $section, 'create_wpcode_snippet' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'available', $result );
		$this->assertArrayHasKey( 'missing', $result );

		// In test environment, WPCode likely won't be available.
		if ( ! function_exists( 'wpcode' ) ) {
			$this->assertFalse( $result['available'], 'create_wpcode_snippet should not be available without WPCode' );
			$this->assertContains( 'WPCode', $result['missing'], 'WPCode should be in missing dependencies' );
		}
	}

	/**
	 * Test Simple JWT Login tool dependency checking uses correct class.
	 */
	public function test_simple_jwt_token_dependency_check() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'check_tool_dependencies' );
		$method->setAccessible( true );

		// Test Simple JWT Login tool dependency.
		$result = $method->invoke( $section, 'generate_simple_jwt_token' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'available', $result );
		$this->assertArrayHasKey( 'missing', $result );

		// In test environment, Simple JWT Login likely won't be available.
		if ( ! class_exists( '\\SimpleJWTLogin\\Modules\\WordPressData' ) ) {
			$this->assertFalse( $result['available'], 'generate_simple_jwt_token should not be available without Simple JWT Login' );
			$this->assertContains( 'Simple JWT Login', $result['missing'], 'Simple JWT Login should be in missing dependencies' );
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

	/**
	 * Test that GitHub OAuth fields are defined.
	 */
	public function test_github_oauth_fields_exist() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );
		$fields  = $section->get_fields();

		$this->assertArrayHasKey( 'github_client_id', $fields, 'github_client_id field should exist' );
		$this->assertArrayHasKey( 'github_client_secret', $fields, 'github_client_secret field should exist' );

		// Verify field types.
		$this->assertEquals( 'text', $fields['github_client_id']['type'], 'github_client_id should be text field' );
		$this->assertEquals( 'password', $fields['github_client_secret']['type'], 'github_client_secret should be password field' );

		// Verify labels.
		$this->assertStringContainsString( 'GitHub OAuth Client ID', $fields['github_client_id']['label'] );
		$this->assertStringContainsString( 'GitHub OAuth Client Secret', $fields['github_client_secret']['label'] );
	}

	/**
	 * Test that GitHub fields are in external_tools subtab.
	 */
	public function test_github_fields_in_external_tools_subtab() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		$this->assertArrayHasKey( 'external_tools', $subtabs, 'external_tools subtab should exist' );
		$this->assertContains( 'github_client_id', $subtabs['external_tools']['fields'], 'github_client_id should be in external_tools fields' );
		$this->assertContains( 'github_client_secret', $subtabs['external_tools']['fields'], 'github_client_secret should be in external_tools fields' );
	}

	/**
	 * Test GitHub connection status rendering when not connected.
	 */
	public function test_github_connection_status_not_connected() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Set up admin user for capability checks.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Clear any existing GitHub settings.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['github_access_token'] = '';
		$settings['github_username']     = '';
		$settings['github_client_id']    = '';
		$settings['github_client_secret'] = '';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Set up query string for external_tools subtab.
		$_GET['subtab'] = 'external_tools';

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Clear $_GET.
		unset( $_GET['subtab'] );

		// Should show setup instructions when no credentials.
		$this->assertStringContainsString( 'GitHub OAuth Credentials Required', $output, 'Should show credentials required message' );
		$this->assertStringContainsString( 'GitHub Developer Settings', $output, 'Should include link to GitHub Developer Settings' );
		$this->assertStringContainsString( 'admin-post.php?action=wp_mcp_ai_github_oauth_callback', $output, 'Should show callback URL' );
	}

	/**
	 * Test GitHub connection status rendering when connected.
	 */
	public function test_github_connection_status_connected() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Set up admin user for capability checks.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up GitHub as connected.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['github_access_token'] = 'test_token_12345';
		$settings['github_username']     = 'testuser';
		$settings['github_client_id']    = 'test_client_id';
		$settings['github_client_secret'] = 'test_client_secret';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Set up query string for external_tools subtab.
		$_GET['subtab'] = 'external_tools';

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Clear $_GET.
		unset( $_GET['subtab'] );

		// Should show connected status.
		$this->assertStringContainsString( 'Connected to GitHub', $output, 'Should show connected message' );
		$this->assertStringContainsString( 'testuser', $output, 'Should show GitHub username' );
		$this->assertStringContainsString( 'Reconnect GitHub Account', $output, 'Should show reconnect button' );
	}

	/**
	 * Test GitHub connection button when credentials configured but not connected.
	 */
	public function test_github_connect_button_with_credentials() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Set up admin user for capability checks.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up GitHub credentials but no access token.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['github_access_token'] = '';
		$settings['github_username']     = '';
		$settings['github_client_id']    = 'test_client_id';
		$settings['github_client_secret'] = 'test_client_secret';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Set up query string for external_tools subtab.
		$_GET['subtab'] = 'external_tools';

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Clear $_GET.
		unset( $_GET['subtab'] );

		// Should show connect button when credentials are configured.
		$this->assertStringContainsString( 'GitHub Not Connected', $output, 'Should show not connected warning' );
		$this->assertStringContainsString( 'Connect GitHub Account', $output, 'Should show connect button' );
		$this->assertStringContainsString( 'button-primary', $output, 'Connect button should be primary' );
		$this->assertStringContainsString( 'admin-post.php?action=wp_mcp_ai_github_oauth_start', $output, 'Should have OAuth start URL' );

		// Should show required permissions from OAuth handler constant.
		$expected_scopes = explode( ',', WP_MCP_AI_Github_OAuth_Handler::GITHUB_OAUTH_SCOPES );
		foreach ( $expected_scopes as $scope ) {
			$scope = trim( $scope );
			$this->assertStringContainsString( $scope, $output, "Should list {$scope} permission" );
		}
	}

	/**
	 * Test that tools manager filter bar has proper structure.
	 */
	public function test_tools_manager_filter_bar_has_proper_structure() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Set up the query string to simulate tools_manager subtab.
		$_GET['subtab'] = 'tools_manager';

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Clear $_GET.
		unset( $_GET['subtab'] );

		// Check that filter bar exists.
		$this->assertStringContainsString( 'wp-mcp-ai-tools-filter-bar', $output, 'Should contain filter bar' );
		$this->assertStringContainsString( 'wp-mcp-ai-tools-filter-form', $output, 'Should contain filter form div' );
		
		// Verify JavaScript filter button exists.
		$this->assertStringContainsString( 'id="wp-mcp-ai-filter-tools"', $output, 'Should have filter button' );
		$this->assertStringContainsString( 'type="button"', $output, 'Filter button should be type button' );

		// Verify search and filter inputs exist.
		$this->assertStringContainsString( 'name="tool_search"', $output, 'Should have tool_search input' );
		$this->assertStringContainsString( 'name="tool_group"', $output, 'Should have tool_group select' );
		
		// Verify JavaScript handler is included.
		$this->assertStringContainsString( '#wp-mcp-ai-filter-tools', $output, 'Should include JavaScript handler' );
		$this->assertStringContainsString( 'window.location.href', $output, 'Should navigate using JavaScript' );
	}

	/**
	 * Test that tools manager filter respects search parameter.
	 */
	public function test_tools_manager_filter_respects_search() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Set up the query string with search parameter.
		$_GET['subtab']     = 'tools_manager';
		$_GET['tool_search'] = 'test_search_term';

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Clear $_GET.
		unset( $_GET['subtab'], $_GET['tool_search'] );

		// Check that search value is preserved in the input.
		$this->assertStringContainsString( 'value="test_search_term"', $output, 'Search input should have the search value' );
		$this->assertStringContainsString( 'Clear', $output, 'Clear button should be visible when search is active' );
	}

	/**
	 * Test that tools manager filter respects category parameter.
	 */
	public function test_tools_manager_filter_respects_category() {
		$section = WP_MCP_AI_Settings_Registry::get_section( 'tools' );

		// Set up the query string with category filter.
		$_GET['subtab']    = 'tools_manager';
		$_GET['tool_group'] = 'wordpress-core';

		// Capture output.
		ob_start();
		$section->render();
		$output = ob_get_clean();

		// Clear $_GET.
		unset( $_GET['subtab'], $_GET['tool_group'] );

		// Check that category is selected.
		$this->assertStringContainsString( 'selected', $output, 'Selected category should be marked' );
		$this->assertStringContainsString( 'Clear', $output, 'Clear button should be visible when filter is active' );
	}
}
