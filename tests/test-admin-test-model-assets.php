<?php
/**
 * Tests for Admin Test Model Assets
 *
 * Verifies that the Test Model admin page properly enqueues all necessary
 * JavaScript and CSS assets for the professional selector to function.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Admin Test Model asset enqueuing.
 */
class Test_Admin_Test_Model_Assets extends WP_UnitTestCase {

	/**
	 * Test model admin instance.
	 *
	 * @var WP_MCP_AI_Admin_Test_Model
	 */
	private $test_model;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure required classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Model' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-model.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Professional_Selector_Shortcode' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-professional-selector-shortcode.php';
		}

		// Initialize the shortcodes to register assets.
		new WP_MCP_AI_Shortcode();
		new WP_MCP_AI_Professional_Selector_Shortcode();

		// Trigger asset registration.
		do_action( 'init' );

		// Set up test model instance.
		$this->test_model = new WP_MCP_AI_Admin_Test_Model();

		// Register the admin page.
		do_action( 'admin_menu' );

		// Set current user as admin.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
	}

	/**
	 * Test that the admin page registers correctly.
	 */
	public function test_admin_page_registers() {
		global $submenu;

		$post_type = 'mcp_ai_profession';
		$parent    = 'edit.php?post_type=' . $post_type;

		// The submenu should exist.
		$this->assertArrayHasKey( $parent, $submenu );

		// Find the test model submenu item.
		$found = false;
		if ( isset( $submenu[ $parent ] ) ) {
			foreach ( $submenu[ $parent ] as $item ) {
				if ( isset( $item[2] ) && 'wp-mcp-ai-test-model' === $item[2] ) {
					$found = true;
					break;
				}
			}
		}

		$this->assertTrue( $found, 'Test Model submenu item should be registered' );
	}

	/**
	 * Test that chat shortcode assets are enqueued on the test model page.
	 *
	 * This is the critical test - verifies that the wp-mcp-ai-chat script
	 * (required dependency for professional selector) is properly enqueued.
	 */
	public function test_chat_assets_enqueued() {
		// Set the current screen to test model page.
		set_current_screen( 'mcp_ai_profession_page_wp-mcp-ai-test-model' );

		// Trigger enqueue scripts with the correct hook.
		$hook = 'mcp_ai_profession_page_wp-mcp-ai-test-model';
		do_action( 'admin_enqueue_scripts', $hook );

		// Verify that chat script is enqueued (required dependency).
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-chat', 'enqueued' ),
			'Chat script (wp-mcp-ai-chat) should be enqueued as dependency for professional selector'
		);

		// Verify that chat styles are enqueued.
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-chat', 'enqueued' ),
			'Chat styles (wp-mcp-ai-chat) should be enqueued as dependency for professional selector'
		);
	}

	/**
	 * Test that professional selector assets are enqueued on the test model page.
	 */
	public function test_professional_selector_assets_enqueued() {
		// Set the current screen to test model page.
		set_current_screen( 'mcp_ai_profession_page_wp-mcp-ai-test-model' );

		// Trigger enqueue scripts.
		$hook = 'mcp_ai_profession_page_wp-mcp-ai-test-model';
		do_action( 'admin_enqueue_scripts', $hook );

		// Verify professional selector script is enqueued.
		$this->assertTrue(
			wp_script_is( 'wp-mcp-ai-professional-selector', 'enqueued' ),
			'Professional selector script should be enqueued'
		);

		// Verify professional selector styles are enqueued.
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-professional-selector', 'enqueued' ),
			'Professional selector styles should be enqueued'
		);
	}

	/**
	 * Test that test model specific assets are enqueued.
	 */
	public function test_test_model_assets_enqueued() {
		// Set the current screen to test model page.
		set_current_screen( 'mcp_ai_profession_page_wp-mcp-ai-test-model' );

		// Trigger enqueue scripts.
		$hook = 'mcp_ai_profession_page_wp-mcp-ai-test-model';
		do_action( 'admin_enqueue_scripts', $hook );

		// Verify test model specific styles are enqueued.
		$this->assertTrue(
			wp_style_is( 'wp-mcp-ai-admin-test-model', 'enqueued' ),
			'Test model admin styles should be enqueued'
		);
	}

	/**
	 * Test that assets are NOT enqueued on other admin pages.
	 */
	public function test_assets_not_enqueued_on_other_pages() {
		// Set a different screen.
		set_current_screen( 'dashboard' );

		// Trigger enqueue scripts with a different hook.
		do_action( 'admin_enqueue_scripts', 'index.php' );

		// Verify that our specific assets are NOT enqueued.
		$this->assertFalse(
			wp_style_is( 'wp-mcp-ai-admin-test-model', 'enqueued' ),
			'Test model styles should not be enqueued on other pages'
		);
	}

	/**
	 * Test that all required dependencies are loaded in correct order.
	 *
	 * This verifies the dependency chain:
	 * wp-mcp-ai-professional-selector depends on wp-mcp-ai-chat
	 */
	public function test_script_dependencies() {
		global $wp_scripts;

		// Set the current screen to test model page.
		set_current_screen( 'mcp_ai_profession_page_wp-mcp-ai-test-model' );

		// Trigger enqueue scripts.
		$hook = 'mcp_ai_profession_page_wp-mcp-ai-test-model';
		do_action( 'admin_enqueue_scripts', $hook );

		// Get the professional selector script object.
		$prof_selector_script = $wp_scripts->registered['wp-mcp-ai-professional-selector'];

		// Verify that it depends on the chat script.
		$this->assertContains(
			'wp-mcp-ai-chat',
			$prof_selector_script->deps,
			'Professional selector script should depend on chat script'
		);
	}
}
