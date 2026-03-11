<?php
/**
 * Tests for PM Task Dependencies (add, remove, get).
 *
 * Verifies that:
 * - add_task_dependency creates the expected meta on both tasks.
 * - Cycle detection prevents circular dependency graphs.
 * - remove_task_dependency cleans up meta on both tasks.
 * - get_task_dependencies returns correct enriched data.
 * - can_start reflects whether upstream tasks are completed.
 *
 * @package WP_MCP_AI
 */

/**
 * Task dependency integration tests.
 */
class WP_MCP_AI_Task_Dependencies_Test extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Shared tool instances.
	 *
	 * @var WP_MCP_AI_Tool_Add_Task_Dependency
	 */
	private $add_tool;

	/**
	 * @var WP_MCP_AI_Tool_Remove_Task_Dependency
	 */
	private $remove_tool;

	/**
	 * @var WP_MCP_AI_Tool_Get_Task_Dependencies
	 */
	private $get_tool;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$pro_tools_dir = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/tools/'
			: dirname( __DIR__, 2 ) . '/addons/pro/includes/tools/';

		$files = array(
			$pro_tools_dir . 'class-wp-mcp-ai-tool-add-task-dependency.php',
			$pro_tools_dir . 'class-wp-mcp-ai-tool-remove-task-dependency.php',
			$pro_tools_dir . 'class-wp-mcp-ai-tool-get-task-dependencies.php',
		);

		foreach ( $files as $file ) {
			if ( ! file_exists( $file ) ) {
				$this->markTestSkipped( 'Task dependency tool files not found.' );
				return;
			}
			require_once $file;
		}

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		// Enable project management.
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_project_management'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Register the mcp_ai_task CPT if not already registered.
		if ( ! post_type_exists( 'mcp_ai_task' ) ) {
			register_post_type( 'mcp_ai_task', array( 'public' => false ) );
		}

		$this->add_tool    = new WP_MCP_AI_Tool_Add_Task_Dependency();
		$this->remove_tool = new WP_MCP_AI_Tool_Remove_Task_Dependency();
		$this->get_tool    = new WP_MCP_AI_Tool_Get_Task_Dependencies();
	}

	/**
	 * Tear down: reset settings.
	 */
	public function tearDown(): void {
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_project_management'] = false;
		update_option( 'wp_mcp_ai_settings', $settings );

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Helper: create a task post with optional meta.
	 *
	 * @param string $title  Task title.
	 * @param array  $meta   Optional meta values.
	 * @return int Task post ID.
	 */
	private function create_task( $title, $meta = array() ) {
		$task_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_task',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		$defaults = array( '_task_status' => 'todo', '_task_priority' => 'medium' );
		$meta     = array_merge( $defaults, $meta );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $task_id, $key, $value );
		}

		return $task_id;
	}

	/**
	 * Context array for tool execution.
	 *
	 * @return array
	 */
	private function context() {
		return array( 'user_id' => $this->admin_user_id );
	}

	// ── add_task_dependency ──────────────────────────────────────────────────

	/**
	 * Adding a dependency sets depends_on on the blocked task.
	 */
	public function test_add_dependency_sets_depends_on_meta() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );

		$result = $this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$depends_on = get_post_meta( $task_b, '_task_depends_on', true );
		$this->assertIsArray( $depends_on );
		$this->assertContains( $task_a, array_map( 'intval', $depends_on ) );
	}

	/**
	 * Adding a dependency sets blocks on the blocking task.
	 */
	public function test_add_dependency_sets_blocks_meta() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );

		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$blocks = get_post_meta( $task_a, '_task_blocks', true );
		$this->assertIsArray( $blocks );
		$this->assertContains( $task_b, array_map( 'intval', $blocks ) );
	}

	/**
	 * A task cannot depend on itself.
	 */
	public function test_self_dependency_is_rejected() {
		$task = $this->create_task( 'Self Task' );

		$result = $this->add_tool->execute(
			array( 'blocking_task_id' => $task, 'blocked_task_id' => $task ),
			$this->context()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_self_dependency', $result->get_error_code() );
	}

	/**
	 * Circular dependency (A → B → A) is rejected.
	 */
	public function test_circular_dependency_is_rejected() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );

		// A blocks B.
		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		// Attempt B blocks A (would create a cycle).
		$result = $this->add_tool->execute(
			array( 'blocking_task_id' => $task_b, 'blocked_task_id' => $task_a ),
			$this->context()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_dependency_cycle', $result->get_error_code() );
	}

	/**
	 * Deep cycle (A → B → C → A) is rejected.
	 */
	public function test_deep_circular_dependency_is_rejected() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );
		$task_c = $this->create_task( 'Task C' );

		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);
		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_b, 'blocked_task_id' => $task_c ),
			$this->context()
		);

		// Attempt C blocks A (closes the loop).
		$result = $this->add_tool->execute(
			array( 'blocking_task_id' => $task_c, 'blocked_task_id' => $task_a ),
			$this->context()
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_dependency_cycle', $result->get_error_code() );
	}

	/**
	 * Adding the same dependency twice is idempotent (already_existed flag is set).
	 */
	public function test_duplicate_dependency_is_idempotent() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );

		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$result = $this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['already_existed'] );

		// Verify only one entry in the meta.
		$depends_on = get_post_meta( $task_b, '_task_depends_on', true );
		$this->assertCount( 1, $depends_on );
	}

	// ── remove_task_dependency ───────────────────────────────────────────────

	/**
	 * Removing a dependency clears depends_on on the blocked task.
	 */
	public function test_remove_dependency_clears_depends_on_meta() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );

		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$result = $this->remove_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['was_removed'] );

		$depends_on = get_post_meta( $task_b, '_task_depends_on', true );
		$this->assertEmpty( $depends_on );
	}

	/**
	 * Removing a dependency clears blocks on the blocking task.
	 */
	public function test_remove_dependency_clears_blocks_meta() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );

		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$this->remove_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$blocks = get_post_meta( $task_a, '_task_blocks', true );
		$this->assertEmpty( $blocks );
	}

	/**
	 * Removing a non-existent dependency returns was_removed = false.
	 */
	public function test_remove_nonexistent_dependency() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );

		$result = $this->remove_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['was_removed'] );
	}

	// ── get_task_dependencies ────────────────────────────────────────────────

	/**
	 * get_task_dependencies returns depends_on and blocks lists.
	 */
	public function test_get_dependencies_returns_full_graph() {
		$task_a = $this->create_task( 'Task A' );
		$task_b = $this->create_task( 'Task B' );
		$task_c = $this->create_task( 'Task C' );

		// A blocks B, A blocks C.
		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);
		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_c ),
			$this->context()
		);

		$result = $this->get_tool->execute(
			array( 'task_id' => $task_a ),
			$this->context()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['blocks'] );
		$this->assertEmpty( $result['depends_on'] );
	}

	/**
	 * can_start is false when upstream tasks are not completed.
	 */
	public function test_can_start_is_false_when_blocker_not_done() {
		$task_a = $this->create_task( 'Task A', array( '_task_status' => 'todo' ) );
		$task_b = $this->create_task( 'Task B' );

		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$result = $this->get_tool->execute(
			array( 'task_id' => $task_b ),
			$this->context()
		);

		$this->assertFalse( $result['can_start'], 'can_start should be false when blocker is not completed.' );
	}

	/**
	 * can_start is true when all upstream tasks are completed.
	 */
	public function test_can_start_is_true_when_all_blockers_done() {
		$task_a = $this->create_task( 'Task A', array( '_task_status' => 'completed' ) );
		$task_b = $this->create_task( 'Task B' );

		$this->add_tool->execute(
			array( 'blocking_task_id' => $task_a, 'blocked_task_id' => $task_b ),
			$this->context()
		);

		$result = $this->get_tool->execute(
			array( 'task_id' => $task_b ),
			$this->context()
		);

		$this->assertTrue( $result['can_start'], 'can_start should be true when all blockers are completed.' );
	}

	/**
	 * Requesting dependencies for an invalid task ID returns WP_Error.
	 */
	public function test_get_dependencies_for_invalid_task() {
		$result = $this->get_tool->execute(
			array( 'task_id' => 999999 ),
			$this->context()
		);

		$this->assertWPError( $result );
	}
}
