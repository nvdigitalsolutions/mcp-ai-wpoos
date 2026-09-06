<?php
/**
 * OOS shadow engine port tests (Wave E6, sub-cluster 1).
 *
 * Characterization suite for the `NvoosContentGraphAi\Engine` OOS
 * shadow surface: byte-identical store constants, the subscriber gate
 * chain (shadow disabled / engine flag / non-REST payload / sampling),
 * the parity record shape with the write-suppression counter, the
 * contained-failure path, the capped newest-first store lifecycle, the
 * per-mode flag helpers (option + filter surface), the write-class
 * classifier, the `tools/execute` suppression waterfall, the per-mode
 * orchestrator/assistant-config seams, and the parity CLI aggregate /
 * diff data methods. Runs in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Cli\OosParityCommand;
use NvoosContentGraphAi\Engine\OosEngineFlags;
use NvoosContentGraphAi\Engine\OosShadowRunner;
use NvoosContentGraphAi\Engine\OosShadowSuppression;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam/stub fixtures share this file with its test case.

/**
 * Seam exposing the per-mode orchestrator/assistant-config resolution and
 * substituting a fake orchestrator for full-run characterization.
 */
class OosShadowRunnerSeam extends OosShadowRunner {

	/**
	 * Fake orchestrator substituted for the real engine.
	 *
	 * @var object|null
	 */
	private static $fake_orchestrator = null;

	/**
	 * Force the orchestrator seam to return null.
	 *
	 * @var bool
	 */
	private static $force_null_orchestrator = false;

	/**
	 * Substitute a fake orchestrator.
	 *
	 * @param object|null $fake Fake orchestrator (null restores the real resolution).
	 * @return void
	 */
	public static function set_fake_orchestrator( $fake ): void {
		self::$fake_orchestrator = $fake;
	}

	/**
	 * Force the seam to report no orchestrator (unavailable mode).
	 *
	 * @param bool $force Force null.
	 * @return void
	 */
	public static function force_null_orchestrator( bool $force ): void {
		self::$force_null_orchestrator = $force;
	}

	/**
	 * {@inheritdoc}
	 */
	protected static function orchestrator() {
		if ( self::$force_null_orchestrator ) {
			return null;
		}

		if ( null !== self::$fake_orchestrator ) {
			return self::$fake_orchestrator;
		}

		return parent::orchestrator();
	}

	/**
	 * Expose the real per-mode orchestrator resolution.
	 *
	 * @return object|null
	 */
	public static function seam_orchestrator() {
		return parent::orchestrator();
	}

	/**
	 * Expose the per-mode assistant-config resolution.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	public static function seam_assistant_configuration( int $assistant_id ): array {
		return parent::assistant_configuration( $assistant_id );
	}
}

/**
 * Fake orchestrator with the byte-identical named-argument handleChat surface.
 */
class FakeOosOrchestrator {

	/**
	 * Result returned by handleChat.
	 *
	 * @var array
	 */
	public $result = array();

	/**
	 * Optional exception thrown by handleChat.
	 *
	 * @var \Throwable|null
	 */
	public $exception = null;

	/**
	 * Options received by the last handleChat call.
	 *
	 * @var array
	 */
	public $last_options = array();

	/**
	 * Record one handleChat invocation.
	 *
	 * @param array                                           $messages        Messages.
	 * @param array                                           $assistantConfig Assistant config.
	 * @param int                                             $userId          User ID.
	 * @param int                                             $assistantId     Assistant ID.
	 * @param array                                           $options         Options.
	 * @param \Nvoos\Core\Domain\ValueObject\CancellationToken|null $cancellation Cancellation token.
	 * @return array
	 * @throws \Throwable When configured.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Named-argument parity with ChatOrchestrator::handleChat.
	public function handleChat(
		array $messages,
		array $assistantConfig,
		int $userId = 0,
		int $assistantId = 0,
		array $options = array(),
		?\Nvoos\Core\Domain\ValueObject\CancellationToken $cancellation = null,
	): array {
		$this->last_options = $options;

		if ( null !== $this->exception ) {
			throw $this->exception;
		}

		return $this->result;
	}
}

/**
 * Tool stub base implementing the core ToolInterface.
 */
abstract class OosToolStubBase implements \Nvoos\Core\Domain\Contract\ToolInterface {

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'stub_tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getName(): string {
		return 'Stub Tool';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDescription(): string {
		return 'Test stub.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getParametersSchema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getRequiredCapability(): string {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		return 'executed';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCapabilityFlags(): array {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getToolRules(): array {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getDataContract(): array {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFlowStages(): array {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitizeForLlm( mixed $result ): string {
		return (string) $result;
	}
}

/**
 * Write-class tool stub (implements the write-class marker).
 */
class WriteClassToolStub extends OosToolStubBase implements \Nvoos\Core\Domain\Contract\ToolWriteClassInterface {

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'write_stub';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isWriteClass(): bool {
		return true;
	}
}

/**
 * Read-capability tool stub.
 */
class ReadToolStub extends OosToolStubBase {

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'read_stub';
	}
}

/**
 * Edit-capability tool stub (write-class by the capability heuristic).
 */
class EditToolStub extends OosToolStubBase {

