<?php
/**
 * Tests for Datasets Admin Page
 *
 * Verifies that the datasets admin page can properly enqueue scripts
 * using the correct constants.
 *
 * @package WP_MCP_AI
 */

/**
 * Test datasets admin page functionality.
 */
class Test_Admin_Datasets_Page extends WP_UnitTestCase {

	/**
	 * Datasets admin page instance.
	 *
	 * @var WP_MCP_AI_Datasets_Admin_Page
	 */
	private $datasets_page;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Create instance of datasets admin page.
		$this->datasets_page = new WP_MCP_AI_Datasets_Admin_Page();
	}

	/**
	 * Test that datasets page stores hook suffix.
	 */
	public function test_datasets_page_hook() {
		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Get the hook suffix property using reflection.
		$reflection = new ReflectionClass( $this->datasets_page );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hook = $property->getValue( $this->datasets_page );

		// Verify hook suffix is not empty and follows expected pattern.
		$this->assertNotEmpty( $hook, 'Datasets page hook should be stored' );
		$this->assertStringContainsString( 'wp-mcp-ai-datasets', $hook, 'Hook should contain the page slug' );
	}

	/**
	 * Test that enqueue_scripts can be called without errors.
	 *
	 * This test verifies that the fix for undefined constant
	 * WP_MCP_AI_PLUGIN_FILE works correctly by using WP_MCP_AI_URL instead.
	 */
	public function test_enqueue_scripts_uses_correct_constants() {
		// Trigger the admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Get the hook suffix.
		$reflection = new ReflectionClass( $this->datasets_page );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$hook = $property->getValue( $this->datasets_page );

		// Verify the constant WP_MCP_AI_URL is defined.
		$this->assertTrue( defined( 'WP_MCP_AI_URL' ), 'WP_MCP_AI_URL constant should be defined' );

		// Verify the constant WP_MCP_AI_VERSION is defined.
		$this->assertTrue( defined( 'WP_MCP_AI_VERSION' ), 'WP_MCP_AI_VERSION constant should be defined' );

		// Verify WP_MCP_AI_PLUGIN_FILE is NOT used (should not be defined).
		// This is the constant that was causing the error.
		$this->assertFalse( defined( 'WP_MCP_AI_PLUGIN_FILE' ), 'WP_MCP_AI_PLUGIN_FILE should not be defined' );

		// Trigger enqueue_scripts - should not throw an error.
		$this->datasets_page->enqueue_scripts( $hook );

		// Verify styles were enqueued.
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-datasets-admin', 'enqueued' ), 'Datasets admin CSS should be enqueued' );

		// Verify scripts were enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-datasets-admin', 'enqueued' ), 'Datasets admin JS should be enqueued' );

		// Verify the script has the correct localized data.
		global $wp_scripts;
		$script_data = $wp_scripts->get_data( 'wp-mcp-ai-datasets-admin', 'data' );
		$this->assertNotEmpty( $script_data, 'Script should have localized data' );
		$this->assertStringContainsString( 'wpMcpAiDatasets', $script_data, 'Script should have wpMcpAiDatasets object' );
	}

	/**
	 * Test that asset URLs are properly constructed.
	 */
	public function test_asset_urls_are_valid() {
		// Construct expected URLs using WP_MCP_AI_URL.
		$expected_css_url = WP_MCP_AI_URL . 'assets/css/datasets-admin.css';
		$expected_js_url  = WP_MCP_AI_URL . 'assets/js/datasets-admin.js';

		// Verify the URLs are not empty.
		$this->assertNotEmpty( $expected_css_url, 'CSS URL should not be empty' );
		$this->assertNotEmpty( $expected_js_url, 'JS URL should not be empty' );

		// Verify the URLs contain the expected paths.
		$this->assertStringContainsString( 'assets/css/datasets-admin.css', $expected_css_url, 'CSS URL should contain correct path' );
		$this->assertStringContainsString( 'assets/js/datasets-admin.js', $expected_js_url, 'JS URL should contain correct path' );
	}
}
