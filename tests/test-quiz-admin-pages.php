<?php
/**
 * Tests for Quiz Admin Pages
 *
 * Verifies that Research & Add and Settings pages are properly registered
 * under the Quiz menu.
 *
 * @package WP_MCP_AI
 */

/**
 * Test quiz admin page registration.
 */
class Test_Quiz_Admin_Pages extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		// Enable quiz system for tests.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_quiz_system'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Clear any existing menu globals.
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Clean up settings.
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test that Quiz CPT is registered when enabled.
	 */
	public function test_quiz_cpt_registered_when_enabled() {
		// Initialize the CPT.
		WP_MCP_AI_Quiz_CPT::init();
		do_action( 'init' );

		// Check if the post type is registered.
		$post_type = get_post_type_object( 'mcp_ai_quiz' );
		$this->assertNotNull( $post_type, 'Quiz CPT should be registered when enabled' );
		$this->assertTrue( $post_type->show_in_menu, 'Quiz CPT should show in menu' );
	}

	/**
	 * Test that Research & Add page is registered.
	 */
	public function test_research_add_page_registered() {
		global $submenu;

		// Load the initialization file to ensure admin pages are loaded.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/quiz-management-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/quiz-management-init.php';
		}

		// Initialize CPT to ensure it's registered.
		WP_MCP_AI_Quiz_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu is registered under the CPT.
		$parent_slug = 'edit.php?post_type=mcp_ai_quiz';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Quiz should have submenu items' );

		// Find the Research & Add page.
		$found_research_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'research-quiz' ) !== false ) {
					$found_research_page = true;
					$this->assertSame( 'Research & Add', $item[0], 'Research & Add page should have correct title' );
					break;
				}
			}
		}

		$this->assertTrue( $found_research_page, 'Research & Add page should be registered' );
	}

	/**
	 * Test that Settings page is registered.
	 */
	public function test_settings_page_registered() {
		global $submenu;

		// Load the initialization file to ensure admin pages are loaded.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/quiz-management-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/quiz-management-init.php';
		}

		// Initialize CPT to ensure it's registered.
		WP_MCP_AI_Quiz_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu is registered under the CPT.
		$parent_slug = 'edit.php?post_type=mcp_ai_quiz';
		$this->assertArrayHasKey( $parent_slug, $submenu, 'Quiz should have submenu items' );

		// Find the Settings page.
		$found_settings_page = false;
		if ( isset( $submenu[ $parent_slug ] ) ) {
			foreach ( $submenu[ $parent_slug ] as $item ) {
				if ( strpos( $item[2], 'quiz-settings' ) !== false ) {
					$found_settings_page = true;
					$this->assertSame( 'Settings', $item[0], 'Settings page should have correct title' );
					break;
				}
			}
		}

		$this->assertTrue( $found_settings_page, 'Settings page should be registered' );
	}

	/**
	 * Test that admin pages are always registered (even when CPT registration might be conditional).
	 */
	public function test_admin_pages_always_loaded() {
		global $submenu;

		// Load the initialization file.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/quiz-management-init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/quiz-management-init.php';
		}

		// Initialize CPT.
		WP_MCP_AI_Quiz_CPT::init();
		do_action( 'init' );

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// If CPT is registered, verify submenu pages exist.
		$post_type = get_post_type_object( 'mcp_ai_quiz' );
		if ( $post_type && $post_type->show_in_menu ) {
			$parent_slug = 'edit.php?post_type=mcp_ai_quiz';
			$this->assertArrayHasKey( $parent_slug, $submenu, 'Quiz submenu should exist when CPT is registered' );

			// Verify both pages are present.
			$page_slugs = array();
			if ( isset( $submenu[ $parent_slug ] ) ) {
				foreach ( $submenu[ $parent_slug ] as $item ) {
					$page_slugs[] = $item[2];
				}
			}

			$this->assertContains( 'research-quiz', $page_slugs, 'Research & Add page should be in submenu' );
			$this->assertContains( 'quiz-settings', $page_slugs, 'Settings page should be in submenu' );
		}
	}
}
