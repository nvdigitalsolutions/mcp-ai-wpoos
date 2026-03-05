<?php
/**
 * Test Workflow Loop Execution
 *
 * PHPUnit tests for loop workflow execution functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test loop workflow execution
 */
class Test_Slash_Command_Workflow_Loop extends WP_UnitTestCase {

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
	 * Test autonomous audit loop template exists
	 */
	public function test_autonomous_audit_template_exists() {
		$result = $this->command->execute(
			array(),
			array( 'list' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'autonomous-audit', $result );
		$this->assertStringContainsString( 'Autonomous Content Audit Loop', $result );
	}

	/**
	 * Test loop workflow shows definition correctly
	 */
	public function test_loop_workflow_shows_definition() {
		$result = $this->command->execute(
			array( 'autonomous-audit' ),
			array( 'show' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Autonomous Content Audit Loop', $result );
		$this->assertStringContainsString( 'Steps:', $result );
	}

	/**
	 * Test loop workflow executes in dry-run mode
	 */
	public function test_loop_workflow_dry_run() {
		$result = $this->command->execute(
			array( 'autonomous-audit' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Workflow: autonomous-audit', $result );
		$this->assertStringContainsString( 'Step Results', $result );
	}

	/**
	 * Test loop execution helper method
	 */
	public function test_execute_loop_steps() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_loop_steps' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'   => 'wait',
				'params' => array( 'seconds' => 1 ),
			),
		);

		$result = $method->invoke(
			$this->command,
			$steps,
			null, // exit_condition.
			3, // max_iterations.
			array(), // workflow_context.
			array( 'user_id' => $this->user_id ), // execution_context.
			false, // dry_run.
			1 // base_step_num.
		);

		// Verify result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'completed', $result );
		$this->assertArrayHasKey( 'failed', $result );
		$this->assertArrayHasKey( 'step_results', $result );
		$this->assertArrayHasKey( 'context', $result );
		$this->assertArrayHasKey( 'iterations', $result );

		// Should execute 3 iterations.
		$this->assertEquals( 3, $result['iterations'] );
		$this->assertEquals( 3, $result['completed'] );
	}

	/**
	 * Test loop with exit condition
	 */
	public function test_loop_with_exit_condition() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_loop_steps' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'       => 'wait',
				'params'     => array( 'seconds' => 1 ),
				'output_var' => 'counter',
			),
		);

		// Set up context that will meet condition on first iteration.
		$context = array( 'quality_score' => 9 );

		$result = $method->invoke(
			$this->command,
			$steps,
			'{{quality_score}} >= 8', // exit_condition.
			10, // max_iterations.
			$context, // workflow_context.
			array( 'user_id' => $this->user_id ), // execution_context.
			false, // dry_run.
			1 // base_step_num.
		);

		// Should exit after first iteration due to condition.
		$this->assertEquals( 1, $result['iterations'] );

		// Should have exit condition message in results.
		$exit_found = false;
		foreach ( $result['step_results'] as $step_result ) {
			if ( isset( $step_result['task'] ) && 'exit-condition' === $step_result['task'] ) {
				$exit_found = true;
				$this->assertStringContainsString( 'Exit condition met', $step_result['message'] );
			}
		}
		$this->assertTrue( $exit_found, 'Exit condition message not found in results' );
	}

	/**
	 * Test loop reaches max iterations
	 */
	public function test_loop_reaches_max_iterations() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_loop_steps' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'   => 'wait',
				'params' => array( 'seconds' => 1 ),
			),
		);

		// Condition will never be met, so should hit max iterations.
		$result = $method->invoke(
			$this->command,
			$steps,
			'{{quality_score}} >= 100', // exit_condition (won't be met).
			3, // max_iterations.
			array( 'quality_score' => 1 ), // workflow_context.
			array( 'user_id' => $this->user_id ), // execution_context.
			false, // dry_run.
			1 // base_step_num.
		);

		// Should execute all 3 iterations.
		$this->assertEquals( 3, $result['iterations'] );

		// Should have iteration limit message in results.
		$limit_found = false;
		foreach ( $result['step_results'] as $step_result ) {
			if ( isset( $step_result['task'] ) && 'iteration-limit' === $step_result['task'] ) {
				$limit_found = true;
				$this->assertStringContainsString( 'Maximum iterations reached', $step_result['message'] );
			}
		}
		$this->assertTrue( $limit_found, 'Iteration limit message not found in results' );
	}

	/**
	 * Test loop step numbering format
	 */
	public function test_loop_step_numbering() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_loop_steps' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'   => 'wait',
				'params' => array( 'seconds' => 1 ),
			),
		);

		$result = $method->invoke(
			$this->command,
			$steps,
			null, // exit_condition.
			2, // max_iterations.
			array(), // workflow_context.
			array( 'user_id' => $this->user_id ), // execution_context.
			false, // dry_run.
			1 // base_step_num.
		);

		// Check step numbering format (should be 1.loop.1.1, 1.loop.2.1, etc.).
		$step_numbers = array();
		foreach ( $result['step_results'] as $step_result ) {
			if ( isset( $step_result['step'] ) ) {
				$step_numbers[] = $step_result['step'];
			}
		}

		$this->assertContains( '1.loop.1.1', $step_numbers );
		$this->assertContains( '1.loop.2.1', $step_numbers );
	}

	/**
	 * Test loop with dry run mode
	 */
	public function test_loop_dry_run_mode() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_loop_steps' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'   => 'wait',
				'params' => array( 'seconds' => 1 ),
			),
		);

		$result = $method->invoke(
			$this->command,
			$steps,
			null, // exit_condition.
			2, // max_iterations.
			array(), // workflow_context.
			array( 'user_id' => $this->user_id ), // execution_context.
			true, // dry_run.
			1 // base_step_num.
		);

		// In dry run, steps should be skipped.
		foreach ( $result['step_results'] as $step_result ) {
			if ( isset( $step_result['status'] ) ) {
				$this->assertEquals( 'skipped', $step_result['status'] );
			}
		}
	}

	/**
	 * Test loop execution response format
	 */
	public function test_loop_execution_response_format() {
		$result = $this->command->execute(
			array( 'autonomous-audit' ),
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
	 * Test loop context preservation across iterations
	 */
	public function test_loop_context_preservation() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_loop_steps' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'       => 'wait',
				'params'     => array( 'seconds' => 1 ),
				'output_var' => 'iteration_result',
			),
		);

		$initial_context = array( 'initial_value' => 'test' );

		$result = $method->invoke(
			$this->command,
			$steps,
			null, // exit_condition.
			2, // max_iterations.
			$initial_context, // workflow_context.
			array( 'user_id' => $this->user_id ), // execution_context.
			false, // dry_run.
			1 // base_step_num.
		);

		// Context should be preserved and updated.
		$this->assertArrayHasKey( 'initial_value', $result['context'] );
		$this->assertEquals( 'test', $result['context']['initial_value'] );
	}
}
