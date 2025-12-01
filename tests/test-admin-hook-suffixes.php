<?php
/**
 * Tests for Admin Hook Suffixes
 *
 * Verifies that submenu pages properly store and use hook suffixes
 * returned by add_submenu_page().
 *
 * @package WP_MCP_AI
 */

/**
 * Test admin hook suffix functionality.
 */
class Test_Admin_Hook_Suffixes extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Test that MCP Server Diagnostic page stores hook suffix.
	 */
	public function test_mcp_diagnostic_page_hook() {
		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Get the hook suffix property using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_MCP_Server_Diagnostic' );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hook = $property->getValue();

		// Verify hook suffix is not empty and follows expected pattern.
		$this->assertNotEmpty( $hook, 'MCP Diagnostic page hook should be stored' );
		$this->assertStringContainsString( 'wp-mcp-ai-mcp-diagnostic', $hook, 'Hook should contain the page slug' );
	}

	/**
	 * Test that Auth0 Setup page stores hook suffix.
	 */
	public function test_auth0_setup_page_hook() {
		// Create an instance.
		$auth0_setup = new WP_MCP_AI_Auth0_Setup();

		// Trigger the admin_menu action.
		do_action( 'admin_menu' );

		// Get the hook suffix property using reflection.
		$reflection = new ReflectionClass( $auth0_setup );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hook = $property->getValue( $auth0_setup );

		// Verify hook suffix is not empty and follows expected pattern.
		$this->assertNotEmpty( $hook, 'Auth0 Setup page hook should be stored' );
		$this->assertStringContainsString( 'wp-mcp-ai-auth0-setup', $hook, 'Hook should contain the page slug' );
	}

	/**
	 * Test that Cron Manager page stores hook suffix.
	 */
	public function test_cron_manager_page_hook() {
		// Create an instance.
		$cron_manager = new WP_MCP_AI_Admin_Cron_Manager();

		// Trigger the admin_menu action.
		do_action( 'admin_menu' );

		// Get the hook suffix property using reflection.
		$reflection = new ReflectionClass( $cron_manager );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hook = $property->getValue( $cron_manager );

		// Verify hook suffix is not empty and follows expected pattern.
		$this->assertNotEmpty( $hook, 'Cron Manager page hook should be stored' );
		$this->assertStringContainsString( 'wp-mcp-ai-cron-manager', $hook, 'Hook should contain the page slug' );
	}

	/**
	 * Test that Provider Diagnostics page stores hook suffix.
	 */
	public function test_provider_diagnostics_page_hook() {
		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Get the hook suffix property using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Provider_Diagnostics' );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hook = $property->getValue();

		// Verify hook suffix is not empty and follows expected pattern.
		$this->assertNotEmpty( $hook, 'Provider Diagnostics page hook should be stored' );
		$this->assertStringContainsString( 'wp-mcp-ai-provider-diagnostic', $hook, 'Hook should contain the page slug' );
	}

	/**
	 * Test that hook suffixes are unique across pages.
	 */
	public function test_hook_suffixes_are_unique() {
		// Create instances.
		$auth0_setup  = new WP_MCP_AI_Auth0_Setup();
		$cron_manager = new WP_MCP_AI_Admin_Cron_Manager();

		// Trigger the admin_menu action.
		do_action( 'admin_menu' );

		// Get all hook suffixes.
		$hooks = array();

		// MCP Diagnostic.
		$reflection = new ReflectionClass( 'WP_MCP_AI_MCP_Server_Diagnostic' );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hooks[] = $property->getValue();

		// Auth0 Setup.
		$reflection = new ReflectionClass( $auth0_setup );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hooks[] = $property->getValue( $auth0_setup );

		// Cron Manager.
		$reflection = new ReflectionClass( $cron_manager );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hooks[] = $property->getValue( $cron_manager );

		// Provider Diagnostics.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Provider_Diagnostics' );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hooks[] = $property->getValue();

		// Verify all hooks are unique.
		$unique_hooks = array_unique( $hooks );
		$this->assertCount( count( $hooks ), $unique_hooks, 'All page hooks should be unique' );
	}

	/**
	 * Test that enqueue_assets methods respect their hook suffix.
	 */
	public function test_enqueue_assets_respects_hook() {
		global $wp_scripts, $wp_styles;

		// Create instances.
		$auth0_setup = new WP_MCP_AI_Auth0_Setup();

		// Trigger the admin_menu action.
		do_action( 'admin_menu' );

		// Get the actual hook suffix.
		$reflection = new ReflectionClass( $auth0_setup );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$actual_hook = $property->getValue( $auth0_setup );

		// Reset scripts and styles.
		$wp_scripts = null;
		$wp_styles  = null;

		// Trigger enqueue with a different hook.
		do_action( 'admin_enqueue_scripts', 'some-other-page' );

		// Verify Auth0 scripts are NOT enqueued.
		if ( isset( $wp_scripts->registered['wp-mcp-ai-auth0-setup'] ) ) {
			$this->fail( 'Auth0 Setup scripts should not be enqueued on other pages' );
		}

		// Reset scripts and styles.
		$wp_scripts = null;
		$wp_styles  = null;

		// Trigger enqueue with the correct hook.
		do_action( 'admin_enqueue_scripts', $actual_hook );

		// Verify Auth0 scripts ARE enqueued.
		$this->assertTrue(
			isset( $wp_scripts->registered['wp-mcp-ai-auth0-setup'] ),
			'Auth0 Setup scripts should be enqueued on the correct page'
		);
	}

	/**
	 * Test that MCP Diagnostic page properly localizes script data.
	 */
	public function test_mcp_diagnostic_localizes_script_data() {
		global $wp_scripts;

		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Get the hook suffix.
		$reflection = new ReflectionClass( 'WP_MCP_AI_MCP_Server_Diagnostic' );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$actual_hook = $property->getValue();

		// Reset scripts.
		$wp_scripts = null;

		// Trigger enqueue with the correct hook.
		do_action( 'admin_enqueue_scripts', $actual_hook );

		// Verify the script is enqueued.
		$this->assertTrue(
			isset( $wp_scripts->registered['wp-mcp-ai-mcp-diagnostic-inline'] ),
			'MCP Diagnostic inline script should be enqueued'
		);

		// Verify localized data is present.
		$this->assertNotEmpty(
			$wp_scripts->registered['wp-mcp-ai-mcp-diagnostic-inline']->extra,
			'Localized script data should be present'
		);

		// Get the localized data.
		$localized_data = null;
		if ( isset( $wp_scripts->registered['wp-mcp-ai-mcp-diagnostic-inline']->extra['data'] ) ) {
			$localized_data = $wp_scripts->registered['wp-mcp-ai-mcp-diagnostic-inline']->extra['data'];
		}

		$this->assertNotNull( $localized_data, 'Localized data should exist' );
		$this->assertStringContainsString( 'wpMcpAiMcpDiagnostic', $localized_data, 'Localized object name should be present' );
		$this->assertStringContainsString( 'ajaxUrl', $localized_data, 'Ajax URL should be localized' );
		$this->assertStringContainsString( 'nonce', $localized_data, 'Nonce should be localized' );
	}
}
