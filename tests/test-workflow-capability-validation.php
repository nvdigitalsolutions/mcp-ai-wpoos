<?php
/**
 * Test Workflow Capability Validation
 *
 * PHPUnit tests for workflow capability validation that prevents 403 errors.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test workflow capability validation
 */
class Test_Workflow_Capability_Validation extends WP_UnitTestCase {

	/**
	 * Command instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Workflow
	 */
	private $command;

	/**
	 * Admin user ID
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load command class.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-workflow.php';

		$this->command = new WP_MCP_AI_Slash_Command_Workflow();

		// Create test users.
		$this->admin_id  = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		$this->editor_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
	}

	/**
	 * Test that site-health workflow requires manage_options capability
	 */
	public function test_site_health_workflow_requires_manage_options() {
		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array( 'site-health' ),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		// Should return a WP_Error for insufficient permissions.
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_workflow_permissions', $result->get_error_code() );
		$this->assertStringContainsString( 'manage_options', $result->get_error_message() );
		$this->assertStringContainsString( 'optimize-perf', $result->get_error_message() );
	}

	/**
	 * Test that admin can execute site-health workflow
	 */
	public function test_admin_can_execute_site_health_workflow() {
		wp_set_current_user( $this->admin_id );

		$result = $this->command->execute(
			array( 'site-health' ),
			array(),
			array( 'user_id' => $this->admin_id )
		);

		// Should NOT be an error for admin.
		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test that daily-review workflow does not require manage_options
	 */
	public function test_daily_review_workflow_allows_editor() {
		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array( 'daily-review' ),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		// Should NOT be an error - daily-review only requires edit_posts.
		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test that publish-ready workflow requires publish_posts capability
	 */
	public function test_publish_ready_workflow_requires_publish_posts() {
		// Create a contributor (has edit_posts but not publish_posts).
		$contributor_id = $this->factory->user->create(
			array(
				'role' => 'contributor',
			)
		);
		wp_set_current_user( $contributor_id );

		$result = $this->command->execute(
			array( 'publish-ready' ),
			array(),
			array( 'user_id' => $contributor_id )
		);

		// Should return a WP_Error for insufficient permissions.
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_workflow_permissions', $result->get_error_code() );
		$this->assertStringContainsString( 'publish_posts', $result->get_error_message() );
		$this->assertStringContainsString( 'ship', $result->get_error_message() );
	}

	/**
	 * Test capability validation with reflection to access private method
	 */
	public function test_capability_validation_method() {
		$workflow = array(
			'name'  => 'Test Workflow',
			'steps' => array(
				array(
					'task'   => 'optimize-perf',
					'params' => array(),
				),
			),
		);

		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'validate_workflow_capabilities' );
		$method->setAccessible( true );

		// Editor should fail - optimize-perf requires manage_options.
		$result = $method->invoke( $this->command, $workflow, $this->editor_id );
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_workflow_permissions', $result->get_error_code() );

		// Admin should succeed.
		$result = $method->invoke( $this->command, $workflow, $this->admin_id );
		$this->assertTrue( $result );
	}

	/**
	 * Test get_task_required_capability method
	 */
	public function test_get_task_required_capability() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'get_task_required_capability' );
		$method->setAccessible( true );

		// Test known capabilities.
		$this->assertEquals( 'manage_options', $method->invoke( $this->command, 'optimize-perf' ) );
		$this->assertEquals( 'manage_options', $method->invoke( $this->command, 'check_performance' ) );
		$this->assertEquals( 'publish_posts', $method->invoke( $this->command, 'ship' ) );
		$this->assertEquals( 'publish_posts', $method->invoke( $this->command, 'publish_post' ) );
		$this->assertEquals( 'edit_posts', $method->invoke( $this->command, 'next-task' ) );
		$this->assertEquals( 'edit_posts', $method->invoke( $this->command, 'clean-content' ) );
		$this->assertEquals( 'edit_posts', $method->invoke( $this->command, 'sync-docs' ) );
		$this->assertNull( $method->invoke( $this->command, 'wait' ) );
		$this->assertNull( $method->invoke( $this->command, 'sleep' ) );
	}

	/**
	 * Test error message provides helpful information
	 */
	public function test_error_message_is_helpful() {
		wp_set_current_user( $this->editor_id );

		$result = $this->command->execute(
			array( 'site-health' ),
			array(),
			array( 'user_id' => $this->editor_id )
		);

		$this->assertWPError( $result );
		$error_message = $result->get_error_message();

		// Should mention the task and capability.
		$this->assertStringContainsString( 'optimize-perf', $error_message );
		$this->assertStringContainsString( 'manage_options', $error_message );
		$this->assertStringContainsString( 'higher privileges', $error_message );
	}
}
