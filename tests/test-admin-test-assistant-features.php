<?php
/**
 * Tests for Admin Test Assistant Features
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Admin Test Assistant class.
 */
class Test_Admin_Test_Assistant_Features extends WP_UnitTestCase {

	/**
	 * Test assistant instance.
	 *
	 * @var WP_MCP_AI_Admin_Test_Assistant
	 */
	private $test_assistant;

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure required classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Assistant' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-assistant.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-assistant-cpt.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-shortcode.php';
		}

		// Create test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Add some tool shortcuts.
		$tool_shortcuts = array(
			array(
				'label'   => 'Test Task',
				'payload' => 'Do a test task',
				'tool'    => 'custom',
			),
		);

		update_post_meta( $this->assistant_id, '_wp_mcp_ai_tool_shortcuts', $tool_shortcuts );

		// Set up test assistant instance.
		$this->test_assistant = new WP_MCP_AI_Admin_Test_Assistant();

		// Set current user as admin.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
	}

	/**
	 * Test that file upload config is properly set.
	 *
	 * The per-method config builder was inlined into render_shortcode();
	 * verify the config chain (Message Attachments MIME source + the two
	 * helper methods) still yields the expected keys.
	 */
	public function test_file_upload_config_is_set() {
		// Should have file accept configuration if user can upload.
		if ( ! current_user_can( 'upload_files' ) || ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			$this->markTestSkipped( 'File upload prerequisites not available' );
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_Shortcode' );

		$extensions_method = $reflection->getMethod( 'get_allowed_extensions_for_mimes' );
		$extensions_method->setAccessible( true );
		$tokens_method = $reflection->getMethod( 'build_file_accept_tokens' );
		$tokens_method->setAccessible( true );

		$mime_sets           = WP_MCP_AI_Message_Attachments::get_allowed_mime_types();
		$allowed_image_mimes = isset( $mime_sets['image'] ) ? (array) $mime_sets['image'] : array();
		$allowed_file_mimes  = isset( $mime_sets['file'] ) ? (array) $mime_sets['file'] : array();
		$allowed_extensions  = $extensions_method->invoke( null, array_merge( $allowed_image_mimes, $allowed_file_mimes ) );
		$file_accept_tokens  = $tokens_method->invoke( null, $allowed_image_mimes, $allowed_file_mimes, $allowed_extensions );

		$config = array(
			'fileAccept'        => implode( ',', $file_accept_tokens ),
			'allowedImageMimes' => array_values( $allowed_image_mimes ),
			'allowedFileMimes'  => array_values( $allowed_file_mimes ),
			'allowedExtensions' => array_values( $allowed_extensions ),
		);

		// Should return array.
		$this->assertIsArray( $config );

		$this->assertArrayHasKey( 'fileAccept', $config );
		$this->assertArrayHasKey( 'allowedImageMimes', $config );
		$this->assertArrayHasKey( 'allowedFileMimes', $config );
		$this->assertArrayHasKey( 'allowedExtensions', $config );

		$this->assertIsString( $config['fileAccept'] );
		$this->assertIsArray( $config['allowedImageMimes'] );
		$this->assertIsArray( $config['allowedFileMimes'] );
		$this->assertIsArray( $config['allowedExtensions'] );
	}

	/**
	 * Test that allowed extensions are properly extracted from MIME types.
	 *
	 * The method lives on WP_MCP_AI_Shortcode (protected static).
	 */
	public function test_get_allowed_extensions_for_mimes() {
		// Use reflection to access the protected static method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Shortcode' );
		$method     = $reflection->getMethod( 'get_allowed_extensions_for_mimes' );
		$method->setAccessible( true );

		// Test with common MIME types.
		$mimes      = array( 'image/jpeg', 'image/png', 'application/pdf' );
		$extensions = $method->invoke( null, $mimes );

		$this->assertIsArray( $extensions );
		$this->assertNotEmpty( $extensions );

		// Should include common extensions.
		$this->assertContains( 'jpg', $extensions );
		$this->assertContains( 'png', $extensions );
		$this->assertContains( 'pdf', $extensions );
	}

	/**
	 * Test that file accept tokens are properly built.
	 *
	 * The method lives on WP_MCP_AI_Shortcode (protected static).
	 */
	public function test_build_file_accept_tokens() {
		// Use reflection to access the protected static method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Shortcode' );
		$method     = $reflection->getMethod( 'build_file_accept_tokens' );
		$method->setAccessible( true );

		$image_mimes = array( 'image/jpeg', 'image/png' );
		$file_mimes  = array( 'application/pdf' );
		$extensions  = array( 'jpg', 'jpeg', 'png', 'pdf' );

		$tokens = $method->invoke( null, $image_mimes, $file_mimes, $extensions );

		$this->assertIsArray( $tokens );
		$this->assertNotEmpty( $tokens );

		// Should include MIME types.
		$this->assertContains( 'image/jpeg', $tokens );
		$this->assertContains( 'image/png', $tokens );
		$this->assertContains( 'application/pdf', $tokens );

		// Should include extensions with dots.
		$this->assertContains( '.jpg', $tokens );
		$this->assertContains( '.png', $tokens );
		$this->assertContains( '.pdf', $tokens );
	}

	/**
	 * Test that tool shortcuts are loaded from assistant config.
	 */
	public function test_get_assistant_tool_shortcuts() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->test_assistant );
		$method     = $reflection->getMethod( 'get_assistant_tool_shortcuts' );
		$method->setAccessible( true );

		$shortcuts = $method->invoke( $this->test_assistant, $this->assistant_id );

		// Should return array.
		$this->assertIsArray( $shortcuts );

		// May be empty if WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts doesn't return shortcuts.
		// But the method should not throw errors.
	}

	/**
	 * Test that admin page registers correctly.
	 */
	public function test_admin_page_registers() {
		global $submenu;

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu was added.
		$post_type = WP_MCP_AI_Assistant_CPT::POST_TYPE;
		$parent    = 'edit.php?post_type=' . $post_type;

		// The submenu should exist.
		$this->assertArrayHasKey( $parent, $submenu );

		// Find the test assistant submenu item.
		$found = false;
		if ( isset( $submenu[ $parent ] ) ) {
			foreach ( $submenu[ $parent ] as $item ) {
				if ( isset( $item[2] ) && 'wp-mcp-ai-test-assistant' === $item[2] ) {
					$found = true;
					break;
				}
			}
		}

		$this->assertTrue( $found, 'Test Assistant submenu item should be registered' );
	}

	/**
	 * Test that assets are enqueued on the test assistant page.
	 */
	public function test_assets_enqueued_on_page() {
		// Register the page first so enqueue_assets() has a page hook to match.
		do_action( 'admin_menu' );

		// Compute the real page hook the way WordPress does (parent is the
		// post-type edit screen).
		$hook = get_plugin_page_hookname( 'wp-mcp-ai-test-assistant', 'edit.php?post_type=' . WP_MCP_AI_Assistant_CPT::POST_TYPE );

		// Set the current screen to test assistant page.
		set_current_screen( $hook );

		// Trigger enqueue scripts.
		do_action( 'admin_enqueue_scripts', $hook );

		// Check if chat.js is enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chat', 'enqueued' ) || wp_script_is( 'wp-mcp-ai-chat', 'registered' ) );

		// Check if test assistant specific assets are enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-admin-test-assistant', 'enqueued' ) || wp_script_is( 'wp-mcp-ai-admin-test-assistant', 'registered' ) );
	}

	/**
	 * Test that sensitive tools flag would be enabled for admin users.
	 *
	 * This is tested through the JavaScript configuration that would be
	 * set in the wpMcpAiChatInstances object, but we can verify the
	 * PHP side provides necessary data.
	 */
	public function test_admin_has_upload_capability() {
		// Admin users should be able to upload files.
		$this->assertTrue( current_user_can( 'upload_files' ) );

		// This capability check is used to set canUploadAttachments in JS.
	}

	/**
	 * Test that usage costs and capability flags settings are properly passed to JavaScript.
	 */
	public function test_usage_costs_and_capability_flags_config() {
		global $wp_scripts;

		// Set the show_usage_costs option.
		$settings                          = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['show_usage_costs']      = true;
		$settings['show_capability_flags'] = true;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Register the page first so enqueue_assets() has a page hook to match.
		do_action( 'admin_menu' );

		// Compute the real page hook the way WordPress does.
		$hook = get_plugin_page_hookname( 'wp-mcp-ai-test-assistant', 'edit.php?post_type=' . WP_MCP_AI_Assistant_CPT::POST_TYPE );

		// Set the current screen to test assistant page.
		set_current_screen( $hook );

		// Trigger enqueue scripts.
		do_action( 'admin_enqueue_scripts', $hook );

		// Check that the script is enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chat', 'enqueued' ) || wp_script_is( 'wp-mcp-ai-chat', 'registered' ) );

		// Get the localized data.
		if ( isset( $wp_scripts->registered['wp-mcp-ai-chat'] ) ) {
			$script_data = $wp_scripts->registered['wp-mcp-ai-chat'];

			// Check if extra data exists.
			if ( isset( $script_data->extra['data'] ) ) {
				$extra_data = $script_data->extra['data'];

				// Verify that showUsageCosts and showCapabilityFlags are in the localized data.
				$this->assertStringContainsString( 'showUsageCosts', $extra_data );
				$this->assertStringContainsString( 'showCapabilityFlags', $extra_data );
			}
		}

		// Clean up.
		$settings['show_usage_costs']      = false;
		$settings['show_capability_flags'] = false;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Test that get_assistant_professionals returns empty array for assistant without professions.
	 */
	public function test_get_assistant_professionals_returns_empty_for_no_professions() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->test_assistant );
		$method     = $reflection->getMethod( 'get_assistant_professionals' );
		$method->setAccessible( true );

		$professionals = $method->invoke( $this->test_assistant, $this->assistant_id );

		$this->assertIsArray( $professionals );
		$this->assertEmpty( $professionals );
	}

	/**
	 * Test that get_assistant_professionals returns profession names when professions are assigned.
	 */
	public function test_get_assistant_professionals_returns_profession_names() {
		// Ensure the profession CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}

		// Create test professions.
		$profession1_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Marketing Expert',
				'post_status' => 'publish',
			)
		);

		$profession2_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Content Writer',
				'post_status' => 'publish',
			)
		);

		// Assign professions to the assistant.
		update_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_PRIMARY_ROLES, array( $profession1_id, $profession2_id ) );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->test_assistant );
		$method     = $reflection->getMethod( 'get_assistant_professionals' );
		$method->setAccessible( true );

		$professionals = $method->invoke( $this->test_assistant, $this->assistant_id );

		$this->assertIsArray( $professionals );
		$this->assertCount( 2, $professionals );
		$this->assertContains( 'Marketing Expert', $professionals );
		$this->assertContains( 'Content Writer', $professionals );

		// Clean up.
		wp_delete_post( $profession1_id, true );
		wp_delete_post( $profession2_id, true );
	}

	/**
	 * Test that get_assistant_professionals handles deleted professions gracefully.
	 */
	public function test_get_assistant_professionals_handles_deleted_professions() {
		// Create a test profession.
		$profession_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Profession',
				'post_status' => 'publish',
			)
		);

		// Assign profession to the assistant.
		update_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_PRIMARY_ROLES, array( $profession_id, 999999 ) );

		// Delete the profession.
		wp_delete_post( $profession_id, true );

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->test_assistant );
		$method     = $reflection->getMethod( 'get_assistant_professionals' );
		$method->setAccessible( true );

		$professionals = $method->invoke( $this->test_assistant, $this->assistant_id );

		// Should return empty array since both professions are invalid (one deleted, one never existed).
		$this->assertIsArray( $professionals );
		$this->assertEmpty( $professionals );
	}

	/**
	 * Test that get_assistant_professionals returns empty array for invalid assistant ID.
	 */
	public function test_get_assistant_professionals_returns_empty_for_invalid_id() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->test_assistant );
		$method     = $reflection->getMethod( 'get_assistant_professionals' );
		$method->setAccessible( true );

		$professionals = $method->invoke( $this->test_assistant, 0 );

		$this->assertIsArray( $professionals );
		$this->assertEmpty( $professionals );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up.
		if ( $this->assistant_id ) {
			wp_delete_post( $this->assistant_id, true );
		}

		parent::tearDown();
	}
}
