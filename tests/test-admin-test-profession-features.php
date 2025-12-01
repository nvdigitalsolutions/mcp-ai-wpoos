<?php
/**
 * Tests for Admin Test Profession Features
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test Admin Test Profession class.
 */
class Test_Admin_Test_Profession_Features extends WP_UnitTestCase {

	/**
	 * Test profession instance.
	 *
	 * @var WP_MCP_AI_Admin_Test_Profession
	 */
	private $test_profession;

	/**
	 * Test profession post ID.
	 *
	 * @var int
	 */
	private $profession_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure required classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Profession' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-profession.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}

		// Create test profession.
		$this->profession_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_title'  => 'Test Profession',
				'post_status' => 'publish',
			)
		);

		// Add some profession meta.
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, 'technical' );
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, array( 'testing', 'debugging' ) );
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, array( 'search_posts', 'create_post' ) );

		// Set up test profession instance.
		$this->test_profession = new WP_MCP_AI_Admin_Test_Profession();

		// Set current user as admin.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
	}

	/**
	 * Test that admin page registers correctly.
	 */
	public function test_admin_page_registers() {
		global $submenu;

		// Trigger admin_menu action.
		do_action( 'admin_menu' );

		// Check if submenu was added.
		$post_type = WP_MCP_AI_Profession_CPT::POST_TYPE;
		$parent    = 'edit.php?post_type=' . $post_type;

		// The submenu should exist.
		$this->assertArrayHasKey( $parent, $submenu );

		// Find the test profession submenu item.
		$found = false;
		if ( isset( $submenu[ $parent ] ) ) {
			foreach ( $submenu[ $parent ] as $item ) {
				if ( isset( $item[2] ) && 'wp-mcp-ai-test-profession' === $item[2] ) {
					$found = true;
					break;
				}
			}
		}

		$this->assertTrue( $found, 'Test Profession submenu item should be registered' );
	}

	/**
	 * Test that assets are enqueued on the test profession page.
	 */
	public function test_assets_enqueued_on_page() {
		// Set the current screen to test profession page.
		set_current_screen( 'mcp_ai_profession_page_wp-mcp-ai-test-profession' );

		// Trigger enqueue scripts.
		$hook = 'mcp_ai_profession_page_wp-mcp-ai-test-profession';
		do_action( 'admin_enqueue_scripts', $hook );

		// Check if chat.js is enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-chat', 'enqueued' ) || wp_script_is( 'wp-mcp-ai-chat', 'registered' ) );

		// Check if test profession specific assets are enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-admin-test-profession', 'enqueued' ) || wp_script_is( 'wp-mcp-ai-admin-test-profession', 'registered' ) );
	}

	/**
	 * Test that page slug is correct.
	 */
	public function test_page_slug() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->test_profession );
		$method     = $reflection->getMethod( 'get_page_slug' );
		$method->setAccessible( true );

		$slug = $method->invoke( $this->test_profession );

		$this->assertEquals( 'wp-mcp-ai-test-profession', $slug );
	}

	/**
	 * Test that post type is correct.
	 */
	public function test_post_type() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->test_profession );
		$method     = $reflection->getMethod( 'get_post_type' );
		$method->setAccessible( true );

		$post_type = $method->invoke( $this->test_profession );

		$this->assertEquals( WP_MCP_AI_Profession_CPT::POST_TYPE, $post_type );
	}

	/**
	 * Test that page title is set.
	 */
	public function test_page_title() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->test_profession );
		$method     = $reflection->getMethod( 'get_page_title' );
		$method->setAccessible( true );

		$title = $method->invoke( $this->test_profession );

		$this->assertNotEmpty( $title );
		$this->assertIsString( $title );
	}

	/**
	 * Test that chat strings are customized for profession context.
	 */
	public function test_chat_strings_customization() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->test_profession );
		$method     = $reflection->getMethod( 'get_chat_strings' );
		$method->setAccessible( true );

		$strings = $method->invoke( $this->test_profession );

		$this->assertIsArray( $strings );
		$this->assertArrayHasKey( 'missingAssistant', $strings );
		$this->assertArrayHasKey( 'roleLabels', $strings );

		// Verify profession-specific customizations.
		$this->assertStringContainsString( 'Profession', $strings['missingAssistant'] );
		$this->assertEquals( 'Professional', $strings['roleLabels']['assistant'] );
	}

	/**
	 * Test that profession details are included in test button data attributes.
	 */
	public function test_profession_details_in_button_data() {
		// Start output buffering to capture rendered HTML.
		ob_start();
		$this->test_profession->render_page();
		$output = ob_get_clean();

		// Verify that profession data is included in the button.
		$this->assertStringContainsString( 'data-profession-data=', $output );
		$this->assertStringContainsString( 'data-profession-id="' . $this->profession_id . '"', $output );
		$this->assertStringContainsString( 'Test Profession', $output );

		// Check that profession metadata is in the JSON data.
		$this->assertStringContainsString( 'technical', $output );
	}

	/**
	 * Test that profession details container exists in modal.
	 */
	public function test_profession_details_container_in_modal() {
		// Start output buffering to capture rendered HTML.
		ob_start();
		$this->test_profession->render_page();
		$output = ob_get_clean();

		// Verify that the details container exists in the modal.
		$this->assertStringContainsString( 'wp-mcp-ai-profession-details-container', $output );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up.
		if ( $this->profession_id ) {
			wp_delete_post( $this->profession_id, true );
		}

		parent::tearDown();
	}
}
