<?php
/**
 * Tests for Project Management Admin Interface
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Project Management Admin metaboxes and columns.
 */
class WP_MCP_AI_Project_Management_Admin_Test extends WP_UnitTestCase {
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

		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Register post types. The helper only registers the auxiliary CPTs
		// (Task Plans / Task Templates); the main Project, Task and Event CPTs
		// are registered by their respective classes, so call those directly.
		// Leaving them unregistered makes map_meta_cap raise an incorrect-usage
		// notice when capabilities are checked against posts of those types.
		wp_mcp_ai_register_project_management_post_types();
		if ( class_exists( 'WP_MCP_AI_Project_CPT' ) ) {
			WP_MCP_AI_Project_CPT::register_post_type();
		}
		if ( class_exists( 'WP_MCP_AI_Task_CPT' ) ) {
			WP_MCP_AI_Task_CPT::register_post_type();
		}
		if ( class_exists( 'WP_MCP_AI_Event_CPT' ) ) {
			WP_MCP_AI_Event_CPT::register_post_type();
		}

		// Set user for tests.
		$admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user );
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
	 * Test that project metabox saves data correctly.
	 */
	public function test_project_metabox_saves_data() {
		// Create a project.
		$project_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_project',
				'post_title' => 'Test Project',
			)
		);

		// Simulate saving metabox data.
		$_POST['wp_mcp_ai_project_details_nonce'] = wp_create_nonce( 'wp_mcp_ai_project_details' );
		$_POST['project_status']                  = 'active';
		$_POST['project_start_date']              = '2025-01-01';
		$_POST['project_end_date']                = '2025-12-31';
		$_POST['project_assigned_to']             = array( get_current_user_id() );

		// Call save method.
		$post = get_post( $project_id );
		WP_MCP_AI_Project_Metabox::save_metabox( $project_id, $post );

		// Verify data was saved.
		$this->assertEquals( 'active', get_post_meta( $project_id, '_project_status', true ) );
		$this->assertEquals( '2025-01-01', get_post_meta( $project_id, '_project_start_date', true ) );
		$this->assertEquals( '2025-12-31', get_post_meta( $project_id, '_project_end_date', true ) );
		$assigned_to = get_post_meta( $project_id, '_project_assigned_to', true );
		$this->assertIsArray( $assigned_to );
		$this->assertContains( get_current_user_id(), $assigned_to );
	}

	/**
	 * Test that task metabox saves data correctly.
	 */
	public function test_task_metabox_saves_data() {
		// Create a project first.
		$project_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_project',
				'post_title' => 'Test Project',
			)
		);

		// Create a task.
		$task_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_task',
				'post_title' => 'Test Task',
			)
		);

		// Simulate saving metabox data.
		$_POST['wp_mcp_ai_task_details_nonce'] = wp_create_nonce( 'wp_mcp_ai_task_details' );
		$_POST['task_status']                  = 'in-progress';
		$_POST['task_priority']                = 'high';
		$_POST['task_project_id']              = $project_id;
		$_POST['task_due_date']                = '2025-06-30';
		$_POST['task_assigned_to']             = get_current_user_id();

		// Call save method.
		$post = get_post( $task_id );
		WP_MCP_AI_Task_Metabox::save_metabox( $task_id, $post );

		// Verify data was saved.
		$this->assertEquals( 'in-progress', get_post_meta( $task_id, '_task_status', true ) );
		$this->assertEquals( 'high', get_post_meta( $task_id, '_task_priority', true ) );
		$this->assertEquals( $project_id, get_post_meta( $task_id, '_task_project_id', true ) );
		$this->assertEquals( '2025-06-30', get_post_meta( $task_id, '_task_due_date', true ) );
		$this->assertEquals( get_current_user_id(), get_post_meta( $task_id, '_task_assigned_to', true ) );
	}

	/**
	 * Test that event metabox saves data correctly.
	 */
	public function test_event_metabox_saves_data() {
		// Create a project first.
		$project_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_project',
				'post_title' => 'Test Project',
			)
		);

		// Create an event.
		$event_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_event',
				'post_title' => 'Test Event',
			)
		);

		// Simulate saving metabox data.
		$_POST['wp_mcp_ai_event_details_nonce'] = wp_create_nonce( 'wp_mcp_ai_event_details' );
		$_POST['event_type']                    = 'meeting';
		// `event_all_day` is a checkbox: presence in POST means checked, absence
		// means unchecked. Omitting it saves the '0' value asserted below.
		$_POST['event_start_date'] = '2025-06-15';
		$_POST['event_start_time'] = '10:00';
		$_POST['event_end_date']   = '2025-06-15';
		$_POST['event_end_time']   = '11:00';
		$_POST['event_location']   = 'Conference Room A';
		$_POST['event_project_id'] = $project_id;
		$_POST['event_attendees']  = array( get_current_user_id() );

		// Call save method.
		$post = get_post( $event_id );
		WP_MCP_AI_Event_Metabox::save_metabox( $event_id, $post );

		// Verify data was saved.
		$this->assertEquals( 'meeting', get_post_meta( $event_id, '_event_type', true ) );
		$this->assertEquals( '0', get_post_meta( $event_id, '_event_all_day', true ) );
		$this->assertEquals( '2025-06-15', get_post_meta( $event_id, '_event_start_date', true ) );
		$this->assertEquals( '10:00', get_post_meta( $event_id, '_event_start_time', true ) );
		$this->assertEquals( '2025-06-15', get_post_meta( $event_id, '_event_end_date', true ) );
		$this->assertEquals( '11:00', get_post_meta( $event_id, '_event_end_time', true ) );
		$this->assertEquals( 'Conference Room A', get_post_meta( $event_id, '_event_location', true ) );
		$this->assertEquals( $project_id, get_post_meta( $event_id, '_event_project_id', true ) );
		$attendees = get_post_meta( $event_id, '_event_attendees', true );
		$this->assertIsArray( $attendees );
		$this->assertContains( get_current_user_id(), $attendees );
	}

	/**
	 * Test that admin columns are registered for projects.
	 */
	public function test_project_admin_columns_registered() {
		$columns = WP_MCP_AI_Project_Management_Admin_Columns::project_columns(
			array(
				'cb'     => 'CB',
				'title'  => 'Title',
				'author' => 'Author',
				'date'   => 'Date',
			)
		);

		$this->assertArrayHasKey( 'status', $columns );
		$this->assertArrayHasKey( 'start_date', $columns );
		$this->assertArrayHasKey( 'end_date', $columns );
		$this->assertArrayHasKey( 'assigned_to', $columns );
	}

	/**
	 * Test that admin columns are registered for tasks.
	 */
	public function test_task_admin_columns_registered() {
		$columns = WP_MCP_AI_Project_Management_Admin_Columns::task_columns(
			array(
				'cb'     => 'CB',
				'title'  => 'Title',
				'author' => 'Author',
				'date'   => 'Date',
			)
		);

		$this->assertArrayHasKey( 'status', $columns );
		$this->assertArrayHasKey( 'priority', $columns );
		$this->assertArrayHasKey( 'project', $columns );
		$this->assertArrayHasKey( 'due_date', $columns );
		$this->assertArrayHasKey( 'assigned_to', $columns );
	}

	/**
	 * Test that admin columns are registered for events.
	 */
	public function test_event_admin_columns_registered() {
		$columns = WP_MCP_AI_Project_Management_Admin_Columns::event_columns(
			array(
				'cb'     => 'CB',
				'title'  => 'Title',
				'author' => 'Author',
				'date'   => 'Date',
			)
		);

		$this->assertArrayHasKey( 'type', $columns );
		$this->assertArrayHasKey( 'start_date', $columns );
		$this->assertArrayHasKey( 'end_date', $columns );
		$this->assertArrayHasKey( 'location', $columns );
		$this->assertArrayHasKey( 'project', $columns );
	}
}
