<?php
/**
 * Workflow dispatcher port tests (Wave E1, sub-cluster 2).
 *
 * Characterization suite for the ported `Dispatcher`: byte-identical
 * `wp_mcp_ai_workflow_executor` filter pass-through (array + WP_Error +
 * all four arguments), the default-executor fallback chain with fake
 * engines (enabled/disabled), the `no_workflow_executor` degradation,
 * the per-mode engine seam, and the trigger hand-off integration
 * through the real filter in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Workflows\Dispatcher;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixtures share this file with its test case.

/**
 * Fake engine reporting enabled + recording execute() calls.
 */
class FakeEnabledEngine {

	/**
	 * Recorded execute calls.
	 *
	 * @var array
	 */
	public static $calls = array();

	/**
	 * Enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return true;
	}

	/**
	 * Record the execution.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $input       Runtime input.
	 * @param array $context     Execution context.
	 * @return array Result envelope.
	 */
	public static function execute( $workflow_id, $input = array(), $context = array() ): array {
		self::$calls[] = array( $workflow_id, $input, $context );

		return array(
			'success' => true,
			'run_id'  => 42,
			'message' => 'ran',
		);
	}
}

/**
 * Fake engine reporting disabled.
 */
class FakeDisabledEngine {

	/**
	 * Disabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return false;
	}

	/**
	 * Must never run.
	 *
	 * @param int   $workflow_id Workflow ID.
	 * @param array $input       Runtime input.
	 * @param array $context     Execution context.
	 * @return array Result envelope.
	 */
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Signature must match the engine contract; the disabled engine never runs.
	public static function execute( $workflow_id, $input = array(), $context = array() ): array {
		return array( 'success' => false );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}

/**
 * Seam forcing the enabled fake engine.
 */
class DispatcherEnabledSeam extends Dispatcher {

	/**
	 * The fake engine.
	 *
	 * @return string
	 */
	protected static function engine_class(): ?string {
		return FakeEnabledEngine::class;
	}
}

/**
 * Seam forcing the disabled fake engine.
 */
class DispatcherDisabledSeam extends Dispatcher {

	/**
	 * The disabled fake engine.
	 *
	 * @return string
	 */
	protected static function engine_class(): ?string {
		return FakeDisabledEngine::class;
	}
}

/**
 * Seam forcing no engine.
 */
class DispatcherNoEngineSeam extends Dispatcher {

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
 * Seam exposing the per-mode engine resolution without overrides.
 */
class DispatcherProbeSeam extends Dispatcher {

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
class Test_Dispatcher extends \WP_UnitTestCase {

	/**
	 * Filter-argument capture.
	 *
	 * @var array|null
	 */
	private $captured = null;

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_workflow_executor' );
		parent::tearDown();
	}

	// ─── Filter pass-through ──────────────────────────────────────────

	public function test_filter_owning_result_passes_through(): void {
		$owned = array(
			'success' => true,
			'run_id'  => 'pro-run-9',
			'message' => 'handled by pro',
		);

		add_filter(
			'wp_mcp_ai_workflow_executor',
			function ( $result, $workflow_id, $input, $context ) use ( $owned ) {
				$this->captured = array( $result, $workflow_id, $input, $context );
				return $owned;
			},
			10,
			4
		);

		$result = DispatcherNoEngineSeam::dispatch( 'string-slug', array( 'a' => 1 ), array( 'ctx' => 2 ) );

		$this->assertSame( $owned, $result );
		$this->assertNull( $this->captured[0] );
		$this->assertSame( 'string-slug', $this->captured[1] );
		$this->assertSame( array( 'a' => 1 ), $this->captured[2] );
		$this->assertSame( array( 'ctx' => 2 ), $this->captured[3] );
	}

	public function test_filter_wp_error_passes_through(): void {
		$error = new \WP_Error( 'executor_rejected', 'No thanks.' );

		add_filter(
			'wp_mcp_ai_workflow_executor',
			function () use ( $error ) {
				return $error;
			}
		);

		$this->assertSame( $error, DispatcherNoEngineSeam::dispatch( 7 ) );
	}

