<?php
/**
 * Tests for the plugin's Elementor request-hardening hooks.
 *
 * Two hooks are covered:
 *
 *  - `suppress_debug_in_elementor_ajax()` records the output-buffer level and
 *    registers a `shutdown` cleanup for Elementor AJAX requests, so stray PHP
 *    output cannot corrupt Elementor's JSON responses when WP_DEBUG is on.
 *  - `disable_auth_check_in_elementor()` drops core's `wp-auth-check` assets in
 *    the Elementor editor, where the modal markup it expects does not exist.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the Elementor request-hardening hooks.
 */
class WP_MCP_AI_Elementor_Debug_Mode_Test extends WP_UnitTestCase {
	use WP_MCP_AI_Request_Context_Test_Helper;

	/**
	 * Original REQUEST values to restore after tests.
	 *
	 * @var array
	 */
	private $original_request = array();

	/**
	 * Original GET values to restore after tests.
	 *
	 * @var array
	 */
	private $original_get = array();

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_request = $_REQUEST;
		$this->original_get     = $_GET;

		// Every hook under test only acts on AJAX requests, so the whole class
		// runs in a simulated AJAX context. Using the `wp_doing_ajax` filter
		// instead of `define( 'DOING_AJAX', true )` keeps it reversible — a
		// leaked constant would flip `wp_doing_ajax()` for the rest of the run.
		$this->simulate_ajax_context();

		$this->record_output_buffer_baseline();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		$_REQUEST = $this->original_request;
		$_GET     = $this->original_get;

		$this->end_ajax_context();
		$this->unwind_output_buffers();

