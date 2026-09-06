<?php
/**
 * Workflow engine V2 port tests (Wave E1, sub-cluster 4).
 *
 * Characterization suite for the ported `WorkflowEngine`:
 * byte-identical enable gate + filter, the manage_options/post/CPT
 * guards, before/after lifecycle actions, the `wf2-` run identifier,
 * the durable run record (running → terminal status with step events),
 * the graph → description → `execute_workflow` delegation with the
 * byte-identical argument shape, the no-tool / no-registry degradation
 * branches, parallel-node detection, budget forwarding, and the
 * per-mode collaborator seams. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Workflows\WorkflowCpt;
use NvoosContentGraphAiPlatform\Workflows\WorkflowEngine;
use NvoosContentGraphAiPlatform\Workflows\WorkflowRunCpt;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixtures share this file with its test case.

/**
 * Fake execute_workflow tool recording invocations.
 */
class FakeExecuteWorkflowTool {

	/**
	 * Recorded calls.
	 *
	 * @var array
	 */
	public static $calls = array();

	/**
	 * Forced result.
	 *
	 * @var array|\WP_Error
	 */
	public static $result = array(
		'success' => true,
		'message' => 'done',
	);

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|\WP_Error
	 */
	public function execute( $arguments, $context ) {
		self::$calls[] = array( $arguments, $context );
		return self::$result;
	}
}

/**
 * Fake registry exposing (or not) the execute_workflow tool.
 */
class FakeToolRegistry {

	/**
	 * Whether to expose the tool.
	 *
	 * @var bool
	 */
	public static $has_tool = true;

	/**
	 * Resolve a tool by slug.
	 *
	 * @param string $slug Tool slug.
	 * @return object|null
	 */
	public function get_tool( $slug ) {
		if ( 'execute_workflow' === $slug && self::$has_tool ) {
			return new FakeExecuteWorkflowTool();
		}
		return null;
	}
}

/**
 * Seam forcing the fake registry + exposing protected resolution.
 */
class WorkflowEngineSeam extends WorkflowEngine {

	/**
	 * The fake registry.
	 *
	 * @return object|null
	 */
	protected static function tool_registry() {
		return new FakeToolRegistry();
	}

	/**
	 * Probe a protected static seam.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed Method result.
	 */
	public static function probe( $method, array $args = array() ) {
		return self::$method( ...$args );
	}
}

/**
 * @group workflows
 */
class Test_Workflow_Engine extends \WP_UnitTestCase {

