<?php
/**
 * Test Workflow Step Dependencies (DAG)
 *
 * PHPUnit tests for workflow dependency resolution functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test workflow step dependencies
 */
class Test_Slash_Command_Workflow_Dependencies extends WP_UnitTestCase {

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
	 * Test dependency workflow template exists
	 */
	public function test_dependency_workflow_template_exists() {
		$result = $this->command->execute(
			array(),
			array( 'list' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'dependency-workflow', $result );
		$this->assertStringContainsString( 'Complex Workflow with Dependencies', $result );
	}

	/**
	 * Test dependency workflow shows definition correctly
	 */
	public function test_dependency_workflow_shows_definition() {
		$result = $this->command->execute(
			array( 'dependency-workflow' ),
			array( 'show' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Complex Workflow with Dependencies', $result );
		$this->assertStringContainsString( 'Steps:', $result );
	}

	/**
	 * Test dependency workflow executes in dry-run mode
	 */
	public function test_dependency_workflow_dry_run() {
		$result = $this->command->execute(
			array( 'dependency-workflow' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Workflow: dependency-workflow', $result );
		$this->assertStringContainsString( 'Step Results', $result );
	}

	/**
	 * Test topological sort with simple dependencies
	 */
	public function test_resolve_step_dependencies_simple() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'resolve_step_dependencies' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'name'       => 'step_b',
				'task'       => 'wait',
				'depends_on' => array( 'step_a' ),
			),
			array(
				'name' => 'step_a',
				'task' => 'wait',
			),
		);

		$result = $method->invoke( $this->command, $steps );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		// step_a should come before step_b.
		$this->assertEquals( 'step_a', $result[0]['name'] );
		$this->assertEquals( 'step_b', $result[1]['name'] );
	}

	/**
	 * Test topological sort with multiple dependencies
	 */
	public function test_resolve_step_dependencies_complex() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'resolve_step_dependencies' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'name'       => 'step_d',
				'task'       => 'wait',
				'depends_on' => array( 'step_b', 'step_c' ),
			),
			array(
				'name'       => 'step_c',
				'task'       => 'wait',
				'depends_on' => array( 'step_a' ),
			),
			array(
				'name'       => 'step_b',
				'task'       => 'wait',
				'depends_on' => array( 'step_a' ),
			),
			array(
				'name' => 'step_a',
				'task' => 'wait',
			),
		);

		$result = $method->invoke( $this->command, $steps );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertCount( 4, $result );

		// step_a should be first.
		$this->assertEquals( 'step_a', $result[0]['name'] );

		// step_d should be last (depends on b and c).
		$this->assertEquals( 'step_d', $result[3]['name'] );
	}

	/**
	 * Test circular dependency detection
	 */
	public function test_resolve_step_dependencies_circular() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'resolve_step_dependencies' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'name'       => 'step_a',
				'task'       => 'wait',
				'depends_on' => array( 'step_b' ),
			),
			array(
				'name'       => 'step_b',
				'task'       => 'wait',
				'depends_on' => array( 'step_a' ),
			),
		);

		$result = $method->invoke( $this->command, $steps );

		$this->assertWPError( $result );
		$this->assertEquals( 'workflow_circular_dependency', $result->get_error_code() );
	}

	/**
	 * Test steps without names error
	 */
	public function test_resolve_step_dependencies_missing_name() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'resolve_step_dependencies' );
		$method->setAccessible( true );

		$steps = array(
			array(
				'task'       => 'wait',
				'depends_on' => array( 'step_a' ),
			),
		);

		$result = $method->invoke( $this->command, $steps );

		$this->assertWPError( $result );
		$this->assertEquals( 'workflow_dag_error', $result->get_error_code() );
	}

	/**
	 * Test has_step_dependencies detection
	 */
	public function test_has_step_dependencies() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'has_step_dependencies' );
		$method->setAccessible( true );

		// Steps with dependencies.
		$steps_with = array(
			array(
				'name'       => 'step_b',
				'task'       => 'wait',
				'depends_on' => array( 'step_a' ),
			),
		);

		$this->assertTrue( $method->invoke( $this->command, $steps_with ) );

		// Steps without dependencies.
		$steps_without = array(
			array(
				'name' => 'step_a',
				'task' => 'wait',
			),
		);

		$this->assertFalse( $method->invoke( $this->command, $steps_without ) );
	}

	/**
	 * Test step name is stored in results
	 */
	public function test_step_name_in_results() {
		$result = $this->command->execute(
			array( 'dependency-workflow' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'analyze', $result );
	}

	/**
	 * Test context is updated with step name
	 */
	public function test_context_updated_with_step_name() {
		// This tests that named steps add their results to context.
		// We can't fully test this without executing real steps,
		// but we verify the structure is correct.
		$result = $this->command->execute(
			array( 'dependency-workflow' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// In dry-run mode, steps should be skipped.
		$this->assertStringContainsString( 'skipped', $result );
	}

	/**
	 * Test parallel dependencies (diamond pattern)
	 */
	public function test_resolve_step_dependencies_diamond() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'resolve_step_dependencies' );
		$method->setAccessible( true );

		// Diamond pattern: A -> B, A -> C, B -> D, C -> D.
		$steps = array(
			array(
				'name' => 'a',
				'task' => 'wait',
			),
			array(
				'name'       => 'b',
				'task'       => 'wait',
				'depends_on' => array( 'a' ),
			),
			array(
				'name'       => 'c',
				'task'       => 'wait',
				'depends_on' => array( 'a' ),
			),
			array(
				'name'       => 'd',
				'task'       => 'wait',
				'depends_on' => array( 'b', 'c' ),
			),
		);

		$result = $method->invoke( $this->command, $steps );

		$this->assertNotWPError( $result );
		$this->assertCount( 4, $result );

		// a should be first.
		$this->assertEquals( 'a', $result[0]['name'] );

		// d should be last.
		$this->assertEquals( 'd', $result[3]['name'] );

		// b and c can be in any order (both at positions 1 or 2).
		$middle_names = array( $result[1]['name'], $result[2]['name'] );
		$this->assertContains( 'b', $middle_names );
		$this->assertContains( 'c', $middle_names );
	}
}
