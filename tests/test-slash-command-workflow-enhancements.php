<?php
/**
 * Test Workflow Enhancements (Metrics & Visualization)
 *
 * PHPUnit tests for performance metrics and workflow visualization features.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test workflow enhancements
 */
class Test_Slash_Command_Workflow_Enhancements extends WP_UnitTestCase {

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

		// Create test user with manage_options capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Test performance metrics are tracked
	 */
	public function test_performance_metrics_tracked() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Performance Metrics:', $result );
		$this->assertStringContainsString( 'Total Duration:', $result );
		$this->assertStringContainsString( 'Steps Executed:', $result );
	}

	/**
	 * Test parallel block metrics are tracked
	 */
	public function test_parallel_metrics_tracked() {
		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Performance Metrics:', $result );
		$this->assertStringContainsString( 'Parallel Blocks:', $result );
	}

	/**
	 * Test loop iteration metrics are tracked
	 */
	public function test_loop_metrics_tracked() {
		$result = $this->command->execute(
			array( 'autonomous-audit' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Performance Metrics:', $result );
		$this->assertStringContainsString( 'Loop Iterations:', $result );
	}

	/**
	 * Test workflow visualization flag is recognized
	 */
	public function test_visualize_flag_recognized() {
		$result = $this->command->execute(
			array( 'dependency-workflow' ),
			array( 'visualize' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Workflow Visualization:', $result );
	}

	/**
	 * Test visualization shows DAG structure
	 */
	public function test_visualization_shows_dag() {
		$result = $this->command->execute(
			array( 'dependency-workflow' ),
			array( 'visualize' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'DAG Structure:', $result );
		$this->assertStringContainsString( 'Layer', $result );
	}

	/**
	 * Test visualization shows sequential structure
	 */
	public function test_visualization_shows_sequential() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'visualize' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Workflow Visualization:', $result );
		$this->assertStringContainsString( '→', $result );
	}

	/**
	 * Test visualization shows parallel blocks
	 */
	public function test_visualization_shows_parallel() {
		$result = $this->command->execute(
			array( 'parallel-checks' ),
			array( 'visualize' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Parallel Block', $result );
		$this->assertStringContainsString( '⇉', $result );
	}

	/**
	 * Test visualization shows conditional blocks
	 */
	public function test_visualization_shows_conditional() {
		$result = $this->command->execute(
			array( 'conditional-publish' ),
			array( 'visualize' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Conditional', $result );
		$this->assertStringContainsString( '⚡', $result );
		$this->assertStringContainsString( 'THEN:', $result );
		$this->assertStringContainsString( 'ELSE:', $result );
	}

	/**
	 * Test visualization shows loop blocks
	 */
	public function test_visualization_shows_loop() {
		$result = $this->command->execute(
			array( 'autonomous-audit' ),
			array( 'visualize' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Loop', $result );
		$this->assertStringContainsString( '↻', $result );
	}

	/**
	 * Test visualization legend is shown
	 */
	public function test_visualization_shows_legend() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'visualize' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Legend:', $result );
		$this->assertStringContainsString( 'Sequential flow', $result );
		$this->assertStringContainsString( 'Parallel execution', $result );
	}

	/**
	 * Test metrics structure in results
	 */
	public function test_metrics_structure() {
		// Use reflection to test internal metrics structure.
		$reflection = new ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'execute_workflow' );
		$method->setAccessible( true );

		$workflow = array(
			'name'  => 'Test Workflow',
			'steps' => array(
				array(
					'task'   => 'wait',
					'params' => array( 'seconds' => 1 ),
				),
			),
		);

		$result = $method->invoke(
			$this->command,
			$workflow,
			false, // dry_run.
			array( 'user_id' => $this->user_id )
		);

		// Verify metrics structure.
		$this->assertArrayHasKey( 'metrics', $result );
		$this->assertArrayHasKey( 'total_duration', $result['metrics'] );
		$this->assertArrayHasKey( 'steps_executed', $result['metrics'] );
		$this->assertArrayHasKey( 'parallel_blocks', $result['metrics'] );
		$this->assertArrayHasKey( 'loop_iterations', $result['metrics'] );
	}

	/**
	 * Test metrics show correct duration
	 */
	public function test_metrics_duration_accurate() {
		$reflection = new ReflectionClass( $this->command );
		$method = $reflection->getMethod( 'execute_workflow' );
		$method->setAccessible( true );

		$workflow = array(
			'name'  => 'Test Workflow',
			'steps' => array(
				array(
					'task'   => 'wait',
					'params' => array( 'seconds' => 1 ),
				),
			),
		);

		$result = $method->invoke(
			$this->command,
			$workflow,
			false,
			array( 'user_id' => $this->user_id )
		);

		// Duration should be at least 1 second.
		$this->assertGreaterThanOrEqual( 1.0, $result['metrics']['total_duration'] );
	}

	/**
	 * Test DAG visualization shows layers
	 */
	public function test_dag_visualization_layers() {
		$result = $this->command->execute(
			array( 'dependency-workflow' ),
			array( 'v' => true ), // Short flag.
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Layer 0:', $result );
	}
}