	/**
	 * Current admin user ID.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * Current subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id = 0;

	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin_id );

		WorkflowCpt::register_cpt();
		WorkflowRunCpt::register_cpt();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_mcp_ai_workflow_v2_enabled' );
		remove_all_actions( 'wp_mcp_ai_workflow_v2_before_execute' );
		remove_all_actions( 'wp_mcp_ai_workflow_v2_after_execute' );
		parent::tearDown();
	}

	/**
	 * Create a workflow post with the given graph.
	 *
	 * @param array $graph Graph array.
	 * @return int
	 */
	private function create_workflow( array $graph = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => WorkflowCpt::CPT,
				'post_title'  => 'Ship It',
				'post_status' => 'publish',
			)
		);
		WorkflowCpt::save_graph( $post_id, $graph );

		return $post_id;
	}

	// ─── Gate ─────────────────────────────────────────────────────────

	public function test_is_enabled_defaults_true_and_honors_filter(): void {
		$this->assertTrue( WorkflowEngine::is_enabled() );

		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_false' );
		$this->assertFalse( WorkflowEngine::is_enabled() );
	}

	public function test_execute_rejects_when_disabled(): void {
		add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_false' );

		$result = WorkflowEngine::execute( $this->create_workflow() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_error', $result->get_error_code() );
	}

	public function test_execute_requires_manage_options(): void {
		$workflow_id = $this->create_workflow();
		wp_set_current_user( $this->subscriber_id );

		$result = WorkflowEngine::execute( $workflow_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_error', $result->get_error_code() );
	}

	public function test_execute_rejects_missing_or_wrong_post(): void {
		$this->assertWPError( WorkflowEngine::execute( 999999 ) );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertWPError( WorkflowEngine::execute( $page_id ) );
	}

	// ─── Happy path + run record ──────────────────────────────────────

	public function test_execute_delegates_to_tool_and_records_run(): void {
		FakeExecuteWorkflowTool::$calls  = array();
		FakeExecuteWorkflowTool::$result = array(
			'success' => true,
			'message' => 'shipped',
		);
		FakeToolRegistry::$has_tool      = true;

		$workflow_id = $this->create_workflow(
			array(
				'nodes' => array(
					array(
						'id'    => 'n1',
						'label' => 'Build',
						'type'  => 'tool',
					),
					array(
						'id'    => 'n2',
						'label' => 'Deploy',
						'type'  => 'parallel',
					),
				),
				'edges' => array(),
			)
		);

		$before = array();
		$after  = array();
		add_action(
			'wp_mcp_ai_workflow_v2_before_execute',
			function ( $id, $input ) use ( &$before ) {
				$before = array( $id, $input );
			},
			10,
			2
		);
		add_action(
			'wp_mcp_ai_workflow_v2_after_execute',
			function ( $id, $result ) use ( &$after ) {
				$after = array( $id, $result );
			},
			10,
			2
		);

		$result = WorkflowEngineSeam::execute(
			$workflow_id,
			array( 'site' => 'example.com' ),
			array(
				'task_type'    => 'deploy',
				'assistant_id' => 3,
			)
		);

		// Envelope.
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'shipped', $result['message'] );
		$this->assertStringStartsWith( 'wf2-' . $workflow_id . '-', $result['run_id'] );
		$this->assertSame(
			array(
				'success' => true,
				'message' => 'shipped',
			),
			$result['results']
		);

		// Lifecycle actions.
		$this->assertSame( $workflow_id, $before[0] );
		$this->assertSame( array( 'site' => 'example.com' ), $before[1] );
		$this->assertSame( $workflow_id, $after[0] );
		$this->assertSame( $result, $after[1] );

		// Tool delegation arguments (byte-identical shape).
		$tool_args = FakeExecuteWorkflowTool::$calls[0][0];
		$this->assertSame( 'Ship It: [tool] Build -> [parallel] Deploy', $tool_args['description'] );
		$this->assertSame( 'deploy', $tool_args['task_type'] );
		$this->assertTrue( $tool_args['parallel'] );
		$this->assertSame(
			array(
				'site'   => 'example.com',
				'run_id' => $result['run_id'],
			),
			$tool_args['context']
		);

		// Durable run record.
		$run = WorkflowRunCpt::get_run( $result['cpt_run_id'] );
		$this->assertSame( $workflow_id, $run['workflow_id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertCount( 2, $run['event_log'] );
		$this->assertSame( 'step_started', $run['event_log'][0]['type'] );
		$this->assertSame( 'step_finished', $run['event_log'][1]['type'] );
		$this->assertGreaterThan( 0, $run['finished_at'] );
	}

	// ─── Degradation branches ─────────────────────────────────────────

	public function test_execute_tool_error_fails_run(): void {
		FakeExecuteWorkflowTool::$result = new \WP_Error( 'boom', 'Tool exploded.' );
		FakeToolRegistry::$has_tool      = true;

		$result = WorkflowEngineSeam::execute( $this->create_workflow() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Tool exploded.', $result['message'] );

		$run = WorkflowRunCpt::get_run( $result['cpt_run_id'] );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'step_errored', $run['event_log'][1]['type'] );
		$this->assertSame( 'Tool exploded.', $run['event_log'][1]['data']['error'] );
	}

	public function test_execute_without_tool_registered_degrades(): void {
		FakeToolRegistry::$has_tool = false;

		$workflow_id = $this->create_workflow(
			array(
				'nodes' => array(),
				'edges' => array(),
			)
		);
		$result      = WorkflowEngineSeam::execute( $workflow_id, array( 'a' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Graph executed (no execute_workflow tool registered).', $result['message'] );
		$this->assertSame(
			array(
				'nodes' => array(),
				'edges' => array(),
			),
			$result['results']['graph']
		);
		$this->assertSame( array( 'a' => 1 ), $result['results']['input'] );

		$run = WorkflowRunCpt::get_run( $result['cpt_run_id'] );
		$this->assertSame( 'completed', $run['status'] );
	}

	// ─── Parallel detection + budget ──────────────────────────────────

	public function test_parallel_flag_reflects_graph(): void {
		FakeExecuteWorkflowTool::$calls = array();
		FakeToolRegistry::$has_tool     = true;

		// No parallel nodes → parallel false.
		WorkflowEngineSeam::execute(
			$this->create_workflow(
				array(
					'nodes' => array(
						array(
							'id'    => 'n1',
							'label' => 'Build',
							'type'  => 'tool',
						),
					),
					'edges' => array(),
				)
			)
		);
		$this->assertFalse( FakeExecuteWorkflowTool::$calls[0][0]['parallel'] );

		// Parallel node → parallel true (covered above too; explicit here).
		WorkflowEngineSeam::execute(
			$this->create_workflow(
				array(
					'nodes' => array(
						array(
							'id'    => 'n1',
							'label' => 'Fan',
							'type'  => 'parallel',
						),
					),
					'edges' => array(),
				)
			)
		);
		$this->assertTrue( FakeExecuteWorkflowTool::$calls[1][0]['parallel'] );
	}

	public function test_run_budget_context_forwards_to_run_record(): void {
		FakeToolRegistry::$has_tool = true;

		$result = WorkflowEngineSeam::execute(
			$this->create_workflow(),
			array(),
			array(
				'run_budget' => array(
					'max_tokens' => 500,
				),
			)
		);

		$run = WorkflowRunCpt::get_run( $result['cpt_run_id'] );
		$this->assertSame( array( 'max_tokens' => 500 ), $run['budget'] );
	}

	// ─── Per-mode seams ───────────────────────────────────────────────

	public function test_collaborator_seams_resolve_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Workflow_CPT', WorkflowEngineSeam::probe( 'workflow_cpt_class' ) );
			$this->assertSame( 'WP_MCP_AI_Workflow_Run_CPT', WorkflowEngineSeam::probe( 'run_cpt_class' ) );
		} else {
			$this->assertSame( 'NvoosContentGraphAiPlatform\Workflows\WorkflowCpt', WorkflowEngineSeam::probe( 'workflow_cpt_class' ) );
			$this->assertSame( 'NvoosContentGraphAiPlatform\Workflows\WorkflowRunCpt', WorkflowEngineSeam::probe( 'run_cpt_class' ) );
		}
	}
}
