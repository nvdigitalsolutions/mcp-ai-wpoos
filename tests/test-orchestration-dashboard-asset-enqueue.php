<?php
/**
 * Tests for Orchestration Dashboard Asset Enqueuing
 *
 * Verifies that the base orchestration dashboard only loads on its own page
 * and does not conflict with the Pro orchestration dashboard.
 *
 * @package WP_MCP_AI
 */

/**
 * Test orchestration dashboard asset enqueuing.
 */
class Test_Orchestration_Dashboard_Asset_Enqueue extends WP_UnitTestCase {

	/**
	 * Instance of the dashboard class
	 *
	 * @var WP_MCP_AI_Admin_Orchestration_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the class if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php';
		}

		// Set up an admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Initialize the dashboard class.
		$this->dashboard = new WP_MCP_AI_Admin_Orchestration_Dashboard();
	}

	/**
	 * Test that assets are enqueued on the base orchestration page.
	 */
	public function test_assets_enqueued_on_base_page() {
		// Simulate being on the base orchestration dashboard page.
		$hook = 'nv-oos_page_mcp-ai-orchestration';
		
		// Clear any previously enqueued scripts/styles.
		global $wp_scripts, $wp_styles;
		$wp_scripts = new WP_Scripts();
		$wp_styles  = new WP_Styles();

		// Call the enqueue method.
		$this->dashboard->enqueue_assets( $hook );

		// Verify assets are enqueued.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-orchestration-dashboard', 'enqueued' ),
			'JavaScript should be enqueued on base orchestration page'
		);
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-orchestration-dashboard', 'enqueued' ),
			'CSS should be enqueued on base orchestration page'
		);
	}

	/**
	 * Test that assets are NOT enqueued on the Pro orchestration page.
	 */
	public function test_assets_not_enqueued_on_pro_page() {
		// Simulate being on the Pro orchestration dashboard page.
		$hook = 'nvoos-pro-dashboard_page_mcp-ai-orchestration-pro';
		
		// Clear any previously enqueued scripts/styles.
		global $wp_scripts, $wp_styles;
		$wp_scripts = new WP_Scripts();
		$wp_styles  = new WP_Styles();

		// Call the enqueue method.
		$this->dashboard->enqueue_assets( $hook );

		// Verify assets are NOT enqueued.
		$this->assertFalse(
			wp_script_is( 'wp-mcp-ai-orchestration-dashboard', 'enqueued' ),
			'JavaScript should NOT be enqueued on Pro orchestration page'
		);
		$this->assertFalse(
			wp_style_is( 'wp-mcp-ai-orchestration-dashboard', 'enqueued' ),
			'CSS should NOT be enqueued on Pro orchestration page'
		);
	}

	/**
	 * Test that assets are NOT enqueued on other pages.
	 */
	public function test_assets_not_enqueued_on_other_pages() {
		// Simulate being on a different admin page.
		$hook = 'other_page';
		
		// Clear any previously enqueued scripts/styles.
		global $wp_scripts, $wp_styles;
		$wp_scripts = new WP_Scripts();
		$wp_styles  = new WP_Styles();

		// Call the enqueue method.
		$this->dashboard->enqueue_assets( $hook );

		// Verify assets are NOT enqueued.
		$this->assertFalse(
			wp_script_is( 'wp-mcp-ai-orchestration-dashboard', 'enqueued' ),
			'JavaScript should NOT be enqueued on other pages'
		);
		$this->assertFalse(
			wp_style_is( 'wp-mcp-ai-orchestration-dashboard', 'enqueued' ),
			'CSS should NOT be enqueued on other pages'
		);
	}

	/**
	 * Test that assets are enqueued on toplevel orchestration page.
	 */
	public function test_assets_enqueued_on_toplevel_page() {
		// Simulate being on a toplevel orchestration page.
		$hook = 'toplevel_page_mcp-ai-orchestration';
		
		// Clear any previously enqueued scripts/styles.
		global $wp_scripts, $wp_styles;
		$wp_scripts = new WP_Scripts();
		$wp_styles  = new WP_Styles();

		// Call the enqueue method.
		$this->dashboard->enqueue_assets( $hook );

		// Verify assets are enqueued.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-orchestration-dashboard', 'enqueued' ),
			'JavaScript should be enqueued on toplevel orchestration page'
		);
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-orchestration-dashboard', 'enqueued' ),
			'CSS should be enqueued on toplevel orchestration page'
		);
	}
}
