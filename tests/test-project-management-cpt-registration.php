<?php
/**
 * Tests for Project Management CPT Registration.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
		global $wp_post_types, $wp_taxonomies;
		if ( isset( $wp_post_types['mcp_ai_project'] ) ) {
			unset( $wp_post_types['mcp_ai_project'] );
		}
		if ( isset( $wp_post_types['mcp_ai_task'] ) ) {
			unset( $wp_post_types['mcp_ai_task'] );
		}
		if ( isset( $wp_post_types['mcp_ai_event'] ) ) {
			unset( $wp_post_types['mcp_ai_event'] );
		}
		if ( isset( $wp_taxonomies['mcp_ai_project_category'] ) ) {
			unset( $wp_taxonomies['mcp_ai_project_category'] );
		}
		if ( isset( $wp_taxonomies['mcp_ai_task_category'] ) ) {
			unset( $wp_taxonomies['mcp_ai_task_category'] );
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
	 * Register the main Project Management post types via their CPT classes.
	 *
	 * Production wires these registrations onto init at plugin load, but the
	 * test environment flips the feature flag after load, so call the public
	 * static registration entry points directly.
	 *
	 * @return void
	 */
	private function register_main_cpts() {
		WP_MCP_AI_Project_CPT::register_post_type();
		WP_MCP_AI_Task_CPT::register_post_type();
		WP_MCP_AI_Event_CPT::register_post_type();
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
		$this->register_main_cpts();

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
		$this->register_main_cpts();

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
		$this->register_main_cpts();

		// Get the post type object.
		$task_post_type = get_post_type_object( 'mcp_ai_task' );

		// Verify show_in_menu is set to true.
		$this->assertTrue( $task_post_type->show_in_menu, 'Task CPT should have show_in_menu set to true' );
		$this->assertEquals( 'dashicons-editor-ol-rtl', $task_post_type->menu_icon, 'Task CPT should have list icon' );
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
		$this->register_main_cpts();

		// Get the post type object.
		$event_post_type = get_post_type_object( 'mcp_ai_event' );

		// Verify show_in_menu is set to true.
		$this->assertTrue( $event_post_type->show_in_menu, 'Event CPT should have show_in_menu set to true' );
		$this->assertEquals( 'dashicons-calendar', $event_post_type->menu_icon, 'Event CPT should have calendar icon' );
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
		$this->register_main_cpts();

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
		$this->register_main_cpts();

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

	/**
	 * Test that project category taxonomy is registered correctly.
	 */
	public function test_project_category_taxonomy_registered() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Register post types and taxonomies.
		wp_mcp_ai_register_project_management_post_types();
		wp_mcp_ai_register_project_management_taxonomies();

		// Check taxonomy is registered.
		$this->assertTrue( taxonomy_exists( 'mcp_ai_project_category' ) );

		// Get taxonomy object.
		$taxonomy = get_taxonomy( 'mcp_ai_project_category' );

		// Verify taxonomy settings.
		$this->assertEquals( 'Project Categories', $taxonomy->labels->name );
		$this->assertTrue( $taxonomy->hierarchical );
		$this->assertTrue( $taxonomy->show_ui );
		$this->assertTrue( $taxonomy->show_admin_column );

		// Verify default categories exist.
		$health_wellness = get_term_by( 'slug', 'health-wellness', 'mcp_ai_project_category' );
		$this->assertNotFalse( $health_wellness );
		// Core normalizes & to &amp; when terms are stored (WP 6.9+).
		$this->assertEquals( 'Health &amp; Wellness', $health_wellness->name );

		$development = get_term_by( 'slug', 'development', 'mcp_ai_project_category' );
		$this->assertNotFalse( $development );
		$this->assertEquals( 'Development', $development->name );

		$design = get_term_by( 'slug', 'design', 'mcp_ai_project_category' );
		$this->assertNotFalse( $design );
		$this->assertEquals( 'Design', $design->name );
	}

	/**
	 * Test that project can be assigned to category.
	 */
	public function test_project_can_be_assigned_to_category() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Register post types and taxonomies.
		wp_mcp_ai_register_project_management_post_types();
		wp_mcp_ai_register_project_management_taxonomies();

		// Create a project post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_project',
				'post_title'  => 'Wellness Initiative',
				'post_status' => 'publish',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Get health wellness category.
		$health_wellness = get_term_by( 'slug', 'health-wellness', 'mcp_ai_project_category' );
		$this->assertNotFalse( $health_wellness );

		// Assign category to project.
		$result = wp_set_object_terms( $post_id, $health_wellness->term_id, 'mcp_ai_project_category' );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		// Verify category is assigned.
		$terms = wp_get_object_terms( $post_id, 'mcp_ai_project_category' );
		$this->assertIsArray( $terms );
		$this->assertCount( 1, $terms );
		// Core normalizes & to &amp; when terms are stored (WP 6.9+).
		$this->assertEquals( 'Health &amp; Wellness', $terms[0]->name );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test that task category taxonomy is registered correctly.
	 */
	public function test_task_category_taxonomy_registered() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Register post types and taxonomies.
		wp_mcp_ai_register_project_management_post_types();
		wp_mcp_ai_register_project_management_taxonomies();

		// Check taxonomy is registered.
		$this->assertTrue( taxonomy_exists( 'mcp_ai_task_category' ) );

		// Get taxonomy object.
		$taxonomy = get_taxonomy( 'mcp_ai_task_category' );

		// Verify taxonomy settings.
		$this->assertEquals( 'Task Categories', $taxonomy->labels->name );
		$this->assertTrue( $taxonomy->hierarchical );
		$this->assertTrue( $taxonomy->show_ui );
		$this->assertTrue( $taxonomy->show_admin_column );

		// Verify default categories exist.
		$health_wellness = get_term_by( 'slug', 'health-wellness', 'mcp_ai_task_category' );
		$this->assertNotFalse( $health_wellness );
		// Core normalizes & to &amp; when terms are stored (WP 6.9+).
		$this->assertEquals( 'Health &amp; Wellness', $health_wellness->name );

		$development = get_term_by( 'slug', 'development', 'mcp_ai_task_category' );
		$this->assertNotFalse( $development );
		$this->assertEquals( 'Development', $development->name );

		$testing = get_term_by( 'slug', 'testing', 'mcp_ai_task_category' );
		$this->assertNotFalse( $testing );
		$this->assertEquals( 'Testing', $testing->name );
	}

	/**
	 * Test that task can be assigned to category.
	 */
	public function test_task_can_be_assigned_to_category() {
		// Enable project management.
		$settings                              = $this->original_settings;
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Register post types and taxonomies.
		wp_mcp_ai_register_project_management_post_types();
		wp_mcp_ai_register_project_management_taxonomies();

		// Create a task post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_task',
				'post_title'  => 'Review wellness program',
				'post_status' => 'publish',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Get health wellness category.
		$health_wellness = get_term_by( 'slug', 'health-wellness', 'mcp_ai_task_category' );
		$this->assertNotFalse( $health_wellness );

		// Assign category to task.
		$result = wp_set_object_terms( $post_id, $health_wellness->term_id, 'mcp_ai_task_category' );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		// Verify category is assigned.
		$terms = wp_get_object_terms( $post_id, 'mcp_ai_task_category' );
		$this->assertIsArray( $terms );
		$this->assertCount( 1, $terms );
		// Core normalizes & to &amp; when terms are stored (WP 6.9+).
		$this->assertEquals( 'Health &amp; Wellness', $terms[0]->name );

		// Clean up.
		wp_delete_post( $post_id, true );
	}
}