	/**
	 * {@inheritdoc}
	 */
	public function getSlug(): string {
		return 'edit_stub';
	}

	/**
	 * {@inheritdoc}
	 */
	public function getRequiredCapability(): string {
		return 'edit_posts';
	}
}

/**
 * OOS shadow engine test suite.
 */
class Test_Oos_Shadow_Runner extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		OosShadowRunnerSeam::set_fake_orchestrator( null );
		OosShadowRunnerSeam::force_null_orchestrator( false );
	}

	public function tearDown(): void {
		\delete_option( OosShadowRunner::STORE_OPTION );
		\delete_option( 'wp_mcp_ai_settings' );
		unset( $_GET['engine'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test cleanup.
		unset( $_SERVER['HTTP_X_WP_MCP_AI_ENGINE'] );
		OosShadowRunnerSeam::set_fake_orchestrator( null );
		OosShadowRunnerSeam::force_null_orchestrator( false );

		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		parent::tearDown();
	}

	/**
	 * Enable shadow mode with a full sampling rate.
	 *
	 * @return void
	 */
	private function enable_shadow(): void {
		\update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_oos_shadow'      => true,
				'oos_shadow_sample_rate' => 1.0,
			)
		);
	}

	/**
	 * Build a REST chat request payload.
	 *
	 * @return \WP_REST_Request
	 */
	private function rest_request(): \WP_REST_Request {
		return new \WP_REST_Request( 'POST', '/wp-json/mcp-ai/v1/chat' );
	}

	// ─── Constants + registration ──────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_oos_shadow_runs', OosShadowRunner::STORE_OPTION );
		$this->assertSame( 100, OosShadowRunner::STORE_MAX );
	}

	public function test_register_wires_the_before_chat_subscriber(): void {
		OosShadowRunner::register();
		OosShadowRunner::register(); // Idempotent.

		$this->assertNotFalse( \has_action( 'wp_mcp_ai_before_chat_request', array( OosShadowRunner::class, 'maybe_run' ) ) );
	}

	// ─── Gate chain ────────────────────────────────────────────────

	public function test_maybe_run_noop_when_shadow_disabled(): void {
		OosShadowRunnerSeam::maybe_run(
			7,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(),
			$this->rest_request()
		);

		$this->assertSame( array(), OosShadowRunner::get_runs() );
	}

	public function test_maybe_run_noop_when_engine_enabled(): void {
		\update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_oos_shadow'      => true,
				'enable_oos_engine'      => true,
				'oos_shadow_sample_rate' => 1.0,
			)
		);

		OosShadowRunnerSeam::maybe_run(
			7,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(),
			$this->rest_request()
		);

		$this->assertSame( array(), OosShadowRunner::get_runs() );
	}

	public function test_maybe_run_noop_for_non_rest_request(): void {
		$this->enable_shadow();

		OosShadowRunnerSeam::maybe_run(
			7,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(),
			new \stdClass()
		);

		$this->assertSame( array(), OosShadowRunner::get_runs() );
	}

	public function test_maybe_run_noop_when_sampling_disabled(): void {
		\update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_oos_shadow'      => true,
				'oos_shadow_sample_rate' => 0.0,
			)
		);

		OosShadowRunnerSeam::maybe_run(
			7,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(),
			$this->rest_request()
		);

		$this->assertSame( array(), OosShadowRunner::get_runs() );
	}

	public function test_maybe_run_noop_for_non_array_payloads(): void {
		$this->enable_shadow();

		OosShadowRunnerSeam::maybe_run( 7, 'not-an-array', array(), $this->rest_request() );

		$this->assertSame( array(), OosShadowRunner::get_runs() );
	}

	// ─── Full shadow run ───────────────────────────────────────────

	public function test_maybe_run_records_parity_run(): void {
		$this->enable_shadow();

		$fake         = new FakeOosOrchestrator();
		$fake->result = array(
			'response'      => array( 'content' => 'shadow response' ),
			'tool_results'  => array(
				array( 'content' => 'read ok' ),
				array( 'content' => 'Error: provider exploded' ),
				array( 'content' => '(shadow: write-class tool suppressed)' ),
			),
			'iterations'    => 3,
			'cancelled'     => false,
			'cancel_reason' => '',
			'cost'          => array(
				'cost_usd'          => 0.0012,
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
			),
		);
		OosShadowRunnerSeam::set_fake_orchestrator( $fake );

		OosShadowRunnerSeam::maybe_run(
			7,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array( 'stream' => true ),
			$this->rest_request()
		);

		$runs = OosShadowRunner::get_runs();
		$this->assertCount( 1, $runs );

		$record = $runs[0];
		$this->assertStringStartsWith( 'oos_shadow_', $record['run_id'] );
		$this->assertSame( 7, $record['assistant_id'] );
		$this->assertSame( 3, $record['iterations'] );
		$this->assertFalse( $record['cancelled'] );
		$this->assertSame( 3, $record['tool_calls'] );
		$this->assertSame( 1, $record['tool_errors'] );
		$this->assertSame( 1, $record['suppressed'] );
		$this->assertSame( 0.0012, $record['cost_usd'] );
		$this->assertSame( 10, $record['prompt_tokens'] );
		$this->assertSame( 5, $record['completion_tokens'] );
		$this->assertTrue( $record['has_response'] );
		$this->assertTrue( $record['sampled'] );

		// The shadow run must never stream and must carry the shadow flag.
		$this->assertTrue( $fake->last_options['shadow_mode'] );
		$this->assertArrayNotHasKey( 'stream', $fake->last_options );
	}

	public function test_maybe_run_contains_orchestrator_failures(): void {
		$this->enable_shadow();

		$fake            = new FakeOosOrchestrator();
		$fake->exception = new \RuntimeException( 'engine exploded' );
		OosShadowRunnerSeam::set_fake_orchestrator( $fake );

		OosShadowRunnerSeam::maybe_run(
			7,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(),
			$this->rest_request()
		);

		$runs = OosShadowRunner::get_runs();
		$this->assertCount( 1, $runs );
		$this->assertSame( 7, $runs[0]['assistant_id'] );
		$this->assertTrue( $runs[0]['sampled'] );
		$this->assertStringContainsString( 'RuntimeException: engine exploded', $runs[0]['error'] );
	}

	public function test_maybe_run_contains_unavailable_orchestrator(): void {
		$this->enable_shadow();
		OosShadowRunnerSeam::force_null_orchestrator( true );

		OosShadowRunnerSeam::maybe_run(
			7,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(),
			$this->rest_request()
		);

		$runs = OosShadowRunner::get_runs();
		$this->assertCount( 1, $runs );
		$this->assertTrue( $runs[0]['sampled'] );
		$this->assertStringContainsString( 'unavailable in this install mode', $runs[0]['error'] );
	}

	// ─── Store lifecycle ───────────────────────────────────────────

	public function test_store_lifecycle_newest_first_and_capped(): void {
		$this->enable_shadow();

		$fake         = new FakeOosOrchestrator();
		$fake->result = array(
			'response'      => array(),
			'tool_results'  => array(),
			'iterations'    => 0,
			'cancelled'     => false,
			'cancel_reason' => '',
			'cost'          => array(),
		);
		OosShadowRunnerSeam::set_fake_orchestrator( $fake );

		// Seed the store one under the cap, then run once to cross it.
		$seeded = array();
		for ( $i = 0; $i < OosShadowRunner::STORE_MAX; $i++ ) {
			$seeded[] = array(
				'run_id'    => 'seed_' . $i,
				'timestamp' => $i,
			);
		}
		\update_option( OosShadowRunner::STORE_OPTION, $seeded, false );

		OosShadowRunnerSeam::maybe_run(
			3,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			),
			array(),
			$this->rest_request()
		);

		$runs = OosShadowRunner::get_runs( OosShadowRunner::STORE_MAX );
		$this->assertCount( OosShadowRunner::STORE_MAX, $runs );

		// Newest first: the fresh shadow run leads, the oldest seed dropped.
		$this->assertStringStartsWith( 'oos_shadow_', $runs[0]['run_id'] );
		$ids = \wp_list_pluck( $runs, 'run_id' );
		$this->assertNotContains( 'seed_0', $ids );
		$this->assertContains( 'seed_' . ( OosShadowRunner::STORE_MAX - 1 ), $ids );

		// get_run / clear_runs.
		$this->assertSame( $runs[0]['run_id'], OosShadowRunner::get_run( $runs[0]['run_id'] )['run_id'] );
		$this->assertNull( OosShadowRunner::get_run( 'seed_0' ) );

		OosShadowRunner::clear_runs();
		$this->assertSame( array(), OosShadowRunner::get_runs() );
	}

	// ─── Flag helpers ──────────────────────────────────────────────

	public function test_flag_helpers_read_option_and_filters(): void {
		$this->assertFalse( OosEngineFlags::shadow_enabled() );

		\update_option( 'wp_mcp_ai_settings', array( 'enable_oos_shadow' => true ) );
		$this->assertTrue( OosEngineFlags::shadow_enabled() );

		// The option short-circuits ahead of the filter (byte-identical);
		// the filter decides only when the option is off.
		\update_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( OosEngineFlags::shadow_enabled() );
		\add_filter( 'wp_mcp_ai_oos_shadow_enabled', '__return_true' );
		$this->assertTrue( OosEngineFlags::shadow_enabled() );
		\remove_filter( 'wp_mcp_ai_oos_shadow_enabled', '__return_true' );

		// Sampling rate clamps to 0.0–1.0.
		\update_option( 'wp_mcp_ai_settings', array( 'oos_shadow_sample_rate' => 5.0 ) );
		$this->assertSame( 1.0, OosEngineFlags::shadow_sample_rate() );
		\update_option( 'wp_mcp_ai_settings', array( 'oos_shadow_sample_rate' => -1.0 ) );
		$this->assertSame( 0.0, OosEngineFlags::shadow_sample_rate() );

		// Timeout filter.
		$this->assertSame( 30, OosEngineFlags::shadow_timeout_seconds() );
		\add_filter(
			'wp_mcp_ai_oos_shadow_timeout_seconds',
			static function (): int {
				return 7;
			}
		);
		$this->assertSame( 7, OosEngineFlags::shadow_timeout_seconds() );
		\remove_all_filters( 'wp_mcp_ai_oos_shadow_timeout_seconds' );
	}

	public function test_engine_enabled_probes(): void {
		\update_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( OosEngineFlags::engine_enabled() );

		\update_option( 'wp_mcp_ai_settings', array( 'enable_oos_engine' => true ) );
		$this->assertTrue( OosEngineFlags::engine_enabled() );

		\update_option( 'wp_mcp_ai_settings', array() );
		$_GET['engine'] = 'oos'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Feature-flag probe test.
		$this->assertTrue( OosEngineFlags::engine_enabled() );
		unset( $_GET['engine'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test cleanup.

		$_SERVER['HTTP_X_WP_MCP_AI_ENGINE'] = 'oos';
		$this->assertTrue( OosEngineFlags::engine_enabled() );
		unset( $_SERVER['HTTP_X_WP_MCP_AI_ENGINE'] );

		$this->assertFalse( OosEngineFlags::engine_enabled() );
	}

	public function test_tool_write_class_classifier(): void {
		$this->assertTrue( OosEngineFlags::tool_is_write_class( new WriteClassToolStub() ) );
		$this->assertFalse( OosEngineFlags::tool_is_write_class( new ReadToolStub() ) );
		$this->assertTrue( OosEngineFlags::tool_is_write_class( new EditToolStub() ) );
		$this->assertFalse( OosEngineFlags::tool_is_write_class( new \stdClass() ) );
	}

	// ─── Suppression waterfall ─────────────────────────────────────

	public function test_suppression_waterfall_short_circuits_write_tools(): void {
		$events   = new \Nvoos\WordPress\Adapter\EventDispatcher();
		$errors   = new \Nvoos\WordPress\Adapter\ErrorFactory();
		$registry = new \Nvoos\Core\Application\Tool\ToolRegistry( $events, $errors );
		$registry->register( new WriteClassToolStub() );
		$registry->register( new ReadToolStub() );

		OosShadowSuppression::wire( $events, $registry );

		$final = static function ( object $event ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Waterfall terminal body ignores the event.
			return 'executed';
		};

		// Write-class tool in shadow mode → synthetic suppression result.
		$suppressed = $events->waterfall(
			'tools/execute',
			new \Nvoos\Core\Domain\Event\ToolsExecute( 'write_stub', array(), array( 'shadow_mode' => true ) ),
			$final
		);
		$this->assertSame( '(shadow: write-class tool suppressed)', $suppressed['message'] );
		$this->assertTrue( $suppressed['data']['shadow_suppressed'] );

		// Read tool in shadow mode → executes live.
		$this->assertSame(
			'executed',
			$events->waterfall(
				'tools/execute',
				new \Nvoos\Core\Domain\Event\ToolsExecute( 'read_stub', array(), array( 'shadow_mode' => true ) ),
				$final
			)
		);

		// Write-class tool outside shadow mode → executes live.
		$this->assertSame(
			'executed',
			$events->waterfall(
				'tools/execute',
				new \Nvoos\Core\Domain\Event\ToolsExecute( 'write_stub', array(), array() ),
				$final
			)
		);
	}

	// ─── Per-mode seams ────────────────────────────────────────────

	public function test_seams_resolve_per_install_mode(): void {
		$orchestrator = OosShadowRunnerSeam::seam_orchestrator();
		$this->assertNotNull( $orchestrator );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertInstanceOf( \Nvoos\Core\Application\Chat\ChatOrchestrator::class, $orchestrator );
		} else {
			$this->assertSame( \NvoosContentGraphAi\CoreBridge::instance()->chat, $orchestrator );
		}

		$this->assertIsArray( OosShadowRunnerSeam::seam_assistant_configuration( 999999 ) );
	}

	// ─── CLI parity data methods ───────────────────────────────────

	public function test_parity_aggregate_and_diff_rows(): void {
		\update_option(
			OosShadowRunner::STORE_OPTION,
			array(
				array(
					'run_id'            => 'run-1',
					'timestamp'         => 1,
					'error'             => 'RuntimeException: boom',
					'cancelled'         => false,
					'has_response'      => false,
					'tool_calls'        => 0,
					'tool_errors'       => 0,
					'suppressed'        => 0,
					'duration_ms'       => 100,
					'cost_usd'          => 0.0,
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
				),
				array(
					'run_id'            => 'run-2',
					'timestamp'         => 2,
					'cancelled'         => true,
					'has_response'      => true,
					'tool_calls'        => 4,
					'tool_errors'       => 2,
					'suppressed'        => 1,
					'duration_ms'       => 300,
					'cost_usd'          => 0.01,
					'prompt_tokens'     => 20,
					'completion_tokens' => 8,
				),
			),
			false
		);

		$totals = OosParityCommand::aggregate( 25 );

		$this->assertSame( 2, $totals['runs'] );
		$this->assertSame( 1, $totals['errors'] );
		$this->assertSame( 1, $totals['cancelled'] );
		$this->assertSame( 1, $totals['no_response'] );
		$this->assertSame( 4, $totals['tool_calls'] );
		$this->assertSame( 2, $totals['tool_errors'] );
		$this->assertSame( 1, $totals['suppressed'] );
		$this->assertSame( 400, $totals['duration_ms'] );
		$this->assertSame( 200, $totals['avg_duration_ms'] );
		$this->assertSame( 50.0, $totals['error_rate'] );
		$this->assertSame( 50.0, $totals['tool_error_rate'] );
		$this->assertSame( 0.01, $totals['cost_usd'] );

		$rows = OosParityCommand::diff_rows(
			array(
				'run_id' => 'run-2',
				'cost'   => array( 'cost_usd' => 0.01 ),
				'ok'     => 1,
			)
		);
		$this->assertSame(
			array(
				array(
					'field' => 'run_id',
					'value' => 'run-2',
				),
				array(
					'field' => 'cost',
					'value' => '{"cost_usd":0.01}',
				),
				array(
					'field' => 'ok',
					'value' => '1',
				),
			),
			$rows
		);
	}
}
