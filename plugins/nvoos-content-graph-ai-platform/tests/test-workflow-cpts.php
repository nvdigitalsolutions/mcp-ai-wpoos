<?php
/**
 * Workflow CPT port tests (Wave E1, sub-cluster 1).
 *
 * Characterization suite for the ported `WorkflowCpt`, `WorkflowRunCpt`,
 * and `WorkflowTriggerCpt`: byte-identical CPT + meta registration,
 * graph read/write with malformed-JSON fallback, the export/import JSON
 * contract, semver bumping, the run lifecycle + event log + status
 * transitions + budget checks, trigger discovery + all six hook bridges
 * + the fire path, and the per-mode dispatcher/engine seams. Real posts
 * in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Workflows\WorkflowCpt;
use NvoosContentGraphAiPlatform\Workflows\WorkflowRunCpt;
use NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixtures share this file with its test case.

/**
 * Seam forcing no dispatcher/engine (documented standalone degradation).
 */
class WorkflowTriggerNoDispatchSeam extends WorkflowTriggerCpt {

	/**
	 * No dispatcher.
	 *
	 * @return string|null
	 */
	protected static function dispatcher_class(): ?string {
		return null;
	}

	/**
	 * No engine.
	 *
	 * @return string|null
	 */
	protected static function engine_class(): ?string {
		return null;
	}
}

/**
 * Fake dispatcher recording dispatch calls.
 */
class FakeWorkflowDispatcher {

	/**
	 * Recorded dispatch calls.
	 *
	 * @var array
	 */
	public static $calls = array();

	/**
	 * Record the call.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $input       Input payload.
	 * @param array $context     Dispatch context.
	 * @return void
	 */
	public static function dispatch( $workflow_id, $input = array(), $context = array() ): void {
		self::$calls[] = array( $workflow_id, $input, $context );
	}
}

/**
 * Seam forcing the fake dispatcher.
 */
class WorkflowTriggerDispatchSeam extends WorkflowTriggerCpt {

	/**
	 * The fake dispatcher.
	 *
	 * @return string
	 */
	protected static function dispatcher_class(): ?string {
		return FakeWorkflowDispatcher::class;
	}

	/**
	 * No engine fallback.
	 *
	 * @return string|null
	 */
	protected static function engine_class(): ?string {
		return null;
	}
}

/**
 * Fake engine recording run calls.
 */
class FakeWorkflowEngine {

	/**
	 * Recorded run calls.
	 *
	 * @var array
	 */
	public static $runs = array();

	/**
	 * Record the run.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $payload     Payload.
	 * @return void
	 */
	public function run( $workflow_id, $payload = array() ): void {
		self::$runs[] = array( $workflow_id, $payload );
	}
}

/**
 * Seam forcing the engine fallback (dispatcher absent).
 */
class WorkflowTriggerEngineSeam extends WorkflowTriggerCpt {

	/**
	 * No dispatcher.
	 *
	 * @return string|null
	 */
	protected static function dispatcher_class(): ?string {
		return null;
	}

	/**
	 * The fake engine.
	 *
	 * @return string
	 */
	protected static function engine_class(): ?string {
		return FakeWorkflowEngine::class;
	}
}

/**
 * Seam exposing the per-mode dispatcher/engine resolution without overrides.
 */
class WorkflowTriggerProbeSeam extends WorkflowTriggerCpt {

	/**
	 * Probe the dispatcher resolution.
	 *
	 * @return string|null
	 */
	public static function probe_dispatcher() {
		return self::dispatcher_class();
	}

	/**
	 * Probe the engine resolution.
	 *
	 * @return string|null
	 */
	public static function probe_engine() {
		return self::engine_class();
	}
}

/**
 * @group workflows
 */
class Test_Workflow_Cpts extends \WP_UnitTestCase {

	/**
	 * Captured `wp_mcp_ai_trigger_fired` events.
	 *
	 * @var array
	 */
	private $trigger_events = array();

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

		// Deterministic hook state: earlier tests' trigger closures must not
		// leak into later ones.
		remove_all_actions( 'transition_post_status' );
		remove_all_actions( 'wp_mcp_ai_a2a_message_received' );
		remove_all_actions( 'user_register' );
		remove_all_actions( 'comment_post' );
		remove_all_actions( 'add_attachment' );

