<?php
/**
 * Test Workflow Conditional Execution
 *
 * PHPUnit tests for conditional workflow execution functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test conditional workflow execution
 */
class Test_Slash_Command_Workflow_Conditional extends WP_UnitTestCase {

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

		// Create test user with publish_posts capability.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Test conditional workflow template exists
	 */
	public function test_conditional_publish_template_exists() {
		$result = $this->command->execute(
			array(),
			array( 'list' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'conditional-publish', $result );
		$this->assertStringContainsString( 'Conditional Content Publishing', $result );
	}

	/**
	 * Test conditional workflow shows definition correctly
	 */
	public function test_conditional_workflow_shows_definition() {
		$result = $this->command->execute(
			array( 'conditional-publish' ),
			array( 'show' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Conditional Content Publishing', $result );
		$this->assertStringContainsString( 'Steps:', $result );
	}

	/**
	 * Test conditional workflow executes in dry-run mode
	 */
	public function test_conditional_workflow_dry_run() {
		$result = $this->command->execute(
			array( 'conditional-publish' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Workflow: conditional-publish', $result );
		$this->assertStringContainsString( 'Step Results', $result );
	}

	/**
	 * Test condition evaluation with greater than operator
	 */
	public function test_evaluate_condition_greater_than() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		// Test numeric comparison.
		$context = array( 'count' => 5 );
		$this->assertTrue( $method->invoke( $this->command, '{{count}} > 3', $context ) );
		$this->assertFalse( $method->invoke( $this->command, '{{count}} > 10', $context ) );
	}

	/**
	 * Test condition evaluation with equals operator
	 */
	public function test_evaluate_condition_equals() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		$context = array( 'status' => 'active' );
		$this->assertTrue( $method->invoke( $this->command, '{{status}} == active', $context ) );
		$this->assertFalse( $method->invoke( $this->command, '{{status}} == inactive', $context ) );
	}

	/**
	 * Test condition evaluation with contains operator
	 */
	public function test_evaluate_condition_contains() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		$context = array( 'message' => 'Hello World' );
		$this->assertTrue( $method->invoke( $this->command, '{{message}} contains Hello', $context ) );
		$this->assertFalse( $method->invoke( $this->command, '{{message}} contains Goodbye', $context ) );
	}

	/**
	 * Test condition evaluation with empty operator
	 */
	public function test_evaluate_condition_empty() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		$context = array( 'value' => '' );
		$this->assertTrue( $method->invoke( $this->command, '{{value}} empty', $context ) );

		$context = array( 'value' => 'something' );
		$this->assertFalse( $method->invoke( $this->command, '{{value}} empty', $context ) );
	}

	/**
	 * Test condition evaluation with not_empty operator
	 */
	public function test_evaluate_condition_not_empty() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		$context = array( 'value' => 'something' );
		$this->assertTrue( $method->invoke( $this->command, '{{value}} not_empty', $context ) );

		$context = array( 'value' => '' );
		$this->assertFalse( $method->invoke( $this->command, '{{value}} not_empty', $context ) );
	}

	/**
	 * Test branch execution helper method
	 */
	public function test_execute_branch_steps() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'execute_branch_steps' );
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
			array(), // workflow_context.
			array( 'user_id' => $this->user_id ), // execution_context.
			false, // dry_run.
			'1.then' // branch_prefix.
		);

		// Verify result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'completed', $result );
		$this->assertArrayHasKey( 'failed', $result );
		$this->assertArrayHasKey( 'step_results', $result );
		$this->assertArrayHasKey( 'context', $result );
		$this->assertEquals( 1, $result['completed'] );
	}

	/**
	 * Test conditional execution result structure
	 */
	public function test_conditional_execution_result_structure() {
		$result = $this->command->execute(
			array( 'conditional-publish' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Summary:', $result );
		$this->assertStringContainsString( 'Total steps:', $result );
	}

	/**
	 * Test condition with less than operator
	 */
	public function test_evaluate_condition_less_than() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		$context = array( 'count' => 2 );
		$this->assertTrue( $method->invoke( $this->command, '{{count}} < 5', $context ) );
		$this->assertFalse( $method->invoke( $this->command, '{{count}} < 1', $context ) );
	}

	/**
	 * Test condition with greater than or equals operator
	 */
	public function test_evaluate_condition_greater_than_or_equals() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		$context = array( 'count' => 5 );
		$this->assertTrue( $method->invoke( $this->command, '{{count}} >= 5', $context ) );
		$this->assertTrue( $method->invoke( $this->command, '{{count}} >= 3', $context ) );
		$this->assertFalse( $method->invoke( $this->command, '{{count}} >= 10', $context ) );
	}

	/**
	 * Test condition with not equals operator
	 */
	public function test_evaluate_condition_not_equals() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		$context = array( 'status' => 'active' );
		$this->assertTrue( $method->invoke( $this->command, '{{status}} != inactive', $context ) );
		$this->assertFalse( $method->invoke( $this->command, '{{status}} != active', $context ) );
	}

	/**
	 * Test boolean condition evaluation
	 */
	public function test_evaluate_condition_boolean() {
		$reflection = new ReflectionClass( $this->command );
		$method     = $reflection->getMethod( 'evaluate_condition' );
		$method->setAccessible( true );

		$context = array( 'enabled' => 'true' );
		$this->assertTrue( $method->invoke( $this->command, '{{enabled}}', $context ) );

		$context = array( 'enabled' => 'false' );
		$this->assertFalse( $method->invoke( $this->command, '{{enabled}}', $context ) );

		$context = array( 'enabled' => '' );
		$this->assertFalse( $method->invoke( $this->command, '{{enabled}}', $context ) );
	}
}
