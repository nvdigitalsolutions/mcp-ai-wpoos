<?php
/**
 * Tests for Project Management Submenu Registration
 *
 * Tests that Research & Add and Settings pages are properly registered
 * as submenu items under the Projects menu.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Project Management submenu registration.
 */
class Test_Project_Management_Submenu_Registration extends WP_UnitTestCase {
	/**
	 * Original settings value to restore after tests.
	 *
	 * @var array
	 */
	private $original_settings;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Store original settings.
		$this->original_settings = get_option( 'wp_mcp_ai_settings', array() );

		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Set admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Set admin context.
		set_current_screen( 'dashboard' );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Restore original settings.
		update_option( 'wp_mcp_ai_settings', $this->original_settings );

		// Clear screen.
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Test that Research & Add submenu is registered under Projects.
	 */
	public function test_research_and_add_submenu_registered() {
		global $submenu;

		// Ensure Pro addon is active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Register post types first (normally happens on 'init').
		wp_mcp_ai_register_project_management_post_types();

		// Clear any existing submenus.
		$submenu = array();

		// Trigger admin initialization (loads submenu files).
		do_action( 'plugins_loaded' );

		// Trigger menu registration.
		do_action( 'admin_menu' );

		// Check if Projects submenu exists.
		$parent_slug = 'edit.php?post_type=mcp_ai_project';
		$this->assertArrayHasKey(
			$parent_slug,
			$submenu,
			'Projects submenu should be registered'
		);

		// Check if Research & Add is in the submenu.
		$found_research = false;
		foreach ( $submenu[ $parent_slug ] as $item ) {
			if ( 'Research & Add' === $item[0] ) {
				$found_research = true;
				$this->assertEquals( 'research-project', $item[2], 'Research & Add should use correct page slug' );
				break;
			}
		}

		$this->assertTrue( $found_research, 'Research & Add submenu item should be registered under Projects' );
	}

	/**
	 * Test that Settings submenu is registered under Projects.
	 */
	public function test_settings_submenu_registered() {
		global $submenu;

		// Ensure Pro addon is active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Register post types first (normally happens on 'init').
		wp_mcp_ai_register_project_management_post_types();

		// Clear any existing submenus.
		$submenu = array();

		// Trigger admin initialization (loads submenu files).
		do_action( 'plugins_loaded' );

		// Trigger menu registration.
		do_action( 'admin_menu' );

		// Check if Projects submenu exists.
		$parent_slug = 'edit.php?post_type=mcp_ai_project';
		$this->assertArrayHasKey(
			$parent_slug,
			$submenu,
			'Projects submenu should be registered'
		);

		// Check if Settings is in the submenu.
		$found_settings = false;
		foreach ( $submenu[ $parent_slug ] as $item ) {
			if ( 'Settings' === $item[0] ) {
				$found_settings = true;
				$this->assertEquals( 'project-settings', $item[2], 'Settings should use correct page slug' );
				break;
			}
		}

		$this->assertTrue( $found_settings, 'Settings submenu item should be registered under Projects' );
	}

	/**
	 * Test that both submenu items use correct priorities.
	 */
	public function test_submenu_items_have_correct_order() {
		global $submenu;

		// Ensure Pro addon is active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Register post types first (normally happens on 'init').
		wp_mcp_ai_register_project_management_post_types();

		// Clear any existing submenus.
		$submenu = array();

		// Trigger admin initialization (loads submenu files).
		do_action( 'plugins_loaded' );

		// Trigger menu registration.
		do_action( 'admin_menu' );

		// Check if Projects submenu exists.
		$parent_slug = 'edit.php?post_type=mcp_ai_project';
		$this->assertArrayHasKey(
			$parent_slug,
			$submenu,
			'Projects submenu should be registered'
		);

		// Find positions of menu items.
		$research_position = null;
		$settings_position = null;

		foreach ( $submenu[ $parent_slug ] as $position => $item ) {
			if ( 'Research & Add' === $item[0] ) {
				$research_position = $position;
			}
			if ( 'Settings' === $item[0] ) {
				$settings_position = $position;
			}
		}

		// Research & Add should appear before Settings (lower priority number = earlier in menu).
		if ( null !== $research_position && null !== $settings_position ) {
			$this->assertLessThan(
				$settings_position,
				$research_position,
				'Research & Add should appear before Settings in the menu'
			);
		}
	}
}
