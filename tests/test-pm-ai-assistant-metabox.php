<?php
/**
 * Tests for the Project Management AI Assistant Metabox.
 *
 * Verifies that the AI assistant metabox is correctly registered
 * and only appears for project management CPTs.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_PM_AI_Assistant_Metabox_Test extends WP_UnitTestCase {

	/**
	 * Test that metabox is only registered for project management CPTs.
	 */
	public function test_metabox_only_for_pm_post_types() {
		// Skip if Pro addon is not available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Enable project management.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load the metabox class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-project-management-ai-assistant-metabox.php';

		// Create metabox instance.
		$metabox = new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();

		global $wp_meta_boxes;

		// Register metaboxes.
		$metabox->register_metabox();

		// Check that metabox is registered for project CPT.
		$this->assertArrayHasKey(
			'mcp_ai_project',
			$wp_meta_boxes,
			'Metabox should be registered for projects'
		);

		$this->assertArrayHasKey(
			'side',
			$wp_meta_boxes['mcp_ai_project'],
			'Metabox should be in side context for projects'
		);

		// Check that metabox is registered for task CPT.
		$this->assertArrayHasKey(
			'mcp_ai_task',
			$wp_meta_boxes,
			'Metabox should be registered for tasks'
		);

		// Check that metabox is registered for event CPT.
		$this->assertArrayHasKey(
			'mcp_ai_event',
			$wp_meta_boxes,
			'Metabox should be registered for events'
		);

		// Check that metabox is NOT registered for regular posts.
		if ( isset( $wp_meta_boxes['post']['side']['high']['wp_mcp_ai_pm_ai_assistant'] ) ) {
			$this->fail( 'Metabox should not be registered for regular posts' );
		}
	}

	/**
	 * Test that metabox can render without errors.
	 */
	public function test_metabox_renders_without_errors() {
		// Skip if Pro addon is not available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Enable project management.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create a test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Create a test task.
		$task_id = $this->factory->post->create(
			array(
				'post_type'    => 'mcp_ai_task',
				'post_title'   => 'Test Task',
				'post_content' => 'Test task description',
				'post_status'  => 'publish',
			)
		);

		// Set task metadata.
		update_post_meta( $task_id, '_task_status', 'todo' );
		update_post_meta( $task_id, '_task_priority', 'medium' );

		// Load the metabox class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-project-management-ai-assistant-metabox.php';

		// Create metabox instance.
		$metabox = new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();

		// Get the post object.
		$post = get_post( $task_id );

		// Start output buffering.
		ob_start();
		$metabox->render( $post );
		$output = ob_get_clean();

		// Verify output contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-pm-assistant-select', $output, 'Output should contain assistant selector' );
		$this->assertStringContainsString( 'Test Assistant', $output, 'Output should contain assistant name' );
		$this->assertStringContainsString( 'wp-mcp-ai-pm-assistant-inline-container', $output, 'Output should contain inline container' );
		$this->assertStringContainsString( 'wp-mcp-ai-pm-assistant-chat-container', $output, 'Output should contain chat container' );

		// Verify inline container starts hidden.
		$this->assertStringContainsString( 'style="display: none;"', $output, 'Inline container should have display: none style on render' );
	}

	/**
	 * Test context data extraction for different CPTs.
	 */
	public function test_context_data_extraction() {
		// Skip if Pro addon is not available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Enable project management.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create test posts of each type.
		$project_id = $this->factory->post->create(
			array(
				'post_type'    => 'mcp_ai_project',
				'post_title'   => 'Test Project',
				'post_content' => 'Project description',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $project_id, '_project_status', 'active' );
		update_post_meta( $project_id, '_project_budget', '10000' );

		$task_id = $this->factory->post->create(
			array(
				'post_type'    => 'mcp_ai_task',
				'post_title'   => 'Test Task',
				'post_content' => 'Task description',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $task_id, '_task_status', 'in-progress' );
		update_post_meta( $task_id, '_task_priority', 'high' );
		update_post_meta( $task_id, '_task_project_id', $project_id );
		update_post_meta( $task_id, '_task_due_date', '2025-01-15' );

		$event_id = $this->factory->post->create(
			array(
				'post_type'    => 'mcp_ai_event',
				'post_title'   => 'Test Event',
				'post_content' => 'Event description',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $event_id, '_event_start_date', '2025-01-10' );
		update_post_meta( $event_id, '_event_end_date', '2025-01-11' );
		update_post_meta( $event_id, '_event_location', 'Test Location' );

		// Load the metabox class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-project-management-ai-assistant-metabox.php';

		// Use reflection to access private methods.
		$metabox    = new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();
		$reflection = new ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'get_context_data' );
		$method->setAccessible( true );

		// Test project context.
		$project      = get_post( $project_id );
		$project_data = $method->invoke( $metabox, $project );
		$this->assertEquals( 'Test Project', $project_data['title'], 'Project title should be extracted' );
		$this->assertEquals( 'active', $project_data['project_status'], 'Project status should be extracted' );
		$this->assertEquals( '10000', $project_data['budget'], 'Project budget should be extracted' );

		// Test task context.
		$task      = get_post( $task_id );
		$task_data = $method->invoke( $metabox, $task );
		$this->assertEquals( 'Test Task', $task_data['title'], 'Task title should be extracted' );
		$this->assertEquals( 'in-progress', $task_data['task_status'], 'Task status should be extracted' );
		$this->assertEquals( 'high', $task_data['task_priority'], 'Task priority should be extracted' );
		$this->assertEquals( $project_id, $task_data['project_id'], 'Task project ID should be extracted' );
		$this->assertEquals( '2025-01-15', $task_data['due_date'], 'Task due date should be extracted' );

		// Test event context.
		$event      = get_post( $event_id );
		$event_data = $method->invoke( $metabox, $event );
		$this->assertEquals( 'Test Event', $event_data['title'], 'Event title should be extracted' );
		$this->assertEquals( '2025-01-10', $event_data['start_date'], 'Event start date should be extracted' );
		$this->assertEquals( '2025-01-11', $event_data['end_date'], 'Event end date should be extracted' );
		$this->assertEquals( 'Test Location', $event_data['location'], 'Event location should be extracted' );
	}

	/**
	 * Test AJAX handler security checks.
	 * 
	 * Note: AJAX handler was removed in favor of inline rendering.
	 * This test is kept for reference but marked as skipped.
	 */
	public function test_ajax_handler_security() {
		$this->markTestSkipped( 'AJAX handler removed - inline rendering used instead' );
	}

	/**
	 * Test AJAX handler returns config and instance ID.
	 * 
	 * Note: AJAX handler was removed in favor of inline rendering.
	 * This test is kept for reference but marked as skipped.
	 */
	public function test_ajax_handler_returns_config() {
		$this->markTestSkipped( 'AJAX handler removed - inline rendering used instead' );
	}

	/**
	 * Test that PM assistant script includes wp-dom-ready dependency when available.
	 *
	 * This ensures block editor compatibility by including the wp-dom-ready dependency
	 * which is used to properly initialize the PM assistant in the block editor context.
	 */
	public function test_script_includes_wp_dom_ready_dependency() {
		// Skip if Pro addon is not available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Enable project management.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Create a test task.
		$task_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_task',
				'post_title'  => 'Test Task',
				'post_status' => 'publish',
			)
		);

		// Set global post to the task.
		global $post;
		$post = get_post( $task_id );

		// Register wp-dom-ready script (simulating WordPress core).
		wp_register_script( 'wp-dom-ready', 'https://example.com/wp-dom-ready.js', array(), '1.0', true );

		// Load the metabox class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-project-management-ai-assistant-metabox.php';

		// Create metabox instance.
		$metabox = new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();

		// Simulate the enqueue_assets method.
		$metabox->enqueue_assets( 'post.php' );

		// Get script dependencies.
		$script_handle = 'wp-mcp-ai-pm-ai-assistant';
		$this->assertTrue( wp_script_is( $script_handle, 'enqueued' ), 'PM assistant script should be enqueued' );

		// Get the script object to check dependencies.
		global $wp_scripts;
		$script = $wp_scripts->registered[ $script_handle ];

		// Verify wp-dom-ready is in dependencies when available.
		$this->assertContains( 'wp-dom-ready', $script->deps, 'Script should include wp-dom-ready dependency for block editor support' );
		$this->assertContains( 'jquery', $script->deps, 'Script should include jquery dependency' );
		$this->assertContains( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, $script->deps, 'Script should include chat bundle dependency' );

		// Verify script is loaded in footer for proper initialization.
		$this->assertTrue( $script->extra['group'] === 1, 'Script should be loaded in footer' );
	}
}
