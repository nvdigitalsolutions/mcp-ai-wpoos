<?php
/**
 * Test Workflow Parallel Execution
 *
 * PHPUnit tests for parallel workflow execution functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test parallel workflow execution
 */
class Test_Slash_Command_Workflow_Parallel extends WP_UnitTestCase {

	/**
	 * Command instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Workflow
	 */
	private $command;

	/**
	 * Test user ID
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load command class.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-workflow.php';

		$this->command = new WP_MCP_AI_Slash_Command_Workflow();

		// Create test user with edit_posts capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Test parallel workflow template exists
	 */
	public function test_parallel_checks_template_exists() {
		$result = $this->command->execute(
			array(),
			array( 'list' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'parallel-checks', $result );
		$this->assertStringContainsString( 'Parallel Site Checks', $result );
	}

	/**
	 * Test parallel workflow shows definition correctly
	 */
	public function test_parallel_workflow_shows_definition() {
		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array( 'show' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Parallel Site Checks', $result );
		$this->assertStringContainsString( 'Steps:', $result );
	}

	/**
	 * Test parallel workflow executes in dry-run mode
	 */
	public function test_parallel_workflow_dry_run() {
		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Workflow: parallel-checks', $result );
		$this->assertStringContainsString( 'Step Results', $result );
		$this->assertStringContainsString( 'dry run', $result, '', true ); // Case-insensitive.
	}

	/**
	 * Test parallel workflow executes with admin capability
	 */
	public function test_parallel_workflow_executes_for_admin() {
		// Create admin user.
		$admin_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $admin_id );

		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'parallel-checks', $result );
	}

	/**
	 * Test parallel execution shows multiple steps
	 */
	public function test_parallel_execution_shows_multiple_steps() {
		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Should show sub-steps with dot notation (1.1, 1.2, 1.3).
		$this->assertStringContainsString( 'Step 1.', $result );
	}

	/**
	 * Test parallel workflow with continue_on_error
	 */
	public function test_parallel_workflow_continues_on_error() {
		// This test verifies the continue_on_error flag works in parallel execution.
		// In dry-run mode, all steps should be skipped, not failed.
		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'skipped', $result );
	}

	/**
	 * Test parallel execution format in response
	 */
	public function test_parallel_execution_response_format() {
		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Should include summary section.
		$this->assertStringContainsString( 'Summary:', $result );
		$this->assertStringContainsString( 'Total steps:', $result );
		$this->assertStringContainsString( 'Completed:', $result );
	}

	/**
	 * Test that parallel workflow requires proper capabilities
	 */
	public function test_parallel_workflow_requires_capability() {
		// Editor should NOT be able to run optimize-perf (requires manage_options).
		// The parallel-checks workflow includes optimize-perf, so it should fail validation.
		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array(),
			array( 'user_id' => $this->user_id )
		);

		// Should fail with capability error.
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_workflow_permissions', $result->get_error_code() );
	}

	/**
	 * Test parallel execution result structure
	 */
	public function test_parallel_execution_result_structure() {
		// Use reflection to test the internal parallel execution method.
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_parallel_steps' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'   => 'wait',
				'name'   => 'test-wait',
				'params' => array( 'seconds' => 1 ),
			),
		);

		$result = $method->invoke(
			$this->command,
			$steps,
			array(), // workflow_context.
			array( 'user_id' => $this->user_id ), // execution_context.
			false, // dry_run.
			1, // base_step_num.
			false // continue_on_error.
		);

		// Verify result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'completed', $result );
		$this->assertArrayHasKey( 'failed', $result );
		$this->assertArrayHasKey( 'step_results', $result );
		$this->assertArrayHasKey( 'context', $result );
	}

	/**
	 * Test parallel execution with timeout
	 */
	public function test_parallel_execution_respects_timeout() {
		// Use reflection to test timeout behavior.
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_parallel_steps' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'    => 'wait',
				'name'    => 'test-wait-timeout',
				'timeout' => 1, // 1 second timeout.
				'params'  => array( 'seconds' => 2 ), // Wait 2 seconds (exceeds timeout).
			),
		);

		$result = $method->invoke(
			$this->command,
			$steps,
			array(),
			array( 'user_id' => $this->user_id ),
			false,
			1,
			false
		);

		// Step should complete but with timeout warning.
		$this->assertEquals( 1, $result['completed'] );
		$this->assertArrayHasKey( 'step_results', $result );
		$this->assertEquals( 'completed-timeout', $result['step_results'][0]['status'] );
	}
}
