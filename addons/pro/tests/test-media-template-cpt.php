<?php
/**
 * Tests for Media Template CPT admin notices and basic functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Media Template CPT admin notice functionality.
 */
class Test_Media_Template_CPT_Admin_Notice extends WP_UnitTestCase {
	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the Media Template CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-template-cpt.php';
		}

		// Set up global $current_screen for admin context.
		set_current_screen( 'edit-mcp_ai_media_tpl' );
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
	 * Test that admin notice is not shown when media toolkit is enabled.
	 */
	public function test_no_notice_when_enabled() {
		// Enable media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Simulate accessing media template page.
		$_GET['post_type'] = 'mcp_ai_media_tpl';

		// Capture output.
		ob_start();
		WP_MCP_AI_Media_Template_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should not show notice when enabled.
		$this->assertEmpty( $output );

		// Clean up.
		unset( $_GET['post_type'] );
	}

	/**
	 * Test that admin notice is shown when media toolkit is disabled.
	 */
	public function test_notice_shown_when_disabled() {
		// Disable media toolkit (default state).
		update_option( 'wp_mcp_ai_settings', array() );

		// Simulate accessing media template page.
		$_GET['post_type'] = 'mcp_ai_media_tpl';

		// Capture output.
		ob_start();
		WP_MCP_AI_Media_Template_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should show notice with specific text.
		$this->assertStringContainsString( 'Media Toolkit Disabled', $output );
		$this->assertStringContainsString( 'Enable Media Toolkit', $output );

		// Clean up.
		unset( $_GET['post_type'] );
	}

	/**
	 * Test that CPT is registered when enabled.
	 */
	public function test_cpt_registered_when_enabled() {
		// Enable media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Initialize CPT.
		WP_MCP_AI_Media_Template_CPT::init();

		// Trigger init action to register post type.
		do_action( 'init' );

		// Check if post type is registered.
		$this->assertTrue( post_type_exists( 'mcp_ai_media_tpl' ) );

		// Check if taxonomy is registered.
		$this->assertTrue( taxonomy_exists( 'mcp_ai_tpl_category' ) );
	}

	/**
	 * Test that CPT is registered when Pro addon is active even in base mode.
	 */
	public function test_cpt_registered_when_pro_active_in_base_mode() {
		// Enable media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Simulate base version mode.
		if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			define( 'WP_MCP_AI_BASE_VERSION', true );
		}

		// Pro addon constant should already be defined when the pro addon is loaded.
		// In this test environment, WP_MCP_AI_PRO_VERSION should be defined.
		$this->assertTrue( defined( 'WP_MCP_AI_PRO_VERSION' ), 'Pro addon should be active in this test environment' );

		// Initialize CPT - should succeed because Pro is active.
		WP_MCP_AI_Media_Template_CPT::init();

		// Trigger init action to register post type.
		do_action( 'init' );

		// Check if post type is registered even in base mode when Pro is active.
		$this->assertTrue( post_type_exists( 'mcp_ai_media_tpl' ) );

		// Check if taxonomy is registered.
		$this->assertTrue( taxonomy_exists( 'mcp_ai_tpl_category' ) );
	}

	/**
	 * Test that template meta can be saved and retrieved.
	 */
	public function test_template_meta_save_and_retrieve() {
		// Create a test user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		// Enable media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Initialize CPT.
		WP_MCP_AI_Media_Template_CPT::init();
		do_action( 'init' );

		// Create a template post.
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_media_tpl',
				'post_title'  => 'Test Template',
				'post_status' => 'publish',
			)
		);

		// Set template metadata.
		update_post_meta( $post_id, '_mcp_ai_template_operation', 'add_logo' );
		update_post_meta(
			$post_id,
			'_mcp_ai_template_parameters',
			wp_json_encode(
				array(
					'logo_position' => 'bottom-right',
					'logo_scale'    => 0.2,
				)
			)
		);
		update_post_meta( $post_id, '_mcp_ai_template_usage_count', 5 );

		// Retrieve and verify metadata.
		$operation   = get_post_meta( $post_id, '_mcp_ai_template_operation', true );
		$parameters  = get_post_meta( $post_id, '_mcp_ai_template_parameters', true );
		$usage_count = get_post_meta( $post_id, '_mcp_ai_template_usage_count', true );

		$this->assertEquals( 'add_logo', $operation );
		$this->assertNotEmpty( $parameters );

		$params = json_decode( $parameters, true );
		$this->assertEquals( 'bottom-right', $params['logo_position'] );
		$this->assertEquals( 0.2, $params['logo_scale'] );
		$this->assertEquals( 5, absint( $usage_count ) );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test admin columns are added correctly.
	 */
	public function test_admin_columns_added() {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => 'Title',
			'date'  => 'Date',
		);

		$new_columns = WP_MCP_AI_Media_Template_CPT::add_admin_columns( $columns );

		// Check that new columns are added after title.
		$this->assertArrayHasKey( 'operation', $new_columns );
		$this->assertArrayHasKey( 'usage_count', $new_columns );
		$this->assertArrayHasKey( 'last_used', $new_columns );

		// Verify order (columns should be after title).
		$keys            = array_keys( $new_columns );
		$title_index     = array_search( 'title', $keys, true );
		$operation_index = array_search( 'operation', $keys, true );

		$this->assertGreaterThan( $title_index, $operation_index );
	}
}
