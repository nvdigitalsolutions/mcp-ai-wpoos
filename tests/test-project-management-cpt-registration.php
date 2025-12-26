<?php
/**
 * Tests for Project Management CPT Registration.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Project Management CPT registration functionality.
 */
class WP_MCP_AI_Project_Management_CPT_Registration_Test extends WP_UnitTestCase {
	/**
	 * Original settings value to restore after tests.
	 *
	 * @var array
	 */
	private $original_settings;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Store original settings.
		$this->original_settings = get_option( 'wp_mcp_ai_settings', array() );

		// Unregister any existing post types to start fresh.
		global $wp_post_types;
		if ( isset( $wp_post_types['mcp_ai_project'] ) ) {
			unset( $wp_post_types['mcp_ai_project'] );
		}
		if ( isset( $wp_post_types['mcp_ai_task'] ) ) {
			unset( $wp_post_types['mcp_ai_task'] );
		}
		if ( isset( $wp_post_types['mcp_ai_event'] ) ) {
			unset( $wp_post_types['mcp_ai_event'] );
		}
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Restore original settings.
		update_option( 'wp_mcp_ai_settings', $this->original_settings );

		parent::tearDown();
	}

	/**
	 * Test that project management CPTs are registered when feature is enabled.
	 */
	public function test_cpts_registered_when_enabled() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_project_management_post_types();

		// Verify post types are registered.
		$this->assertTrue( post_type_exists( 'mcp_ai_project' ), 'Project CPT should be registered' );
		$this->assertTrue( post_type_exists( 'mcp_ai_task' ), 'Task CPT should be registered' );
		$this->assertTrue( post_type_exists( 'mcp_ai_event' ), 'Event CPT should be registered' );
	}

	/**
	 * Test that project management CPTs are NOT registered when feature is disabled.
	 */
	public function test_cpts_not_registered_when_disabled() {
		// Disable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_project_management_post_types();

		// Verify post types are NOT registered.
		$this->assertFalse( post_type_exists( 'mcp_ai_project' ), 'Project CPT should NOT be registered when disabled' );
		$this->assertFalse( post_type_exists( 'mcp_ai_task' ), 'Task CPT should NOT be registered when disabled' );
		$this->assertFalse( post_type_exists( 'mcp_ai_event' ), 'Event CPT should NOT be registered when disabled' );
	}

	/**
	 * Test that project CPT has show_in_menu set to true.
	 */
	public function test_project_cpt_shows_in_menu() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_project_management_post_types();

		// Get the post type object.
		$project_post_type = get_post_type_object( 'mcp_ai_project' );

		// Verify show_in_menu is set to true.
		$this->assertTrue( $project_post_type->show_in_menu, 'Project CPT should have show_in_menu set to true' );
		$this->assertEquals( 'dashicons-portfolio', $project_post_type->menu_icon, 'Project CPT should have portfolio icon' );
	}

	/**
	 * Test that task CPT has show_in_menu set to true.
	 */
	public function test_task_cpt_shows_in_menu() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_project_management_post_types();

		// Get the post type object.
		$task_post_type = get_post_type_object( 'mcp_ai_task' );

		// Verify show_in_menu is set to true.
		$this->assertTrue( $task_post_type->show_in_menu, 'Task CPT should have show_in_menu set to true' );
		$this->assertEquals( 'dashicons-list-view', $task_post_type->menu_icon, 'Task CPT should have list-view icon' );
	}

	/**
	 * Test that event CPT has show_in_menu set to true.
	 */
	public function test_event_cpt_shows_in_menu() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_project_management_post_types();

		// Get the post type object.
		$event_post_type = get_post_type_object( 'mcp_ai_event' );

		// Verify show_in_menu is set to true.
		$this->assertTrue( $event_post_type->show_in_menu, 'Event CPT should have show_in_menu set to true' );
		$this->assertEquals( 'dashicons-calendar-alt', $event_post_type->menu_icon, 'Event CPT should have calendar icon' );
	}

	/**
	 * Test that all CPTs have correct labels.
	 */
	public function test_cpt_labels() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_project_management_post_types();

		// Check Project labels.
		$project_post_type = get_post_type_object( 'mcp_ai_project' );
		$this->assertEquals( 'Projects', $project_post_type->labels->name );
		$this->assertEquals( 'Project', $project_post_type->labels->singular_name );

		// Check Task labels.
		$task_post_type = get_post_type_object( 'mcp_ai_task' );
		$this->assertEquals( 'Tasks', $task_post_type->labels->name );
		$this->assertEquals( 'Task', $task_post_type->labels->singular_name );

		// Check Event labels.
		$event_post_type = get_post_type_object( 'mcp_ai_event' );
		$this->assertEquals( 'Events', $event_post_type->labels->name );
		$this->assertEquals( 'Event', $event_post_type->labels->singular_name );
	}

	/**
	 * Test that CPTs are not public but show UI.
	 */
	public function test_cpt_visibility_settings() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Trigger the registration.
		wp_mcp_ai_register_project_management_post_types();

		// Check all three CPTs.
		$post_types = array( 'mcp_ai_project', 'mcp_ai_task', 'mcp_ai_event' );

		foreach ( $post_types as $post_type_name ) {
			$post_type = get_post_type_object( $post_type_name );

			$this->assertFalse( $post_type->public, "$post_type_name should not be public" );
			$this->assertTrue( $post_type->show_ui, "$post_type_name should show UI" );
			$this->assertTrue( $post_type->show_in_rest, "$post_type_name should show in REST API" );
			$this->assertFalse( $post_type->has_archive, "$post_type_name should not have archive" );
		}
	}
}
