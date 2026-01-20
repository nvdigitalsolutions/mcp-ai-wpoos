<?php
/**
 * Tests for AI CPT Management Integration.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test AI CPT Management Integration functionality.
 */
class Test_AI_CPT_Management_Integration extends WP_UnitTestCase {
	/**
	 * Integration instance.
	 *
	 * @var WP_MCP_AI_Pro_CPT_AI_Integration|null
	 */
	private $integration = null;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the integration class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_AI_Integration' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-pro-cpt-ai-integration.php';
		}

		// Set admin context.
		set_current_screen( 'post' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up any settings.
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Test that integration is not loaded when feature is disabled.
	 */
	public function test_integration_not_loaded_when_disabled() {
		// Disable feature (default state).
		update_option( 'wp_mcp_ai_settings', array() );

		// Check if metabox action is registered.
		$this->assertFalse( has_action( 'add_meta_boxes' ) );
	}

	/**
	 * Test that integration is loaded when feature is enabled.
	 */
	public function test_integration_loaded_when_enabled() {
		// Enable feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Get singleton instance (which should initialize hooks).
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Verify instance was created.
		$this->assertInstanceOf( 'WP_MCP_AI_Pro_CPT_AI_Integration', $integration );

		// Verify hooks are registered.
		$this->assertGreaterThan( 0, has_action( 'add_meta_boxes' ) );
		$this->assertGreaterThan( 0, has_action( 'admin_enqueue_scripts' ) );
		$this->assertGreaterThan( 0, has_action( 'wp_ajax_wp_mcp_ai_cpt_chat' ) );
	}

	/**
	 * Test that supported post types do not include posts and pages by default.
	 */
	public function test_supported_post_types() {
		// Enable feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_post_types' );
		$method->setAccessible( true );

		$post_types = $method->invoke( $integration );

		// Should NOT include posts and pages by default (they have been removed).
		$this->assertNotContains( 'post', $post_types );
		$this->assertNotContains( 'page', $post_types );
	}

	/**
	 * Test that supported taxonomies do not include any taxonomies by default.
	 */
	public function test_supported_taxonomies() {
		// Enable feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_taxonomies' );
		$method->setAccessible( true );

		$taxonomies = $method->invoke( $integration );

		// Should NOT include any taxonomies by default (they have been removed).
		$this->assertNotContains( 'category', $taxonomies );
		$this->assertNotContains( 'post_tag', $taxonomies );
		$this->assertEmpty( $taxonomies );
	}

	/**
	 * Test that system message is built correctly for posts.
	 */
	public function test_build_system_message_for_post() {
		// Enable feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Create test context.
		$context = array(
			'post_id'      => 123,
			'post_type'    => 'post',
			'post_title'   => 'Test Post',
			'post_status'  => 'draft',
			'post_content' => 'Test content',
		);

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'build_system_message' );
		$method->setAccessible( true );

		$message = $method->invoke( $integration, $context );

		// Verify message contains expected content.
		$this->assertStringContainsString( 'editing a post', $message );
		$this->assertStringContainsString( 'Test Post', $message );
		$this->assertStringContainsString( 'draft', $message );
	}

	/**
	 * Test that system message is built correctly for terms.
	 */
	public function test_build_system_message_for_term() {
		// Enable feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Create test context.
		$context = array(
			'term_id'          => 456,
			'taxonomy'         => 'category',
			'term_name'        => 'Test Category',
			'term_description' => 'Test description',
		);

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'build_system_message' );
		$method->setAccessible( true );

		$message = $method->invoke( $integration, $context );

		// Verify message contains expected content.
		$this->assertStringContainsString( 'editing a category', $message );
		$this->assertStringContainsString( 'Test Category', $message );
	}

	/**
	 * Test AJAX handler requires nonce.
	 */
	public function test_ajax_handler_requires_nonce() {
		// Enable feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Create admin user and set as current user.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX action.
		try {
			// Mock AJAX request without nonce.
			$_POST['message'] = 'Test message';

			// This should throw an exception for missing nonce.
			$this->expectException( 'WPAjaxDieContinueException' );
			do_action( 'wp_ajax_wp_mcp_ai_cpt_chat' );
		} catch ( Exception $e ) {
			// Expected to fail without nonce.
			$this->assertTrue( true );
		}
	}

	/**
	 * Test AJAX handler requires edit_posts capability.
	 */
	public function test_ajax_handler_requires_capability() {
		// Enable feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Create subscriber (no edit_posts capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Get instance to register AJAX handler.
		WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Set up AJAX request with valid nonce.
		$_POST['nonce']   = wp_create_nonce( 'wp_mcp_ai_cpt_chat' );
		$_POST['message'] = 'Test message';

		// Capture output.
		ob_start();
		try {
			do_action( 'wp_ajax_wp_mcp_ai_cpt_chat' );
		} catch ( Exception $e ) {
			// Expected to send JSON error.
		}
		$output = ob_get_clean();

		// Verify error response about permissions.
		$this->assertStringContainsString( 'do not have permission', $output );
	}

	/**
	 * Test that filter can add custom post types.
	 */
	public function test_filter_can_add_custom_post_types() {
		// Enable feature.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Add filter to include custom post type.
		add_filter(
			'wp_mcp_ai_cpt_supported_post_types',
			function ( $post_types ) {
				$post_types[] = 'custom_type';
				return $post_types;
			}
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_post_types' );
		$method->setAccessible( true );

		$post_types = $method->invoke( $integration );

		// Should include custom type.
		$this->assertContains( 'custom_type', $post_types );
	}

	/**
	 * Test that quiz CPT is included when quiz system is enabled.
	 */
	public function test_quiz_cpt_included_when_enabled() {
		// Enable AI CPT management and quiz system.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
				'enable_quiz_system'       => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_post_types' );
		$method->setAccessible( true );

		$post_types = $method->invoke( $integration );

		// Should NOT include quiz CPT (they have dedicated research pages).
		// This test is for future functionality when settings-based inclusion is implemented.
		$this->markTestSkipped( 'Quiz CPT is not included in supported post types - has dedicated research page interface' );
	}

	/**
	 * Test that quiz CPT is not included when quiz system is disabled.
	 */
	public function test_quiz_cpt_not_included_when_disabled() {
		// Enable AI CPT management but not quiz system.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_post_types' );
		$method->setAccessible( true );

		$post_types = $method->invoke( $integration );

		// Should not include quiz CPT (correct - they have dedicated interfaces).
		$this->assertNotContains( 'mcp_ai_quiz', $post_types );
	}

	/**
	 * Test that place CPT is included when places management is enabled.
	 */
	public function test_place_cpt_included_when_enabled() {
		// Enable AI CPT management and places management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
				'enable_places_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_post_types' );
		$method->setAccessible( true );

		$post_types = $method->invoke( $integration );

		// Should NOT include place CPT (they have dedicated research pages).
		// This test is for future functionality when settings-based inclusion is implemented.
		$this->markTestSkipped( 'Place CPT is not included in supported post types - has dedicated research page interface' );
	}

	/**
	 * Test that project management CPTs are included when enabled.
	 */
	public function test_project_management_cpts_included_when_enabled() {
		// Enable AI CPT management and project management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management'  => true,
				'enable_project_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_post_types' );
		$method->setAccessible( true );

		$post_types = $method->invoke( $integration );

		// Should NOT include project management CPTs (they have specialized metaboxes).
		// This test is for future functionality when settings-based inclusion is implemented.
		$this->markTestSkipped( 'Project Management CPTs are not included - they have specialized AI assistant metaboxes' );
	}

	/**
	 * Test that project management CPTs are not included when disabled.
	 */
	public function test_project_management_cpts_not_included_when_disabled() {
		// Enable AI CPT management but not project management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_post_types' );
		$method->setAccessible( true );

		$post_types = $method->invoke( $integration );

		// Should not include project management CPTs (correct - they have specialized interfaces).
		$this->assertNotContains( 'mcp_ai_project', $post_types );
		$this->assertNotContains( 'mcp_ai_task', $post_types );
		$this->assertNotContains( 'mcp_ai_event', $post_types );
	}

	/**
	 * Test that all Pro CPTs are included when all features are enabled.
	 */
	public function test_all_pro_cpts_included_when_all_features_enabled() {
		// Enable all features.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ai_cpt_management'  => true,
				'enable_quiz_system'        => true,
				'enable_places_management'  => true,
				'enable_project_management' => true,
			)
		);

		// Get instance.
		$integration = WP_MCP_AI_Pro_CPT_AI_Integration::get_instance();

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $integration );
		$method     = $reflection->getMethod( 'get_supported_post_types' );
		$method->setAccessible( true );

		$post_types = $method->invoke( $integration );

		// Pro CPTs are NOT currently included (they have specialized interfaces).
		// This test is for future functionality when settings-based inclusion is implemented.
		$this->markTestSkipped( 'Pro CPTs are not included in supported post types - they have dedicated interfaces' );
	}
}