		parent::tear_down();
	}

	/**
	 * Test that suppress_debug_in_elementor_ajax method exists.
	 */
	public function test_suppress_debug_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI', 'suppress_debug_in_elementor_ajax' ),
			'suppress_debug_in_elementor_ajax method should exist'
		);
	}

	/**
	 * Test that disable_auth_check_in_elementor method exists.
	 */
	public function test_disable_auth_check_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI', 'disable_auth_check_in_elementor' ),
			'disable_auth_check_in_elementor method should exist'
		);
	}

	/**
	 * Test that clean_elementor_output_buffer method exists.
	 */
	public function test_clean_output_buffer_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI', 'clean_elementor_output_buffer' ),
			'clean_elementor_output_buffer method should exist'
		);
	}

	/**
	 * Elementor AJAX requests register the shutdown buffer cleanup.
	 */
	public function test_shutdown_cleanup_registered_for_elementor_ajax() {
		$plugin = WP_MCP_AI::instance();

		$_REQUEST['action'] = 'elementor_ajax';
		$plugin->suppress_debug_in_elementor_ajax();

		// `has_action()` returns the priority (0 here), so compare against false.
		$this->assertNotFalse(
			has_action( 'shutdown', array( $plugin, 'clean_elementor_output_buffer' ) ),
			'Elementor AJAX should register the shutdown buffer cleanup'
		);
	}

	/**
	 * Non-Elementor AJAX requests are left alone.
	 */
	public function test_shutdown_cleanup_not_registered_for_other_ajax() {
		$plugin = WP_MCP_AI::instance();

		$_REQUEST['action'] = 'my_custom_action';
		$plugin->suppress_debug_in_elementor_ajax();

		$this->assertFalse(
			has_action( 'shutdown', array( $plugin, 'clean_elementor_output_buffer' ) ),
			'Non-Elementor AJAX should not register the shutdown buffer cleanup'
		);
	}

	/**
	 * Requests with no action parameter are left alone.
	 */
	public function test_shutdown_cleanup_not_registered_without_action() {
		$plugin = WP_MCP_AI::instance();

		unset( $_REQUEST['action'] );
		$plugin->suppress_debug_in_elementor_ajax();

		$this->assertFalse(
			has_action( 'shutdown', array( $plugin, 'clean_elementor_output_buffer' ) ),
			'A request without an action should not register the shutdown cleanup'
		);
	}

	/**
	 * Every `elementor`-prefixed action is recognised.
	 */
	public function test_elementor_action_patterns_detected() {
		$plugin = WP_MCP_AI::instance();

		$elementor_actions = array(
			'elementor_ajax',
			'elementor_render_widget',
			'elementor_get_templates',
			'elementor_save_builder',
			'elementor_pro_forms_send_form',
		);

		foreach ( $elementor_actions as $action ) {
			remove_action( 'shutdown', array( $plugin, 'clean_elementor_output_buffer' ), 0 );

			$_REQUEST['action'] = $action;
			$plugin->suppress_debug_in_elementor_ajax();

			$this->assertNotFalse(
				has_action( 'shutdown', array( $plugin, 'clean_elementor_output_buffer' ) ),
				"Action '{$action}' should register the shutdown buffer cleanup"
			);
		}
	}

	/**
	 * The shutdown cleanup discards buffers opened after the request was tagged.
	 */
	public function test_clean_output_buffer_discards_later_buffers() {
		$plugin = WP_MCP_AI::instance();

		$_REQUEST['action'] = 'elementor_save_builder';

		$level_before = ob_get_level();

		// Records `$level_before` as the level to unwind back to on shutdown.
		$plugin->suppress_debug_in_elementor_ajax();

		// Simulate a later component buffering stray output — exactly what would
		// otherwise be prepended to Elementor's JSON response.
		ob_start();
		echo 'This output should be discarded';

		$this->assertGreaterThan( $level_before, ob_get_level(), 'Stray buffer should be open before cleanup' );

		$plugin->clean_elementor_output_buffer();

		$this->assertSame(
			$level_before,
			ob_get_level(),
			'Cleanup should unwind back to the level recorded at request start'
		);
	}

	/**
	 * The cleanup is a no-op for non-Elementor actions, even if it is called.
	 */
	public function test_clean_output_buffer_ignores_non_elementor_actions() {
		$plugin = WP_MCP_AI::instance();

		$_REQUEST['action'] = 'elementor_save_builder';
		$plugin->suppress_debug_in_elementor_ajax();

		// A different request shape reaches shutdown — the cleanup must not
		// unwind buffers it does not own.
		$_REQUEST['action'] = 'some_other_action';

		ob_start();
		$level_with_buffer = ob_get_level();

		$plugin->clean_elementor_output_buffer();

		$this->assertSame(
			$level_with_buffer,
			ob_get_level(),
			'Cleanup should leave buffers alone for non-Elementor actions'
		);
	}

	/**
	 * The Elementor editor drops core's wp-auth-check assets.
	 */
	public function test_auth_check_disabled_in_elementor_editor() {
		$plugin = WP_MCP_AI::instance();

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertNotFalse(
			has_action( 'admin_enqueue_scripts', 'wp_auth_check_load' ),
			'Core should register wp_auth_check_load before the hook runs'
		);

		$_GET['action'] = 'elementor';
		$plugin->disable_auth_check_in_elementor();

		$this->assertFalse(
			has_action( 'admin_enqueue_scripts', 'wp_auth_check_load' ),
			'wp_auth_check_load should be removed in the Elementor editor'
		);
	}

	/**
	 * Regular admin pages keep core's wp-auth-check assets.
	 */
	public function test_auth_check_kept_on_regular_pages() {
		$plugin = WP_MCP_AI::instance();

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$_GET['page'] = 'some-admin-page';
		$plugin->disable_auth_check_in_elementor();

		$this->assertNotFalse(
			has_action( 'admin_enqueue_scripts', 'wp_auth_check_load' ),
			'wp_auth_check_load should survive on non-Elementor pages'
		);
	}

	/**
	 * Users without `edit_posts` do not trigger the auth-check removal.
	 */
	public function test_auth_check_kept_without_edit_posts() {
		$plugin = WP_MCP_AI::instance();

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$_GET['action'] = 'elementor';
		$plugin->disable_auth_check_in_elementor();

		$this->assertNotFalse(
			has_action( 'admin_enqueue_scripts', 'wp_auth_check_load' ),
			'wp_auth_check_load should survive for users who cannot edit posts'
		);
	}
}
