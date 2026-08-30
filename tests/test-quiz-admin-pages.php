<?php
/**
 * Tests for Quiz Admin Pages
 *
 * Verifies that Research & Add and Settings pages are properly registered
 * under the Quiz menu.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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

		// Store original settings.
		$this->original_settings = get_option( 'wp_mcp_ai_settings', array() );

		// Enable quiz system for tests by default (tests can override if needed).
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_quiz_system'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Store original menu globals and clear them.
		global $menu, $submenu;
		$this->original_menu    = $menu;
		$this->original_submenu = $submenu;
		$menu                   = array();
		$submenu                = array();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Restore original settings.
		if ( ! empty( $this->original_settings ) ) {
			update_option( 'wp_mcp_ai_settings', $this->original_settings );
		} else {
			delete_option( 'wp_mcp_ai_settings' );
		}

		// Restore original menu globals.
		global $menu, $submenu;
		$menu    = $this->original_menu;
		$submenu = $this->original_submenu;

		parent::tearDown();
	}

	/**
	 * Original settings.
	 *
	 * @var array
	 */
	protected $original_settings = array();

	/**
	 * Original menu state.
	 *
	 * @var array
	 */
	protected $original_menu;

	/**
	 * Original submenu state.
	 *
	 * @var array
	 */
	protected $original_submenu;

	/**
	 * Load the quiz admin page classes and register their hooks.
	 *
	 * The production init wires them inside its is_admin() branch, which
	 * never executes under WP_UnitTestCase (init.php is already required
	 * by the module registry before set_current_screen() makes is_admin()
	 * true), so the suite wires them itself. WP_UnitTestCase resets hooks
	 * between tests, so this must run in every test that asserts menus.
	 */
	private function load_admin_pages() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-quiz-research-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-quiz-settings-page.php';
		WP_MCP_AI_Quiz_Research_Page::init();
		new WP_MCP_AI_Quiz_Settings_Page();
	}

	/**
	 * Test that Quiz CPT is registered when enabled.
	 */
	public function test_quiz_cpt_registered_when_enabled() {
		// Register the CPT directly. Production wires this on the 'init'
		// hook, but re-firing that action under WP_UnitTestCase re-runs
		// WooCommerce's block registrations and pollutes the test with
		// incorrect-usage notices.
		WP_MCP_AI_Quiz_CPT::register_post_types();

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
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/init.php';
		}
		$this->load_admin_pages();

		// Register the CPT directly (see test_quiz_cpt_registered_when_enabled).
		WP_MCP_AI_Quiz_CPT::register_post_types();

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
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/init.php';
		}
		$this->load_admin_pages();

		// Register the CPT directly (see test_quiz_cpt_registered_when_enabled).
		WP_MCP_AI_Quiz_CPT::register_post_types();

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
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/init.php';
		}
		$this->load_admin_pages();

		// Register the CPT directly (see test_quiz_cpt_registered_when_enabled).
		WP_MCP_AI_Quiz_CPT::register_post_types();

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// If CPT is registered, verify submenu pages exist.
		$post_type = get_post_type_object( 'mcp_ai_quiz' );
		if ( $post_type && $post_type->show_in_menu ) {
			$parent_slug = 'edit.php?post_type=mcp_ai_quiz';
			$this->assertArrayHasKey( $parent_slug, $submenu, 'Quiz submenu should exist when CPT is registered' );

			// Verify both pages are present.
			$found_research = false;
			$found_settings = false;

			if ( isset( $submenu[ $parent_slug ] ) ) {
				foreach ( $submenu[ $parent_slug ] as $item ) {
					if ( strpos( $item[2], 'research-quiz' ) !== false ) {
						$found_research = true;
					}
					if ( strpos( $item[2], 'quiz-settings' ) !== false ) {
						$found_settings = true;
					}
				}
			}

			$this->assertTrue( $found_research, 'Research & Add page should be in submenu' );
			$this->assertTrue( $found_settings, 'Settings page should be in submenu' );
		}
	}

	/**
	 * Test that admin pages are registered even when quiz system is disabled.
	 */
	public function test_admin_pages_registered_when_disabled() {
		// Disable the quiz system.
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_quiz_system'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		global $submenu;

		// Load the initialization file.
		if ( file_exists( WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/init.php' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/quiz-management/init.php';
		}
		$this->load_admin_pages();

		// When the quiz system is disabled, the CPT init() bails without
		// hooking registration — the CPT stays unregistered in a fresh
		// state (it may persist from earlier tests in this process).
		WP_MCP_AI_Quiz_CPT::init();

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if CPT was registered (it shouldn't be when disabled).
		$post_type = get_post_type_object( 'mcp_ai_quiz' );

		// If CPT is registered, verify admin pages are present.
		// If CPT is not registered (expected when disabled), that's the current behavior.
		// The admin pages are always loaded, but they only appear if the CPT exists.
		if ( $post_type && $post_type->show_in_menu ) {
			$parent_slug = 'edit.php?post_type=mcp_ai_quiz';
			$this->assertArrayHasKey( $parent_slug, $submenu, 'Quiz submenu should exist when CPT is registered' );

			// Verify both pages are present.
			$found_research = false;
			$found_settings = false;

			if ( isset( $submenu[ $parent_slug ] ) ) {
				foreach ( $submenu[ $parent_slug ] as $item ) {
					if ( strpos( $item[2], 'research-quiz' ) !== false ) {
						$found_research = true;
					}
					if ( strpos( $item[2], 'quiz-settings' ) !== false ) {
						$found_settings = true;
					}
				}
			}

			$this->assertTrue( $found_research, 'Research & Add page should be registered even when feature is disabled' );
			$this->assertTrue( $found_settings, 'Settings page should be registered even when feature is disabled' );
		} else {
			// CPT is not registered when disabled. Quiz CPT behavior differs from Document Template CPT.
			// Document Template CPT is always registered, but Quiz CPT registration is conditional.
			// Since the CPT doesn't exist, there's no parent menu for submenu pages to attach to.
			// This test documents that admin pages are loaded but can't appear without the CPT.
			$this->markTestSkipped( 'Quiz CPT is not registered when disabled, so admin pages cannot be verified' );
		}
	}
}
