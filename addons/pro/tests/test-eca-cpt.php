<?php
/**
 * Tests for ECA CPT admin notices and functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test ECA CPT admin notice functionality.
 */
class Test_ECA_CPT_Admin_Notice extends WP_UnitTestCase {
	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the ECA CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_ECA_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-eca-cpt.php';
		}

		// Load metabox classes for testing.
		if ( ! class_exists( 'WP_MCP_AI_ECA_Metabox_Base' ) ) {
			require_once dirname( __DIR__ ) . '/includes/metaboxes/class-wp-mcp-ai-eca-metabox-base.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_ECA_Metabox_Details' ) ) {
			require_once dirname( __DIR__ ) . '/includes/metaboxes/class-wp-mcp-ai-eca-metabox-details.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_ECA_Metabox_Schedule' ) ) {
			require_once dirname( __DIR__ ) . '/includes/metaboxes/class-wp-mcp-ai-eca-metabox-schedule.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_ECA_Metabox_Enrollment' ) ) {
			require_once dirname( __DIR__ ) . '/includes/metaboxes/class-wp-mcp-ai-eca-metabox-enrollment.php';
		}

		// Set up global $current_screen for admin context.
		set_current_screen( 'edit-mcp_ai_eca' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up any settings.
		delete_option( 'wp_mcp_ai_settings' );

		// Clean up global variables.
		if ( isset( $_GET['post_type'] ) ) {
			unset( $_GET['post_type'] );
		}

		parent::tearDown();
	}

	/**
	 * Test that admin notice is not shown when ECA management is enabled.
	 */
	public function test_no_notice_when_enabled() {
		// Enable ECA management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management' => true,
			)
		);

		// Set up screen for ECA page.
		set_current_screen( 'edit-mcp_ai_eca' );

		// Capture output.
		ob_start();
		WP_MCP_AI_ECA_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should not show notice when enabled.
		$this->assertEmpty( $output );
	}

	/**
	 * Test that admin notice is shown when ECA management is disabled.
	 */
	public function test_notice_shown_when_disabled() {
		// Disable ECA management (default state).
		update_option( 'wp_mcp_ai_settings', array() );

		// Set up screen for ECA page.
		set_current_screen( 'edit-mcp_ai_eca' );

		// Capture output.
		ob_start();
		WP_MCP_AI_ECA_CPT::show_disabled_notice();
		$output = ob_get_clean();

		// Should show notice with specific text.
		$this->assertStringContainsString( 'ECA Management Disabled', $output );
		$this->assertStringContainsString( 'Enable ECA Management', $output );
	}

	/**
	 * Test that post types are registered correctly.
	 */
	public function test_post_types_registered() {
		// Enable ECA management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management' => true,
			)
		);

		// Register post types.
		WP_MCP_AI_ECA_CPT::register_post_types();

		// Check ECA post type is registered.
		$this->assertTrue( post_type_exists( 'mcp_ai_eca' ) );

		// Check Student post type is registered.
		$this->assertTrue( post_type_exists( 'mcp_ai_student' ) );

		// Get ECA post type object.
		$eca_post_type = get_post_type_object( 'mcp_ai_eca' );

		// Verify post type settings.
		$this->assertEquals( 'ECAs', $eca_post_type->labels->name );
		$this->assertFalse( $eca_post_type->public );
		$this->assertTrue( $eca_post_type->show_ui );
		$this->assertContains( 'title', $eca_post_type->supports );
		$this->assertContains( 'editor', $eca_post_type->supports );
	}

	/**
	 * Test that ECA meta can be saved.
	 */
	public function test_eca_meta_saved() {
		// Enable ECA management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management' => true,
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create an ECA post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_eca',
				'post_title'  => 'Test ECA',
				'post_status' => 'publish',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Simulate POST data for metaboxes.
		$_POST['wp_mcp_ai_eca_details_nonce'] = wp_create_nonce( 'wp_mcp_ai_eca_details_nonce' );
		$_POST['wp_mcp_ai_eca_code']          = 'ECA-001';
		$_POST['wp_mcp_ai_eca_type']          = 'club';
		$_POST['wp_mcp_ai_eca_venue']         = 'Sports Hall';
		$_POST['wp_mcp_ai_eca_status']        = 'active';
		$_POST['wp_mcp_ai_eca_is_paid']       = 'yes';
		$_POST['wp_mcp_ai_eca_cost']          = '50.00';
		$_POST['wp_mcp_ai_eca_cost_period']   = 'term';

		// Get the post object.
		$post = get_post( $post_id );

		// Manually call save handler since we're not in real WordPress context.
		if ( class_exists( 'WP_MCP_AI_ECA_Metabox_Details' ) ) {
			$metabox = new WP_MCP_AI_ECA_Metabox_Details();
			$metabox->save( $post_id, $post );
		}

		// Verify meta values are saved.
		$this->assertEquals( 'ECA-001', get_post_meta( $post_id, '_eca_code', true ) );
		$this->assertEquals( 'club', get_post_meta( $post_id, '_eca_type', true ) );
		$this->assertEquals( 'Sports Hall', get_post_meta( $post_id, '_eca_venue', true ) );
		$this->assertEquals( 'active', get_post_meta( $post_id, '_eca_status', true ) );
		$this->assertEquals( 'yes', get_post_meta( $post_id, '_eca_is_paid', true ) );
		$this->assertEquals( 50.0, (float) get_post_meta( $post_id, '_eca_cost', true ) );
		$this->assertEquals( 'term', get_post_meta( $post_id, '_eca_cost_period', true ) );

		// Clean up.
		wp_delete_post( $post_id, true );
		unset( $_POST );
	}

	/**
	 * Test that schedule meta can be saved.
	 */
	public function test_schedule_meta_saved() {
		// Enable ECA management.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_eca_management' => true,
			)
		);

		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create an ECA post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_eca',
				'post_title'  => 'Test ECA Schedule',
				'post_status' => 'publish',
			)
		);

		// Simulate POST data for schedule metabox.
		$_POST['wp_mcp_ai_eca_schedule_nonce'] = wp_create_nonce( 'wp_mcp_ai_eca_schedule_nonce' );
		$_POST['wp_mcp_ai_eca_day']            = 'Monday';
		$_POST['wp_mcp_ai_eca_start_time']     = '3:30 PM';
		$_POST['wp_mcp_ai_eca_end_time']       = '4:30 PM';
		$_POST['wp_mcp_ai_eca_teachers']       = 'Mr. Smith, Ms. Johnson';
		$_POST['wp_mcp_ai_eca_year_groups']    = 'Year 7, Year 8';

		// Get the post object.
		$post = get_post( $post_id );

		// Manually call save handler.
		if ( class_exists( 'WP_MCP_AI_ECA_Metabox_Schedule' ) ) {
			$metabox = new WP_MCP_AI_ECA_Metabox_Schedule();
			$metabox->save( $post_id, $post );
		}

		// Verify meta values are saved.
		$this->assertEquals( 'Monday', get_post_meta( $post_id, '_eca_day', true ) );
		$this->assertEquals( '3:30 PM', get_post_meta( $post_id, '_eca_start_time', true ) );
		$this->assertEquals( '4:30 PM', get_post_meta( $post_id, '_eca_end_time', true ) );

		$teachers = get_post_meta( $post_id, '_eca_teachers', true );
		$this->assertIsArray( $teachers );
		$this->assertContains( 'Mr. Smith', $teachers );
		$this->assertContains( 'Ms. Johnson', $teachers );

		$year_groups = get_post_meta( $post_id, '_eca_year_groups', true );
		$this->assertIsArray( $year_groups );
		$this->assertContains( 'Year 7', $year_groups );
		$this->assertContains( 'Year 8', $year_groups );

		// Clean up.
		wp_delete_post( $post_id, true );
		unset( $_POST );
	}
}
