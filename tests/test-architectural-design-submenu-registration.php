<?php
/**
 * Tests for Architectural Design Submenu Registration
 *
 * Tests that Research & Add and Settings pages are properly registered
 * as submenu items under the Design Projects menu.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Architectural Design submenu registration.
 */
class Test_Architectural_Design_Submenu_Registration extends WP_UnitTestCase {
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

		// Enable architectural design toolkit.
		$settings                                      = $this->original_settings;
		$settings['enable_architectural_design_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Set admin user.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );

		// Set admin context.
		set_current_screen( 'dashboard' );
	}

	/**
	 * Helper method to initialize architectural design menu.
	 */
	private function initialize_architectural_design_menu() {
		global $submenu;

		// Register post types first (normally happens on 'init').
		if ( function_exists( 'wp_mcp_ai_register_architectural_project_cpt' ) ) {
			wp_mcp_ai_register_architectural_project_cpt();
		}

		// Clear any existing submenus.
		$submenu = array();

		// Load the architectural design toolkit init file.
		$init_file = WP_MCP_AI_PRO_PATH . 'includes/architectural-design-toolkit-init.php';
		if ( file_exists( $init_file ) ) {
			require_once $init_file;
		}

		// Trigger menu registration.
		do_action( 'admin_menu' );
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
	 * Test that Research & Add submenu is registered under Design Projects.
	 */
	public function test_research_and_add_submenu_registered() {
		global $submenu;

		// Ensure Pro addon is active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Initialize menu.
		$this->initialize_architectural_design_menu();

		// Check if Design Projects submenu exists.
		$parent_slug = 'edit.php?post_type=mcp_ai_arch_proj';
		$this->assertArrayHasKey(
			$parent_slug,
			$submenu,
			'Design Projects submenu should be registered'
		);

		// Check if Research & Add is in the submenu.
		$found_research = false;
		foreach ( $submenu[ $parent_slug ] as $item ) {
			if ( 'Research & Add' === $item[0] ) {
				$found_research = true;
				$this->assertEquals( 'architectural-project-research', $item[2], 'Research & Add should use correct page slug' );
				break;
			}
		}

		$this->assertTrue( $found_research, 'Research & Add submenu item should be registered under Design Projects' );
	}

	/**
	 * Test that Settings submenu is registered under Design Projects.
	 */
	public function test_settings_submenu_registered() {
		global $submenu;

		// Ensure Pro addon is active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Initialize menu.
		$this->initialize_architectural_design_menu();

		// Check if Design Projects submenu exists.
		$parent_slug = 'edit.php?post_type=mcp_ai_arch_proj';
		$this->assertArrayHasKey(
			$parent_slug,
			$submenu,
			'Design Projects submenu should be registered'
		);

		// Check if Settings is in the submenu.
		$found_settings = false;
		foreach ( $submenu[ $parent_slug ] as $item ) {
			if ( 'Settings' === $item[0] ) {
				$found_settings = true;
				$this->assertEquals( 'architectural-project-settings', $item[2], 'Settings should use correct page slug' );
				break;
			}
		}

		$this->assertTrue( $found_settings, 'Settings submenu item should be registered under Design Projects' );
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

		// Initialize menu.
		$this->initialize_architectural_design_menu();

		// Check if Design Projects submenu exists.
		$parent_slug = 'edit.php?post_type=mcp_ai_arch_proj';
		$this->assertArrayHasKey(
			$parent_slug,
			$submenu,
			'Design Projects submenu should be registered'
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
