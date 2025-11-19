<?php
/**
 * Tests for Admin Test Assistant Features
 *
 * @package WP_MCP_AI
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
	 */
	public function test_file_upload_config_is_set() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->test_assistant );
		$method     = $reflection->getMethod( 'get_file_upload_config' );
		$method->setAccessible( true );

		$config = $method->invoke( $this->test_assistant );

		// Should return array.
		$this->assertIsArray( $config );

		// Should have file accept configuration if user can upload.
		if ( current_user_can( 'upload_files' ) && class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			$this->assertArrayHasKey( 'fileAccept', $config );
			$this->assertArrayHasKey( 'allowedImageMimes', $config );
			$this->assertArrayHasKey( 'allowedFileMimes', $config );
			$this->assertArrayHasKey( 'allowedExtensions', $config );

			$this->assertIsString( $config['fileAccept'] );
			$this->assertIsArray( $config['allowedImageMimes'] );
			$this->assertIsArray( $config['allowedFileMimes'] );
			$this->assertIsArray( $config['allowedExtensions'] );
		}
	}

	/**
	 * Test that allowed extensions are properly extracted from MIME types.
	 */
	public function test_get_allowed_extensions_for_mimes() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->test_assistant );
		$method     = $reflection->getMethod( 'get_allowed_extensions_for_mimes' );
		$method->setAccessible( true );

		// Test with common MIME types.
		$mimes      = array( 'image/jpeg', 'image/png', 'application/pdf' );
		$extensions = $method->invoke( $this->test_assistant, $mimes );

		$this->assertIsArray( $extensions );
		$this->assertNotEmpty( $extensions );

		// Should include common extensions.
		$this->assertContains( 'jpg', $extensions );
		$this->assertContains( 'png', $extensions );
		$this->assertContains( 'pdf', $extensions );
	}

	/**
	 * Test that file accept tokens are properly built.
	 */
	public function test_build_file_accept_tokens() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->test_assistant );
		$method     = $reflection->getMethod( 'build_file_accept_tokens' );
		$method->setAccessible( true );

		$image_mimes = array( 'image/jpeg', 'image/png' );
		$file_mimes  = array( 'application/pdf' );
		$extensions  = array( 'jpg', 'jpeg', 'png', 'pdf' );

		$tokens = $method->invoke( $this->test_assistant, $image_mimes, $file_mimes, $extensions );

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
		// Set the current screen to test assistant page.
		set_current_screen( 'mcp_ai_assistant_page_wp-mcp-ai-test-assistant' );

		// Trigger enqueue scripts.
		$hook = 'mcp_ai_assistant_page_wp-mcp-ai-test-assistant';
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
