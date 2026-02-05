<?php
/**
 * Test Next Task Slash Command
 *
 * PHPUnit tests for /next-task command functionality.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test next-task command functionality
 */
class Test_Slash_Command_Next_Task extends WP_UnitTestCase {

	/**
	 * Command instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Next_Task
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
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-next-task.php';

		$this->command = new WP_MCP_AI_Slash_Command_Next_Task();

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
			array(),
			array( 'user_id' => $this->user_id )
		);

		// Should not be an error.
		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test dry-run flag returns plan without execution
	 */
	public function test_dry_run_flag() {
		// Create some draft posts for task discovery.
		$this->factory->post->create_many(
			3,
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);

		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'dry run', $result );
	}

	/**
	 * Test limit flag restricts task count
	 */
	public function test_limit_flag() {
		// Create multiple draft posts.
		$this->factory->post->create_many(
			10,
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'limit'   => '2',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test task type filter
	 */
	public function test_type_filter() {
		// Create draft post.
		$this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);

		// Test with drafts filter.
		$result = $this->command->execute(
			array(),
			array(
				'type'    => 'drafts',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test no tasks scenario
	 */
	public function test_no_tasks_found() {
		// No draft posts, should return success with no tasks.
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'No tasks found', $result );
	}

	/**
	 * Test task discovery finds draft posts
	 */
	public function test_discovers_draft_posts() {
		// Create draft posts.
		$draft_ids = $this->factory->post->create_many(
			3,
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
				'post_title'  => 'Test Draft Post',
			)
		);

		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Discovered Tasks', $result );
	}

	/**
	 * Test task discovery finds posts without meta descriptions
	 */
	public function test_discovers_missing_meta_descriptions() {
		// Create published posts without meta descriptions.
		$post_ids = $this->factory->post->create_many(
			2,
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'type'    => 'seo',
				'dry-run' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
	}

	/**
	 * Test auto flag bypasses approval
	 */
	public function test_auto_flag_bypasses_approval() {
		// Create a draft post.
		$this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);

		$result = $this->command->execute(
			array(),
			array(
				'auto'  => true,
				'limit' => '1',
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Completed', $result );
	}

	/**
	 * Test without auto flag requires approval
	 */
	public function test_without_auto_requires_approval() {
		// Create a draft post.
		$this->factory->post->create(
			array(
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);

		$result = $this->command->execute(
			array(),
			array( 'limit' => '1' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'approval', strtolower( $result ) );
	}

	/**
	 * Test command output format
	 */
	public function test_output_format() {
		$result = $this->command->execute(
			array(),
			array( 'dry-run' => true ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertIsString( $result );
		// Should be markdown formatted.
		$this->assertStringContainsString( '##', $result );
	}

	/**
	 * Cleanup after tests
	 */
	public function tearDown(): void {
		parent::tearDown();
	}
}