	// ─── Default executor fallback ────────────────────────────────────

	public function test_default_executor_runs_when_enabled(): void {
		FakeEnabledEngine::$calls = array();

		$result = DispatcherEnabledSeam::dispatch( '12', array( 'x' => 'y' ), array( 'source' => 'test' ) );

		$this->assertSame(
			array(
				'success' => true,
				'run_id'  => 42,
				'message' => 'ran',
			),
			$result
		);
		$this->assertCount( 1, FakeEnabledEngine::$calls );
		$this->assertSame( 12, FakeEnabledEngine::$calls[0][0] );
		$this->assertSame( array( 'x' => 'y' ), FakeEnabledEngine::$calls[0][1] );
		$this->assertSame( array( 'source' => 'test' ), FakeEnabledEngine::$calls[0][2] );
	}

	public function test_disabled_engine_yields_no_executor_error(): void {
		$result = DispatcherDisabledSeam::dispatch( 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'no_workflow_executor', $result->get_error_code() );
	}

	public function test_missing_engine_yields_no_executor_error(): void {
		$result = DispatcherNoEngineSeam::dispatch( 5 );

		$this->assertWPError( $result );
		$this->assertSame( 'no_workflow_executor', $result->get_error_code() );
	}

	// ─── Per-mode seam ────────────────────────────────────────────────

	public function test_engine_seam_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Workflow_Engine_V2', DispatcherProbeSeam::probe_engine() );
		} else {
			$this->assertSame( 'NvoosContentGraphAiPlatform\Workflows\WorkflowEngine', DispatcherProbeSeam::probe_engine() );
		}
	}

	// ─── Trigger hand-off integration (real filter, both matrices) ────

	public function test_trigger_handoff_reaches_the_executor_filter(): void {
		// The trigger/workflow CPT ports ship in the E1 CPT sub-cluster PR —
		// this integration activates once they merge.
		if ( ! class_exists( 'NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt' ) ) {
			$this->markTestSkipped( 'Workflow trigger CPT port not merged yet — hand-off integration pending.' );
		}

		\NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt::register_cpt();
		\NvoosContentGraphAiPlatform\Workflows\WorkflowCpt::register_cpt();

		$workflow_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_workflow',
				'post_status' => 'publish',
			)
		);
		$trigger_id  = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_trigger',
				'post_title'  => 'Hand-off trigger',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $trigger_id, '_wp_mcp_ai_trigger_type', 'file_upload' );
		update_post_meta( $trigger_id, '_wp_mcp_ai_trigger_workflow_id', $workflow_id );
		update_post_meta( $trigger_id, '_wp_mcp_ai_trigger_enabled', true );

		$handoff = null;
		add_filter(
			'wp_mcp_ai_workflow_executor',
			function ( $result, $wf_id, $input, $context ) use ( &$handoff ) {
				$handoff = array( $wf_id, $input, $context );

				return array(
					'success' => true,
					'run_id'  => 'taken',
					'message' => 'executor took ownership',
				);
			},
			10,
			4
		);

		\NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt::fire_trigger(
			$trigger_id,
			$workflow_id,
			array( 'attachment_id' => 77 )
		);

		// Monolith: the base dispatcher receives the hand-off; standalone:
		// the ported Dispatcher does — the filter contract is identical.
		$this->assertSame( $workflow_id, $handoff[0] );
		$this->assertSame( array( 'attachment_id' => 77 ), $handoff[1] );
		$this->assertSame(
			array(
				'source'     => 'trigger',
				'trigger_id' => $trigger_id,
			),
			$handoff[2]
		);

		// The fire path stamped the trigger.
		$this->assertGreaterThan( 0, (int) get_post_meta( $trigger_id, '_wp_mcp_ai_trigger_last_fired_at', true ) );
	}
}
