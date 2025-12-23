<?php
/**
 * Test datasets admin page functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Datasets_Admin_Page.
 */
class Test_Datasets_Admin_Page extends WP_UnitTestCase {

	/**
	 * Test admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Create admin user.
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_user_id );

		// Load the datasets admin page class.
		require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-datasets-admin-page.php';
	}

	/**
	 * Test that the datasets menu is registered.
	 */
	public function test_menu_registered() {
		global $submenu;

		// Trigger admin_menu hook.
		do_action( 'admin_menu' );

		// Check that submenu exists under wp-mcp-ai-dashboard.
		$this->assertArrayHasKey( 'wp-mcp-ai-dashboard', $submenu, 'WP MCP AI dashboard should have submenu items' );

		// Check that datasets page is in the submenu.
		$found = false;
		if ( isset( $submenu['wp-mcp-ai-dashboard'] ) && is_array( $submenu['wp-mcp-ai-dashboard'] ) ) {
			foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
				if ( isset( $item[2] ) && 'wp-mcp-ai-datasets' === $item[2] ) {
					$found = true;
					$this->assertEquals( 'HF Datasets', $item[0], 'Menu title should be "HF Datasets"' );
					break;
				}
			}
		}

		$this->assertTrue( $found, 'Datasets page should be registered in submenu' );
	}

	/**
	 * Test that scripts are enqueued on the correct page.
	 */
	public function test_scripts_enqueued_on_datasets_page() {
		global $wp_scripts, $wp_styles;

		// Reset enqueued scripts and styles.
		$wp_scripts = new WP_Scripts();
		$wp_styles = new WP_Styles();

		// Trigger admin_menu to get page hook.
		do_action( 'admin_menu' );

		// Get the page hook - it should be wp_mcp_ai_dashboard_page_wp-mcp-ai-datasets.
		$hook = 'wp_mcp_ai_dashboard_page_wp-mcp-ai-datasets';

		// Trigger enqueue scripts with the correct hook.
		do_action( 'admin_enqueue_scripts', $hook );

		// Check that datasets admin script is enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-datasets-admin', 'enqueued' ), 'Datasets admin script should be enqueued' );
		
		// Check that datasets admin style is enqueued.
		$this->assertTrue( wp_style_is( 'wp-mcp-ai-datasets-admin', 'enqueued' ), 'Datasets admin style should be enqueued' );
	}

	/**
	 * Test that scripts are NOT enqueued on other pages.
	 */
	public function test_scripts_not_enqueued_on_other_pages() {
		global $wp_scripts, $wp_styles;

		// Reset enqueued scripts and styles.
		$wp_scripts = new WP_Scripts();
		$wp_styles = new WP_Styles();

		// Trigger admin_menu to register the page.
		do_action( 'admin_menu' );

		// Use a different hook (e.g., dashboard page).
		$wrong_hook = 'index.php';

		// Trigger enqueue scripts with the wrong hook.
		do_action( 'admin_enqueue_scripts', $wrong_hook );

		// Check that datasets admin script is NOT enqueued.
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-datasets-admin', 'enqueued' ), 'Datasets admin script should NOT be enqueued on other pages' );
		
		// Check that datasets admin style is NOT enqueued.
		$this->assertFalse( wp_style_is( 'wp-mcp-ai-datasets-admin', 'enqueued' ), 'Datasets admin style should NOT be enqueued on other pages' );
	}

	/**
	 * Test that the page hook is stored correctly.
	 */
	public function test_page_hook_stored() {
		// Create a reflection of the class to access private property.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Datasets_Admin_Page' );
		$property = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );

		// Create a new instance.
		$instance = new WP_MCP_AI_Datasets_Admin_Page();

		// Trigger admin_menu to register the page.
		do_action( 'admin_menu' );

		// Get the page hook value.
		$page_hook = $property->getValue( $instance );

		// Verify the hook is set and follows the expected pattern.
		$this->assertNotEmpty( $page_hook, 'Page hook should be stored' );
		$this->assertStringContainsString( 'wp-mcp-ai-datasets', $page_hook, 'Page hook should contain the menu slug' );
	}
}
