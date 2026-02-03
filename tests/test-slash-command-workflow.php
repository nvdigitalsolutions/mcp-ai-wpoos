<?php
/**
 * Test Workflow Slash Command
 *
 * PHPUnit tests for /workflow command functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test workflow command functionality
 */
class Test_Slash_Command_Workflow extends WP_UnitTestCase {

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
	 * Test command requires edit_posts capability
	 */
	public function test_command_requires_capability() {
		// Create user without edit_posts capability.
		$subscriber_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber_id );

		$result = $this->command->execute(
			array(),
			array(),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * Test command executes with valid user
	 */
	public function test_command_executes_for_valid_user() {
		$result = $this->command->execute(
			array(),
			array( 'list' => true ),
			array( 'user_id' => $this->user_id )
		);

		// Should not be an error.
		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test listing workflows
	 */
	public function test_list_workflows() {
		$result = $this->command->execute(
			array(),
			array( 'list' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Available Workflows', $result );
		// Should contain built-in templates.
		$this->assertStringContainsString( 'daily-review', $result );
	}

	/**
	 * Test showing workflow definition
	 */
	public function test_show_workflow_definition() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'show' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Workflow:', $result );
		$this->assertStringContainsString( 'Steps:', $result );
	}

	/**
	 * Test executing workflow in dry-run mode
	 */
	public function test_execute_workflow_dry_run() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Workflow:', $result );
		$this->assertStringContainsString( 'dry run', $result );
	}

	/**
	 * Test workflow not found error
	 */
	public function test_workflow_not_found() {
		$result = $this->command->execute(
			array( 'non-existent-workflow' ),
			array(),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'workflow_not_found', $result->get_error_code() );
	}

	/**
	 * Test missing workflow name error
	 */
	public function test_missing_workflow_name() {
		$result = $this->command->execute(
			array(),
			array(),
			array( 'user_id' => $this->user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_workflow_name', $result->get_error_code() );
	}

	/**
	 * Test built-in workflow templates exist
	 */
	public function test_builtin_templates_exist() {
		$result = $this->command->execute(
			array(),
			array( 'list' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Check for all 3 built-in templates.
		$this->assertStringContainsString( 'daily-review', $result );
		$this->assertStringContainsString( 'publish-ready', $result );
		$this->assertStringContainsString( 'site-health', $result );
	}

	/**
	 * Test workflow execution completes steps
	 */
	public function test_workflow_execution_completes() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Step Results', $result );
		$this->assertStringContainsString( 'Step 1', $result );
	}

	/**
	 * Test workflow summary information
	 */
	public function test_workflow_summary() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Summary:', $result );
		$this->assertStringContainsString( 'Total steps:', $result );
		$this->assertStringContainsString( 'Completed:', $result );
	}

	/**
	 * Test action flag
	 */
	public function test_action_flag() {
		$result = $this->command->execute(
			array(),
			array( 'action' => 'list' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Available Workflows', $result );
	}

	/**
	 * Test output format
	 */
	public function test_output_format() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		// Should be markdown formatted.
		$this->assertStringContainsString( '##', $result );
		$this->assertStringContainsString( '**', $result );
	}

	/**
	 * Test step status indicators
	 */
	public function test_step_status_indicators() {
		$result = $this->command->execute(
			array( 'daily-review' ),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		// Should contain emoji indicators.
		$this->assertMatchesRegularExpression( '/[✅❌⏭️]/', $result );
	}

	/**
	 * Cleanup after tests
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