		$this->trigger_events = array();
		add_action(
			'wp_mcp_ai_trigger_fired',
			function ( $trigger_id, $workflow_id, $payload ) {
				$this->trigger_events[] = array(
					'trigger_id'  => $trigger_id,
					'workflow_id' => $workflow_id,
					'payload'     => $payload,
				);
			},
			10,
			3
		);

		WorkflowCpt::register_cpt();
		WorkflowRunCpt::register_cpt();
		WorkflowTriggerCpt::register_cpt();
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_actions( 'wp_mcp_ai_trigger_fired' );
		remove_all_actions( 'wp_mcp_ai_workflow_run_budget_exceeded' );
		parent::tearDown();
	}

	/**
	 * Create a workflow post.
	 *
	 * @return int
	 */
	private function create_workflow(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => WorkflowCpt::CPT,
				'post_title'  => 'Test Workflow',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Create an enabled trigger post.
	 *
	 * @param string $type        Trigger type.
	 * @param int    $workflow_id Target workflow ID.
	 * @param array  $config      Type-specific config.
	 * @return int Trigger post ID.
	 */
	private function create_trigger( string $type, int $workflow_id, array $config = array() ): int {
		$trigger_id = self::factory()->post->create(
			array(
				'post_type'   => WorkflowTriggerCpt::CPT,
				'post_title'  => 'Trigger ' . $type,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $trigger_id, '_wp_mcp_ai_trigger_type', $type );
		update_post_meta( $trigger_id, '_wp_mcp_ai_trigger_workflow_id', $workflow_id );
		update_post_meta( $trigger_id, '_wp_mcp_ai_trigger_enabled', true );
		update_post_meta( $trigger_id, '_wp_mcp_ai_trigger_config', wp_json_encode( $config ) );

		return $trigger_id;
	}

	/**
	 * Capture the fired event for one trigger.
	 *
	 * @param int $trigger_id Trigger post ID.
	 * @return array|null
	 */
	private function fired_for( int $trigger_id ) {
		foreach ( $this->trigger_events as $event ) {
			if ( $trigger_id === $event['trigger_id'] ) {
				return $event;
			}
		}
		return null;
	}

	// ─── WorkflowCpt ──────────────────────────────────────────────────

	public function test_workflow_constants_are_byte_identical(): void {
		$this->assertSame( 'mcp_ai_workflow', WorkflowCpt::CPT );
		$this->assertSame( '_wp_mcp_ai_workflow_graph', WorkflowCpt::META_GRAPH );
		$this->assertSame( '_wp_mcp_ai_workflow_version', WorkflowCpt::META_VERSION );
		$this->assertSame( '_wp_mcp_ai_workflow_tags', WorkflowCpt::META_TAGS );
	}

	public function test_workflow_cpt_registers_with_manage_options_capabilities(): void {
		$cpt = get_post_type_object( WorkflowCpt::CPT );

		$this->assertFalse( $cpt->public );
		$this->assertTrue( $cpt->show_ui );
		$this->assertFalse( $cpt->show_in_menu );
		$this->assertFalse( $cpt->show_in_rest );
		$this->assertSame( 'manage_options', $cpt->cap->edit_posts );
		$this->assertSame( 'manage_options', $cpt->cap->publish_posts );
		$this->assertTrue( post_type_supports( WorkflowCpt::CPT, 'title' ) );
		$this->assertTrue( post_type_supports( WorkflowCpt::CPT, 'editor' ) );
		$this->assertTrue( post_type_supports( WorkflowCpt::CPT, 'revisions' ) );
		$this->assertTrue( post_type_supports( WorkflowCpt::CPT, 'custom-fields' ) );
	}

	public function test_workflow_meta_is_registered(): void {
		WorkflowCpt::register_meta();

		$keys = get_registered_meta_keys( 'post', WorkflowCpt::CPT );

		$this->assertArrayHasKey( WorkflowCpt::META_GRAPH, $keys );
		$this->assertArrayHasKey( WorkflowCpt::META_VERSION, $keys );
		$this->assertArrayHasKey( WorkflowCpt::META_TAGS, $keys );
		$this->assertSame( 'string', $keys[ WorkflowCpt::META_GRAPH ]['type'] );
		$this->assertTrue( $keys[ WorkflowCpt::META_GRAPH ]['single'] );
	}

	public function test_graph_roundtrip_and_fallbacks(): void {
		$post_id = $this->create_workflow();

		// Empty meta → empty graph.
		$this->assertSame(
			array(
				'nodes' => array(),
				'edges' => array(),
			),
			WorkflowCpt::get_graph( $post_id )
		);

		$graph = array(
			'nodes' => array(
				array(
					'id'   => 'n1',
					'type' => 'tool',
				),
			),
			'edges' => array(
				array(
					'from' => 'n1',
					'to'   => 'n2',
				),
			),
		);
		$this->assertTrue( WorkflowCpt::save_graph( $post_id, $graph ) );
		$this->assertSame( $graph, WorkflowCpt::get_graph( $post_id ) );

		// Malformed JSON falls back to the empty shape.
		update_post_meta( $post_id, WorkflowCpt::META_GRAPH, '{not-json' );
		$this->assertSame(
			array(
				'nodes' => array(),
				'edges' => array(),
			),
			WorkflowCpt::get_graph( $post_id )
		);
	}

	public function test_save_graph_requires_manage_options(): void {
		$post_id = $this->create_workflow();

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse(
			WorkflowCpt::save_graph(
				$post_id,
				array(
					'nodes' => array( 1 ),
					'edges' => array(),
				)
			)
		);
	}

	public function test_export_json_shape(): void {
		$post_id = $this->create_workflow();
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'A workflow description.',
			)
		);
		update_post_meta( $post_id, WorkflowCpt::META_VERSION, '2.3.1' );
		update_post_meta( $post_id, WorkflowCpt::META_TAGS, wp_slash( wp_json_encode( array( 'tag-a' ) ) ) );
		WorkflowCpt::save_graph(
			$post_id,
			array(
				'nodes' => array( array( 'id' => 'n1' ) ),
				'edges' => array(),
			)
		);

		$export = WorkflowCpt::export_json( $post_id );

		$this->assertSame( '1.0', $export['schema_version'] );
		$this->assertSame( 'Test Workflow', $export['name'] );
		$this->assertSame( 'A workflow description.', $export['description'] );
		$this->assertSame( '2.3.1', $export['version'] );
		$this->assertSame( array( 'tag-a' ), $export['tags'] );
		$this->assertSame( array( array( 'id' => 'n1' ) ), $export['graph']['nodes'] );
		$this->assertNotEmpty( $export['exported_at'] );

		// Non-workflow posts export as an empty payload.
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( array(), WorkflowCpt::export_json( $page_id ) );
	}

	public function test_import_json_creates_and_overwrites(): void {
		$payload = array(
			'name'        => 'Imported',
			'description' => 'Imported description.',
			'version'     => '1.2.3',
			'tags'        => array( 'one', 'two' ),
			'graph'       => array(
				'nodes' => array( array( 'id' => 'x' ) ),
				'edges' => array(),
			),
		);

		$created = WorkflowCpt::import_json( $payload );
		$this->assertNotInstanceOf( \WP_Error::class, $created );

		$post = get_post( $created );
		$this->assertSame( WorkflowCpt::CPT, $post->post_type );
		$this->assertSame( 'Imported', $post->post_title );
		$this->assertSame( 'Imported description.', $post->post_content );
		$this->assertSame( '1.2.3', get_post_meta( $created, WorkflowCpt::META_VERSION, true ) );
		$this->assertSame( array( array( 'id' => 'x' ) ), WorkflowCpt::get_graph( $created )['nodes'] );

		// Overwrite path.
		$payload['name'] = 'Renamed';
		$updated         = WorkflowCpt::import_json( $payload, $created );
		$this->assertSame( $created, $updated );
		$this->assertSame( 'Renamed', get_the_title( $updated ) );
	}

	public function test_import_json_validation_and_capability(): void {
		$this->assertWPError( WorkflowCpt::import_json( array( 'name' => '' ) ) );
		$this->assertSame( 'invalid_data', WorkflowCpt::import_json( array( 'name' => '' ) )->get_error_code() );

		wp_set_current_user( $this->subscriber_id );
		$result = WorkflowCpt::import_json( array( 'name' => 'Blocked' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_bump_version_semver(): void {
		$post_id = $this->create_workflow();
		update_post_meta( $post_id, WorkflowCpt::META_VERSION, '1.2.3' );

		$this->assertSame( '1.2.4', WorkflowCpt::bump_version( $post_id, 'patch' ) );
		$this->assertSame( '1.3.0', WorkflowCpt::bump_version( $post_id, 'minor' ) );
		$this->assertSame( '2.0.0', WorkflowCpt::bump_version( $post_id, 'major' ) );

		delete_post_meta( $post_id, WorkflowCpt::META_VERSION );
		$this->assertSame( '1.0.1', WorkflowCpt::bump_version( $post_id ) );
	}

	// ─── WorkflowRunCpt ───────────────────────────────────────────────

	public function test_run_constants_are_byte_identical(): void {
		$this->assertSame( 'mcp_ai_workflow_run', WorkflowRunCpt::CPT );
		$this->assertSame( array( 'pending', 'running', 'completed', 'failed', 'cancelled' ), WorkflowRunCpt::STATUSES );
		$this->assertSame(
			array( 'step_started', 'step_finished', 'step_errored', 'step_retried', 'budget_exceeded', 'checkpoint' ),
			WorkflowRunCpt::EVENT_TYPES
		);
	}

	public function test_run_cpt_and_meta_registration(): void {
		WorkflowRunCpt::register_meta();

		$cpt = get_post_type_object( WorkflowRunCpt::CPT );
		$this->assertFalse( $cpt->public );
		$this->assertFalse( $cpt->show_ui );
		$this->assertTrue( post_type_supports( WorkflowRunCpt::CPT, 'custom-fields' ) );

		$keys = get_registered_meta_keys( 'post', WorkflowRunCpt::CPT );
		$this->assertCount( 10, $keys );
		$this->assertSame( 'integer', $keys['_wp_mcp_ai_run_workflow_id']['type'] );
		$this->assertSame( 'number', $keys['_wp_mcp_ai_run_cost_usd']['type'] );
	}

	public function test_create_run_seeds_all_meta(): void {
		$workflow_id = $this->create_workflow();
		$before      = time();

		$run_id = WorkflowRunCpt::create_run(
			$workflow_id,
			array( 'site' => 'example.com' ),
			array( 'max_tokens' => 1000 ),
			array( 'assistant_id' => 5 )
		);
		$after  = time();

		$post = get_post( $run_id );
		$this->assertSame( WorkflowRunCpt::CPT, $post->post_type );
		$this->assertStringContainsString( (string) $run_id, $post->post_title );
		$this->assertStringContainsString( (string) $workflow_id, $post->post_title );

		$this->assertSame( $workflow_id, (int) get_post_meta( $run_id, '_wp_mcp_ai_run_workflow_id', true ) );
		$this->assertSame( 'pending', get_post_meta( $run_id, '_wp_mcp_ai_run_status', true ) );
		$this->assertSame( array( 'site' => 'example.com' ), json_decode( (string) get_post_meta( $run_id, '_wp_mcp_ai_run_input', true ), true ) );
		$this->assertSame( array( 'assistant_id' => 5 ), json_decode( (string) get_post_meta( $run_id, '_wp_mcp_ai_run_context', true ), true ) );
		$this->assertSame( array(), json_decode( (string) get_post_meta( $run_id, '_wp_mcp_ai_run_event_log', true ), true ) );
		$this->assertSame( 0.0, (float) get_post_meta( $run_id, '_wp_mcp_ai_run_cost_usd', true ) );
		$this->assertSame( 0, (int) get_post_meta( $run_id, '_wp_mcp_ai_run_tokens_used', true ) );
		$this->assertSame( 0, (int) get_post_meta( $run_id, '_wp_mcp_ai_run_finished_at', true ) );

		$started = (int) get_post_meta( $run_id, '_wp_mcp_ai_run_started_at', true );
		$this->assertGreaterThanOrEqual( $before, $started );
		$this->assertLessThanOrEqual( $after, $started );
	}

	public function test_event_log_appends_with_sequence(): void {
		$run_id = WorkflowRunCpt::create_run( $this->create_workflow() );

		$this->assertTrue( WorkflowRunCpt::append_event( $run_id, 'step_started', 'node-1', 'tool', array( 'x' => 1 ) ) );
		$this->assertTrue( WorkflowRunCpt::append_event( $run_id, 'step_finished', 'node-1', 'tool' ) );

		$log = WorkflowRunCpt::get_event_log( $run_id );
		$this->assertCount( 2, $log );
		$this->assertSame( 1, $log[0]['seq'] );
		$this->assertSame( 'step_started', $log[0]['type'] );
		$this->assertSame( 'node-1', $log[0]['node_id'] );
		$this->assertSame( array( 'x' => 1 ), $log[0]['data'] );
		$this->assertSame( 2, $log[1]['seq'] );
		$this->assertNotEmpty( $log[1]['timestamp'] );

		// Missing run → false, empty log for foreign posts.
		$this->assertFalse( WorkflowRunCpt::append_event( 999999, 'step_started', 'n', 'tool' ) );
		$this->assertSame( array(), WorkflowRunCpt::get_event_log( 999999 ) );
	}

	public function test_set_status_transitions_and_stamps_terminal(): void {
		$run_id = WorkflowRunCpt::create_run( $this->create_workflow() );

		$this->assertTrue( WorkflowRunCpt::set_status( $run_id, 'running' ) );
		$this->assertSame( 'running', get_post_meta( $run_id, '_wp_mcp_ai_run_status', true ) );
		$this->assertSame( 0, (int) get_post_meta( $run_id, '_wp_mcp_ai_run_finished_at', true ) );

		$before = time();
		$this->assertTrue( WorkflowRunCpt::set_status( $run_id, 'completed' ) );
		$finished = (int) get_post_meta( $run_id, '_wp_mcp_ai_run_finished_at', true );
		$this->assertGreaterThanOrEqual( $before, $finished );

		// Invalid status + missing run.
		$this->assertFalse( WorkflowRunCpt::set_status( $run_id, 'bogus' ) );
		$this->assertFalse( WorkflowRunCpt::set_status( 999999, 'completed' ) );
	}

	public function test_get_run_shape(): void {
		$run_id = WorkflowRunCpt::create_run( $this->create_workflow(), array( 'in' => 1 ) );
		WorkflowRunCpt::append_event( $run_id, 'checkpoint', 'node-1', 'condition' );
		WorkflowRunCpt::set_status( $run_id, 'failed' );

		$run = WorkflowRunCpt::get_run( $run_id );
		$this->assertSame( $run_id, $run['id'] );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( array( 'in' => 1 ), $run['input'] );
		$this->assertCount( 1, $run['event_log'] );
		$this->assertGreaterThan( 0, $run['finished_at'] );

		$this->assertFalse( WorkflowRunCpt::get_run( 999999 ) );
	}

	public function test_check_budget_dimensions_and_action(): void {
		$run_id = WorkflowRunCpt::create_run(
			$this->create_workflow(),
			array(),
			array(
				'max_cost_usd'     => 1.0,
				'max_tokens'       => 10,
				'max_wall_seconds' => 3600,
				'max_steps'        => 1,
			)
		);

		// Within budget.
		$this->assertTrue( WorkflowRunCpt::check_budget( $run_id ) );

		// Breach cost + tokens + steps (append two events → 2 > max_steps).
		update_post_meta( $run_id, '_wp_mcp_ai_run_cost_usd', 1.5 );
		update_post_meta( $run_id, '_wp_mcp_ai_run_tokens_used', 25 );
		WorkflowRunCpt::append_event( $run_id, 'step_started', 'n1', 'tool' );
		WorkflowRunCpt::append_event( $run_id, 'step_started', 'n2', 'tool' );

		$violations = null;
		add_action(
			'wp_mcp_ai_workflow_run_budget_exceeded',
			function ( $rid, $v ) use ( &$violations ) {
				$violations = array( $rid, $v );
			},
			10,
			2
		);

		$this->assertFalse( WorkflowRunCpt::check_budget( $run_id ) );
		$this->assertSame( $run_id, $violations[0] );
		$this->assertArrayHasKey( 'cost_usd', $violations[1] );
		$this->assertArrayHasKey( 'tokens_used', $violations[1] );
		$this->assertArrayHasKey( 'steps', $violations[1] );
		$this->assertSame( 1.5, $violations[1]['cost_usd']['current'] );

		// No budget → always within; missing run → false.
		$plain = WorkflowRunCpt::create_run( $this->create_workflow() );
		$this->assertTrue( WorkflowRunCpt::check_budget( $plain ) );
		$this->assertFalse( WorkflowRunCpt::check_budget( 999999 ) );
	}

	// ─── WorkflowTriggerCpt ───────────────────────────────────────────

	public function test_trigger_constants_and_registration(): void {
		$this->assertSame( 'mcp_ai_trigger', WorkflowTriggerCpt::CPT );

		WorkflowTriggerCpt::register_meta();

		$cpt = get_post_type_object( WorkflowTriggerCpt::CPT );
		$this->assertFalse( $cpt->public );
		$this->assertFalse( $cpt->show_ui );
		$this->assertTrue( post_type_supports( WorkflowTriggerCpt::CPT, 'title' ) );

		$keys = get_registered_meta_keys( 'post', WorkflowTriggerCpt::CPT );
		$this->assertCount( 5, $keys );
		$this->assertSame( 'boolean', $keys['_wp_mcp_ai_trigger_enabled']['type'] );
		$this->assertSame( true, $keys['_wp_mcp_ai_trigger_enabled']['default'] );
	}

	public function test_register_all_triggers_skips_disabled_and_incomplete(): void {
		$workflow_id = $this->create_workflow();

		// Create the user BEFORE hooking so the factory's own user_register
		// firing does not pollute the capture.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Disabled trigger + missing-workflow trigger + fully valid trigger.
		$disabled = $this->create_trigger( 'user_registration', $workflow_id );
		update_post_meta( $disabled, '_wp_mcp_ai_trigger_enabled', false );

		$incomplete = self::factory()->post->create(
			array(
				'post_type'   => WorkflowTriggerCpt::CPT,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $incomplete, '_wp_mcp_ai_trigger_type', 'user_registration' );
		update_post_meta( $incomplete, '_wp_mcp_ai_trigger_enabled', true );

		$valid = $this->create_trigger( 'user_registration', $workflow_id );

		WorkflowTriggerNoDispatchSeam::register_all_triggers();

		do_action( 'user_register', $user_id );

		$this->assertNotNull( $this->fired_for( $valid ) );
		$this->assertNull( $this->fired_for( $disabled ) );
		$this->assertNull( $this->fired_for( $incomplete ) );
		$this->assertSame( $workflow_id, $this->fired_for( $valid )['workflow_id'] );
		$this->assertSame( array( 'user_id' => $user_id ), $this->fired_for( $valid )['payload'] );
	}

	public function test_post_status_change_trigger_filters(): void {
		$workflow_id = $this->create_workflow();
		$trigger_id  = $this->create_trigger(
			'post_status_change',
			$workflow_id,
			array(
				'post_type'   => 'post',
				'from_status' => 'draft',
				'to_status'   => 'publish',
			)
		);

		WorkflowTriggerNoDispatchSeam::register_all_triggers();

		// Non-matching to-status → no fire.
		$post_a = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_update_post(
			array(
				'ID'          => $post_a,
				'post_status' => 'pending',
			)
		);
		$this->assertNull( $this->fired_for( $trigger_id ) );

		// Matching draft → publish transition → fire with payload.
		$post_b = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_update_post(
			array(
				'ID'          => $post_b,
				'post_status' => 'publish',
			)
		);
		$event = $this->fired_for( $trigger_id );
		$this->assertNotNull( $event );
		$this->assertSame( $post_b, $event['payload']['post_id'] );
		$this->assertSame( 'publish', $event['payload']['new_status'] );
		$this->assertSame( 'draft', $event['payload']['old_status'] );
	}

	public function test_cron_schedule_trigger_fires_on_hook(): void {
		$workflow_id = $this->create_workflow();
		$trigger_id  = $this->create_trigger( 'cron_schedule', $workflow_id, array( 'schedule' => 'hourly' ) );

		WorkflowTriggerNoDispatchSeam::register_all_triggers();

		$hook = 'wp_mcp_ai_trigger_cron_' . $trigger_id;
		$this->assertNotFalse( wp_next_scheduled( $hook ) );

		do_action( $hook );
		$this->assertNotNull( $this->fired_for( $trigger_id ) );

		wp_clear_scheduled_hook( $hook );
	}

	public function test_a2a_inbound_trigger_fires_on_message(): void {
		$workflow_id = $this->create_workflow();
		$trigger_id  = $this->create_trigger( 'a2a_inbound', $workflow_id );

		WorkflowTriggerNoDispatchSeam::register_all_triggers();

		$message = array(
			'messageId' => 'm-1',
			'role'      => 'user',
			'parts'     => array(),
		);
		do_action( 'wp_mcp_ai_a2a_message_received', $message );

		$event = $this->fired_for( $trigger_id );
		$this->assertNotNull( $event );
		$this->assertSame( $message, $event['payload']['message'] );
	}

	public function test_comment_published_trigger_gates_on_approval(): void {
		$workflow_id = $this->create_workflow();
		$trigger_id  = $this->create_trigger( 'comment_published', $workflow_id );

		WorkflowTriggerNoDispatchSeam::register_all_triggers();

		// Unapproved comment → no fire.
		do_action( 'comment_post', 123, 0 );
		$this->assertNull( $this->fired_for( $trigger_id ) );

		// Approved comment → fire.
		do_action( 'comment_post', 456, 1 );
		$this->assertSame( array( 'comment_id' => 456 ), $this->fired_for( $trigger_id )['payload'] );
	}

	public function test_file_upload_trigger_fires_on_attachment(): void {
		$workflow_id = $this->create_workflow();
		$trigger_id  = $this->create_trigger( 'file_upload', $workflow_id );

		WorkflowTriggerNoDispatchSeam::register_all_triggers();

		do_action( 'add_attachment', 789 );
		$this->assertSame( array( 'attachment_id' => 789 ), $this->fired_for( $trigger_id )['payload'] );
	}

	public function test_fire_trigger_stamps_and_dispatches(): void {
		$workflow_id = $this->create_workflow();
		$trigger_id  = $this->create_trigger( 'file_upload', $workflow_id );

		// No-execution seam: timestamp + action only.
		WorkflowTriggerNoDispatchSeam::fire_trigger( $trigger_id, $workflow_id, array( 'a' => 1 ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $trigger_id, '_wp_mcp_ai_trigger_last_fired_at', true ) );
		$this->assertSame( array( 'a' => 1 ), $this->fired_for( $trigger_id )['payload'] );

		// Dispatcher seam: hand-off with byte-identical context shape.
		FakeWorkflowDispatcher::$calls = array();
		WorkflowTriggerDispatchSeam::fire_trigger( $trigger_id, $workflow_id, array( 'b' => 2 ) );
		$this->assertCount( 1, FakeWorkflowDispatcher::$calls );
		$this->assertSame( $workflow_id, FakeWorkflowDispatcher::$calls[0][0] );
		$this->assertSame( array( 'b' => 2 ), FakeWorkflowDispatcher::$calls[0][1] );
		$this->assertSame(
			array(
				'source'     => 'trigger',
				'trigger_id' => $trigger_id,
			),
			FakeWorkflowDispatcher::$calls[0][2]
		);

		// Engine fallback seam.
		FakeWorkflowEngine::$runs = array();
		WorkflowTriggerEngineSeam::fire_trigger( $trigger_id, $workflow_id, array( 'c' => 3 ) );
		$this->assertCount( 1, FakeWorkflowEngine::$runs );
		$this->assertSame( $workflow_id, FakeWorkflowEngine::$runs[0][0] );
		$this->assertSame( array( 'c' => 3 ), FakeWorkflowEngine::$runs[0][1] );
	}

	public function test_execution_seams_resolve_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Workflow_Dispatcher', WorkflowTriggerProbeSeam::probe_dispatcher() );
			$this->assertSame( 'WP_MCP_AI_Workflow_Engine_V2', WorkflowTriggerProbeSeam::probe_engine() );
		} else {
			$this->assertNull( WorkflowTriggerProbeSeam::probe_dispatcher() );
			$this->assertNull( WorkflowTriggerProbeSeam::probe_engine() );
		}
	}
}
