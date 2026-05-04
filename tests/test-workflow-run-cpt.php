<?php
/**
 * Tests for WP_MCP_AI_Workflow_Run_CPT.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 */

/**
 * Workflow Run CPT unit tests.
 *
 * @covers WP_MCP_AI_Workflow_Run_CPT
 */
class Test_Workflow_Run_CPT extends WP_UnitTestCase {

	/** @var int Admin user ID. */
	private $admin_id;

	/** @var int Dummy workflow post ID. */
	private $workflow_id;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-run-cpt.php';
		WP_MCP_AI_Workflow_Run_CPT::register_cpt();
		WP_MCP_AI_Workflow_Run_CPT::register_meta();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a minimal workflow post to reference.
		$this->workflow_id = wp_insert_post(
			array(
				'post_title'  => 'Test Workflow',
				'post_status' => 'publish',
				'post_type'   => 'post', // Plain post is fine as a dummy reference.
			)
		);
	}

	// ── CPT registration ──────────────────────────────────────────────────────

	/**
	 * The CPT should be registered after register_cpt() is called.
	 */
	public function test_cpt_is_registered() {
		$this->assertTrue( post_type_exists( WP_MCP_AI_Workflow_Run_CPT::CPT ) );
	}

	/**
	 * The CPT slug constant should equal 'mcp_ai_workflow_run'.
	 */
	public function test_cpt_slug_is_mcp_ai_workflow_run() {
		$this->assertSame( 'mcp_ai_workflow_run', WP_MCP_AI_Workflow_Run_CPT::CPT );
	}

	// ── create_run ────────────────────────────────────────────────────────────

	/**
	 * create_run() should return an integer post ID.
	 */
	public function test_create_run_returns_integer() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run(
			$this->workflow_id,
			array( 'foo' => 'bar' ),
			array(),
			array()
		);

		$this->assertIsInt( $run_id );
		$this->assertGreaterThan( 0, $run_id );
	}

	/**
	 * A freshly created run should have status 'pending'.
	 */
	public function test_create_run_initial_status_is_pending() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id );
		$run    = WP_MCP_AI_Workflow_Run_CPT::get_run( $run_id );

		$this->assertSame( 'pending', $run['status'] );
	}

	/**
	 * get_run() round-trip preserves workflow_id and input.
	 */
	public function test_create_run_roundtrip() {
		$input  = array( 'key' => 'value', 'num' => 42 );
		$budget = array( 'max_steps' => 10 );
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id, $input, $budget );
		$run    = WP_MCP_AI_Workflow_Run_CPT::get_run( $run_id );

		$this->assertIsArray( $run );
		$this->assertSame( $this->workflow_id, $run['workflow_id'] );
		$this->assertSame( $input, $run['input'] );
		$this->assertSame( 10, $run['budget']['max_steps'] );
		$this->assertSame( $run_id, $run['id'] );
	}

	// ── append_event / get_event_log ──────────────────────────────────────────

	/**
	 * A new run should have an empty event log.
	 */
	public function test_event_log_is_empty_on_create() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id );
		$log    = WP_MCP_AI_Workflow_Run_CPT::get_event_log( $run_id );

		$this->assertIsArray( $log );
		$this->assertEmpty( $log );
	}

	/**
	 * append_event() should add an entry and auto-increment seq.
	 */
	public function test_append_event_adds_entry() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id );

		$result = WP_MCP_AI_Workflow_Run_CPT::append_event(
			$run_id,
			'step_started',
			'node-001',
			'tool',
			array( 'tool_slug' => 'get_post' )
		);

		$this->assertTrue( $result );

		$log = WP_MCP_AI_Workflow_Run_CPT::get_event_log( $run_id );

		$this->assertCount( 1, $log );
		$this->assertSame( 'step_started', $log[0]['type'] );
		$this->assertSame( 'node-001', $log[0]['node_id'] );
		$this->assertSame( 'tool', $log[0]['node_type'] );
		$this->assertSame( 1, $log[0]['seq'] );
	}

	/**
	 * Appending multiple events should increment seq correctly.
	 */
	public function test_append_event_increments_seq() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id );

		WP_MCP_AI_Workflow_Run_CPT::append_event( $run_id, 'step_started', 'n1', 'tool' );
		WP_MCP_AI_Workflow_Run_CPT::append_event( $run_id, 'step_finished', 'n1', 'tool' );
		WP_MCP_AI_Workflow_Run_CPT::append_event( $run_id, 'checkpoint', 'n2', 'condition' );

		$log = WP_MCP_AI_Workflow_Run_CPT::get_event_log( $run_id );

		$this->assertCount( 3, $log );
		$this->assertSame( 1, $log[0]['seq'] );
		$this->assertSame( 2, $log[1]['seq'] );
		$this->assertSame( 3, $log[2]['seq'] );
	}

	/**
	 * append_event() should return false for a non-existent run.
	 */
	public function test_append_event_returns_false_for_nonexistent_run() {
		$result = WP_MCP_AI_Workflow_Run_CPT::append_event( 999999, 'step_started', 'n', 'tool' );
		$this->assertFalse( $result );
	}

	// ── set_status ────────────────────────────────────────────────────────────

	/**
	 * set_status() should transition to a valid status.
	 */
	public function test_set_status_transitions() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id );

		$result = WP_MCP_AI_Workflow_Run_CPT::set_status( $run_id, 'running' );
		$this->assertTrue( $result );

		$run = WP_MCP_AI_Workflow_Run_CPT::get_run( $run_id );
		$this->assertSame( 'running', $run['status'] );
	}

	/**
	 * Terminal status should set finished_at.
	 */
	public function test_set_status_completed_sets_finished_at() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id );

		WP_MCP_AI_Workflow_Run_CPT::set_status( $run_id, 'completed' );

		$run = WP_MCP_AI_Workflow_Run_CPT::get_run( $run_id );
		$this->assertGreaterThan( 0, $run['finished_at'] );
	}

	/**
	 * set_status() should return false for an invalid status string.
	 */
	public function test_set_status_rejects_invalid_status() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id );
		$result = WP_MCP_AI_Workflow_Run_CPT::set_status( $run_id, 'not_a_valid_status' );

		$this->assertFalse( $result );
	}

	/**
	 * set_status() should return false for a non-existent post.
	 */
	public function test_set_status_returns_false_for_nonexistent_run() {
		$result = WP_MCP_AI_Workflow_Run_CPT::set_status( 999999, 'completed' );
		$this->assertFalse( $result );
	}

	// ── check_budget ──────────────────────────────────────────────────────────

	/**
	 * check_budget() returns true when budget is not set.
	 */
	public function test_check_budget_returns_true_when_no_budget() {
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id, array(), array() );
		$this->assertTrue( WP_MCP_AI_Workflow_Run_CPT::check_budget( $run_id ) );
	}

	/**
	 * check_budget() returns true when under limits.
	 */
	public function test_check_budget_returns_true_under_limits() {
		$budget = array(
			'max_cost_usd'      => 10.0,
			'max_tokens'        => 100000,
			'max_wall_seconds'  => 3600,
			'max_steps'         => 50,
		);
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id, array(), $budget );

		$this->assertTrue( WP_MCP_AI_Workflow_Run_CPT::check_budget( $run_id ) );
	}

	/**
	 * check_budget() fires action and returns false when step limit exceeded.
	 */
	public function test_check_budget_fires_action_when_exceeded() {
		$action_fired = false;
		add_action(
			'wp_mcp_ai_workflow_run_budget_exceeded',
			function ( $run_id, $violations ) use ( &$action_fired ) {
				$action_fired = true;
			},
			10,
			2
		);

		// Budget: max 1 step.
		$budget = array( 'max_steps' => 1 );
		$run_id = WP_MCP_AI_Workflow_Run_CPT::create_run( $this->workflow_id, array(), $budget );

		// Append 2 events to exceed the 1-step limit.
		WP_MCP_AI_Workflow_Run_CPT::append_event( $run_id, 'step_started', 'n1', 'tool' );
		WP_MCP_AI_Workflow_Run_CPT::append_event( $run_id, 'step_finished', 'n1', 'tool' );

		$within = WP_MCP_AI_Workflow_Run_CPT::check_budget( $run_id );

		$this->assertFalse( $within );
		$this->assertTrue( $action_fired );
	}

	// ── get_run ───────────────────────────────────────────────────────────────

	/**
	 * get_run() returns false for a non-existent ID.
	 */
	public function test_get_run_returns_false_for_nonexistent_id() {
		$result = WP_MCP_AI_Workflow_Run_CPT::get_run( 999999 );
		$this->assertFalse( $result );
	}

	/**
	 * get_run() returns false for ID 0.
	 */
	public function test_get_run_returns_false_for_zero_id() {
		$result = WP_MCP_AI_Workflow_Run_CPT::get_run( 0 );
		$this->assertFalse( $result );
	}
}
