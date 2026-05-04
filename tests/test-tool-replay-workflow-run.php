<?php
/**
 * Tests for WP_MCP_AI_Tool_Replay_Workflow_Run.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 */

/**
 * Replay Workflow Run tool unit tests.
 *
 * @covers WP_MCP_AI_Tool_Replay_Workflow_Run
 */
class Test_Tool_Replay_Workflow_Run extends WP_UnitTestCase {

	/** @var int Admin user ID. */
	private $admin_id;

	/** @var WP_MCP_AI_Tool_Replay_Workflow_Run Tool under test. */
	private $tool;

	/** @var int A valid run post ID. */
	private $run_id;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-workflow-run-cpt.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-replay-workflow-run.php';

		WP_MCP_AI_Workflow_Run_CPT::register_cpt();
		WP_MCP_AI_Workflow_Run_CPT::register_meta();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->tool = new WP_MCP_AI_Tool_Replay_Workflow_Run();

		// Create a run with some events for tests.
		$workflow_id  = wp_insert_post(
			array(
				'post_title'  => 'Dummy Workflow',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$this->run_id = WP_MCP_AI_Workflow_Run_CPT::create_run(
			$workflow_id,
			array( 'param' => 'value' ),
			array(),
			array()
		);
		WP_MCP_AI_Workflow_Run_CPT::append_event( $this->run_id, 'step_started', 'n1', 'tool' );
		WP_MCP_AI_Workflow_Run_CPT::append_event( $this->run_id, 'step_finished', 'n1', 'tool' );
		WP_MCP_AI_Workflow_Run_CPT::set_status( $this->run_id, 'completed' );
	}

	// ── Slug ──────────────────────────────────────────────────────────────────

	/**
	 * Tool slug should be 'replay_workflow_run'.
	 */
	public function test_slug_is_replay_workflow_run() {
		$this->assertSame( 'replay_workflow_run', $this->tool->get_slug() );
	}

	// ── Nonexistent run ───────────────────────────────────────────────────────

	/**
	 * Executing with a nonexistent run_id should return a WP_Error.
	 */
	public function test_returns_error_for_nonexistent_run_id() {
		$result = $this->tool->execute( array( 'run_id' => 999999, 'dry_run' => true ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'run_not_found', $result->get_error_code() );
	}

	/**
	 * Executing with run_id = 0 should return a WP_Error.
	 */
	public function test_returns_error_for_zero_run_id() {
		$result = $this->tool->execute( array( 'run_id' => 0 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_run_id', $result->get_error_code() );
	}

	// ── Dry run ───────────────────────────────────────────────────────────────

	/**
	 * A dry run on a valid run ID should succeed and list events.
	 */
	public function test_dry_run_returns_success_with_event_summary() {
		$result = $this->tool->execute(
			array( 'run_id' => $this->run_id, 'dry_run' => true )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( $this->run_id, $result['original_run_id'] );
		$this->assertSame( 2, $result['events_replayed'] );
		$this->assertArrayHasKey( 'event_summary', $result );
		$this->assertCount( 2, $result['event_summary'] );
	}

	/**
	 * A dry run should not create a new run record.
	 */
	public function test_dry_run_does_not_create_new_run() {
		$before = (int) ( new WP_Query(
			array(
				'post_type'      => WP_MCP_AI_Workflow_Run_CPT::CPT,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) )->found_posts;

		$this->tool->execute( array( 'run_id' => $this->run_id, 'dry_run' => true ) );

		$after = (int) ( new WP_Query(
			array(
				'post_type'      => WP_MCP_AI_Workflow_Run_CPT::CPT,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) )->found_posts;

		$this->assertSame( $before, $after );
	}

	// ── Capability flags ──────────────────────────────────────────────────────

	/**
	 * The tool must implement WP_MCP_AI_Tool_Capability_Flags_Interface.
	 */
	public function test_implements_capability_flags_interface() {
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $this->tool );
	}

	/**
	 * Capability flags must include 'requires-approval'.
	 */
	public function test_capability_flags_include_requires_approval() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'requires-approval', $flags );
	}

	// ── Permissions ───────────────────────────────────────────────────────────

	/**
	 * Executing as a subscriber (no manage_options) should return WP_Error.
	 */
	public function test_returns_forbidden_for_non_admin() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$result = $this->tool->execute( array( 'run_id' => $this->run_id, 'dry_run' => true ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	// ── Parameters schema ─────────────────────────────────────────────────────

	/**
	 * The parameters schema must require 'run_id'.
	 */
	public function test_parameters_schema_requires_run_id() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'run_id', $schema['required'] );
	}
}
