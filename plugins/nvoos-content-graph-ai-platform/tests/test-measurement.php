<?php
/**
 * Measurement ported-class tests (extraction Wave C).
 *
 * Verifies the extraction port of the Measurement subsystem
 * (src/Measurement/) preserves the public behaviour and data-stability
 * contracts of the base plugin's measurement classes
 * (mcp-ai-wpoos/includes/measurement/).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Measurement\BudgetEnvelope;
use NvoosContentGraphAiPlatform\Measurement\BudgetRegistry;
use NvoosContentGraphAiPlatform\Measurement\ChatTurnMetrics;
use NvoosContentGraphAiPlatform\Measurement\ChatTurnObserver;
use NvoosContentGraphAiPlatform\Measurement\MeasurementRegistry;
use NvoosContentGraphAiPlatform\Measurement\MeasurementService;
use NvoosContentGraphAiPlatform\Measurement\MetricCollector;
use NvoosContentGraphAiPlatform\Measurement\MetricEventStore;
use NvoosContentGraphAiPlatform\Measurement\MetricPersister;
use NvoosContentGraphAiPlatform\Measurement\MetricRetention;
use NvoosContentGraphAiPlatform\Measurement\RewardFunctionRegistry;
use NvoosContentGraphAiPlatform\Measurement\SessionLogObserver;
use NvoosContentGraphAiPlatform\Measurement\SseMetrics;
use NvoosContentGraphAiPlatform\Measurement\VerifierBase;
use NvoosContentGraphAiPlatform\Measurement\VerifierRegistry;

/**
 * @group measurement
 */
class Test_Platform_Measurement extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Reset the ported singletons so each test starts from a clean slate.
		// The base plugin's own singletons (monolith matrix) are untouched.
		MeasurementRegistry::reset_instance();
		MetricCollector::reset_instance();
		MetricEventStore::reset_instance();
		MetricPersister::reset_instance();
		MetricRetention::unschedule();
		ChatTurnObserver::reset_instance();
		SessionLogObserver::reset_instance();
		RewardFunctionRegistry::reset_instance();
		VerifierRegistry::reset_instance();
		BudgetRegistry::reset_instance();
		delete_option( BudgetRegistry::PERSISTENT_OPTION );
	}

	public function tearDown(): void {
		// Drop the shared metric-events table when this file's event-store
		// test created it (same drop/install pattern as the base test).
		$store = MetricEventStore::get_instance();
		if ( $store->table_exists() ) {
			$store->drop();
		}
		MetricEventStore::reset_instance();
		BudgetRegistry::reset_instance();
		delete_option( BudgetRegistry::PERSISTENT_OPTION );
		parent::tearDown();
	}

	public function test_data_stability_constants_match_base(): void {
		// Metric type / direction / privacy vocabulary (data stability).
		$this->assertSame( 'counter', MeasurementRegistry::TYPE_COUNTER );
		$this->assertSame( 'gauge', MeasurementRegistry::TYPE_GAUGE );
		$this->assertSame( 'histogram', MeasurementRegistry::TYPE_HISTOGRAM );
		$this->assertSame( 'rate', MeasurementRegistry::TYPE_RATE );
		$this->assertSame( 'higher_is_better', MeasurementRegistry::DIRECTION_HIGHER_IS_BETTER );
		$this->assertSame( 'lower_is_better', MeasurementRegistry::DIRECTION_LOWER_IS_BETTER );
		$this->assertSame( 'neutral', MeasurementRegistry::DIRECTION_NEUTRAL );
		$this->assertSame( 'public', MeasurementRegistry::PRIVACY_PUBLIC );
		$this->assertSame( 'internal', MeasurementRegistry::PRIVACY_INTERNAL );
		$this->assertSame( 'sensitive', MeasurementRegistry::PRIVACY_SENSITIVE );
		$this->assertSame( 'restricted', MeasurementRegistry::PRIVACY_RESTRICTED );

		// Event store table + schema option (byte-identical to base).
		$this->assertSame( 'mcp_ai_metric_events', MetricEventStore::TABLE_BASE );
		$this->assertSame( 'wp_mcp_ai_metric_events_schema_version', MetricEventStore::SCHEMA_OPTION );
		$this->assertSame( 1, MetricEventStore::SCHEMA_VERSION );
		$this->assertSame( 200, MetricEventStore::MAX_BATCH_ROWS );

		// Retention cron hook.
		$this->assertSame( 'wp_mcp_ai_metric_retention_purge', MetricRetention::CRON_HOOK );

		// Budget option key + scopes.
		$this->assertSame( 'wp_mcp_ai_budget_accumulators', BudgetRegistry::PERSISTENT_OPTION );
		$this->assertSame( 'request', BudgetEnvelope::SCOPE_REQUEST );
		$this->assertSame( 'persistent', BudgetEnvelope::SCOPE_PERSISTENT );

		// Chat-turn + SSE stock metric ids.
		$this->assertSame( 'token_usage.prompt_tokens', ChatTurnMetrics::TOKEN_USAGE_PROMPT );
		$this->assertSame( 'token_usage.completion_tokens', ChatTurnMetrics::TOKEN_USAGE_COMPLETION );
		$this->assertSame( 'token_usage.total_cost_usd', ChatTurnMetrics::TOKEN_USAGE_TOTAL_COST_USD );
		$this->assertSame( 'chat.turn.duration_ms', ChatTurnMetrics::CHAT_TURN_DURATION_MS );
		$this->assertSame( 'chat.turn.count', ChatTurnMetrics::CHAT_TURN_COUNT );
		$this->assertSame( 'chat.turn.error.count', ChatTurnMetrics::CHAT_TURN_ERROR_COUNT );
		$this->assertSame( 'chat.agentic.iterations', ChatTurnMetrics::CHAT_AGENTIC_ITERATIONS );
		$this->assertSame( 'stream.count', SseMetrics::STREAM_COUNT );
		$this->assertSame( 'stream.ttfb_ms', SseMetrics::STREAM_TTFB_MS );
		$this->assertSame( 'stream.chunk_interval_ms', SseMetrics::STREAM_CHUNK_INTERVAL_MS );
		$this->assertSame( 'stream.total_duration_ms', SseMetrics::STREAM_TOTAL_DURATION_MS );
		$this->assertSame( 'stream.chunks.count', SseMetrics::STREAM_CHUNKS_COUNT );
		$this->assertSame( 'stream.cancelled.count', SseMetrics::STREAM_CANCELLED_COUNT );
		$this->assertSame( 'stream.error.count', SseMetrics::STREAM_ERROR_COUNT );
	}

	public function test_metric_registry_register_retrieve_and_guard(): void {
		$registry = MeasurementRegistry::get_instance();

		$ok = $registry->register(
			array(
				'id'           => 'tool.execution.success_rate',
				'label'        => 'Tool Success Rate',
				'type'         => MeasurementRegistry::TYPE_RATE,
				'unit'         => 'ratio',
				'direction'    => MeasurementRegistry::DIRECTION_HIGHER_IS_BETTER,
				'privacy_tier' => MeasurementRegistry::PRIVACY_INTERNAL,
			)
		);
		$this->assertTrue( $ok );
		$this->assertTrue( $registry->has( 'tool.execution.success_rate' ) );

		$def = $registry->get( 'TOOL.EXECUTION.SUCCESS_RATE' );
		$this->assertNotNull( $def );
		$this->assertSame( 'Tool Success Rate', $def['label'] );
		$this->assertSame( 'internal', $def['privacy_tier'] );

		// Duplicate registration is a no-op — first registration wins.
		$this->assertFalse(
			$registry->register(
				array(
					'id'    => 'tool.execution.success_rate',
					'label' => 'Override',
					'type'  => MeasurementRegistry::TYPE_COUNTER,
					'unit'  => 'count',
				)
			)
		);

		// Invalid definitions are rejected.
		$this->assertFalse( $registry->register( array( 'label' => 'No id' ) ) );
		$this->assertFalse(
			$registry->register(
				array(
					'id'    => 'bad type',
					'label' => 'Bad',
					'type'  => 'wat',
					'unit'  => 'count',
				)
			)
		);

		// Goodhart guard: metrics without a counter-metric are surfaced.
		$this->assertContains( 'tool.execution.success_rate', $registry->metrics_without_counter() );
	}

	public function test_metric_registry_register_many_counts(): void {
		$registry = MeasurementRegistry::get_instance();

		$count = $registry->register_many(
			array(
				array(
					'id'    => 'a.count',
					'label' => 'A',
					'type'  => MeasurementRegistry::TYPE_COUNTER,
					'unit'  => 'count',
				),
				'invalid entry',
				array(
					'id'    => 'b.count',
					'label' => 'B',
					'type'  => MeasurementRegistry::TYPE_COUNTER,
					'unit'  => 'count',
				),
			)
		);

		$this->assertSame( 2, $count );
	}

	public function test_metric_collector_records_known_metrics_only(): void {
		$registry = MeasurementRegistry::get_instance();
		$registry->register(
			array(
				'id'    => 'test.counter',
				'label' => 'Test Counter',
				'type'  => MeasurementRegistry::TYPE_COUNTER,
				'unit'  => 'count',
			)
		);

		$collector = MetricCollector::get_instance();

		$this->assertTrue( $collector->record( 'test.counter', 1 ) );
		$this->assertFalse( $collector->record( 'unknown.metric', 1 ) );
		$this->assertFalse( $collector->record( 'test.counter', 'not-a-number' ) );

		$buffered = $collector->buffered();
		$this->assertCount( 1, $buffered );
		$this->assertSame( 'test.counter', $buffered[0]['id'] );
		$this->assertSame( 1.0, $buffered[0]['value'] );
		$this->assertSame( 'counter', $buffered[0]['type'] );
		$this->assertSame( 'internal', $buffered[0]['privacy'] );
	}

	public function test_metric_collector_sampling_gate(): void {
		$registry = MeasurementRegistry::get_instance();
		$registry->register(
			array(
				'id'    => 'test.gauge',
				'label' => 'Test Gauge',
				'type'  => MeasurementRegistry::TYPE_GAUGE,
				'unit'  => 'ms',
			)
		);
		$collector = MetricCollector::get_instance();

		// Full rate keeps the sample.
		$this->assertTrue( $this->invoke_protected( $collector, 'should_sample', 'test.gauge' ) );

		// Zero rate drops the sample.
		$collector->set_sample_rate( 0.0 );
		$this->assertFalse( $this->invoke_protected( $collector, 'should_sample', 'test.gauge' ) );
		$this->assertFalse( $collector->record( 'test.gauge', 5 ) );
	}

	public function test_metric_collector_sanitizes_context(): void {
		$collector = MetricCollector::get_instance();

		$sanitized = $this->invoke_protected(
			$collector,
			'sanitize_context',
			array(
				'assistant_id' => '42',
				'user_id'      => '7',
				'model'        => ' <b>gpt</b>-4o ',
				'tool'         => 'get_posts',
				'session_id'   => ' sess_1 ',
				'attributes'   => array(
					'key'     => ' <i>value</i> ',
					'arr'     => array( 1, 2 ),
					'bad key' => 'x',
				),
				'not-allowed'  => 'dropped',
			)
		);

		$this->assertSame( 42, $sanitized['assistant_id'] );
		$this->assertSame( 7, $sanitized['user_id'] );
		$this->assertSame( 'gpt-4o', $sanitized['model'] );
		$this->assertSame( 'get_posts', $sanitized['tool'] );
		$this->assertSame( 'sess_1', $sanitized['session_id'] );
		$this->assertArrayNotHasKey( 'not-allowed', $sanitized );
		$this->assertSame( 'value', $sanitized['attributes']['key'] );
		$this->assertArrayNotHasKey( 'arr', $sanitized['attributes'] );
	}

	public function test_reward_function_registry_contract(): void {
		$registry = RewardFunctionRegistry::get_instance();

		$definition = array(
			'slug'        => 'test_reward',
			'label'       => 'Test Reward',
			'callback'    => static function ( array $inputs, array $context ): float {
				unset( $inputs, $context );
				return 1.5;
			},
			'output_min'  => 0.0,
			'output_max'  => 1.0,
			'inputs'      => array( 'score' ),
			'anti_gaming' => 'Paired with a counter-metric.',
		);

		$this->assertTrue( $registry->register( $definition ) );
		$this->assertNotNull( $registry->get( 'test_reward' ) );

		// Duplicate slug is rejected.
		$this->assertWPError( $registry->register( $definition ) );

		// The anti-gaming Goodhart guard cannot be bypassed.
		$unsafe = $registry->register(
			array(
				'slug'       => 'unsafe',
				'label'      => 'Unsafe',
				'callback'   => 'strlen',
				'output_min' => 0,
				'output_max' => 10,
				'inputs'     => array(),
			)
		);
		$this->assertWPError( $unsafe );
		$this->assertSame( 'wp_mcp_ai_reward_missing_anti_gaming', $unsafe->get_error_code() );

		// Evaluate clamps to the declared range.
		$this->assertSame( 1.0, $registry->evaluate( 'test_reward', array( 'score' => 1 ), array() ) );

		// Missing required input.
		$missing = $registry->evaluate( 'test_reward', array(), array() );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_reward_missing_input', $missing->get_error_code() );

		// Unknown slug.
		$this->assertWPError( $registry->evaluate( 'nope', array(), array() ) );
	}

	public function test_verifier_registry_contract_and_independence(): void {
		$verifier = new class() extends VerifierBase {
			public function __construct() {
				$this->slug                 = 'test_verifier';
				$this->label                = 'Test Verifier';
				$this->independence_profile = array(
					'disallowed_providers' => array( 'openai' ),
					'disallowed_models'    => array(),
					'disallowed_tools'     => array(),
					'allowed_domains'      => array(),
				);
			}
			public function verify( array $subject, array $context = array() ) {
				unset( $subject, $context );
				return $this->result_pass( 0.75, 0.9, array( 'looks good' ) );
			}
		};

		$registry = VerifierRegistry::get_instance();
		$this->assertTrue( $registry->register( $verifier ) );
		$this->assertSame( $verifier, $registry->get( 'test_verifier' ) );

		// Duplicate registration is a no-op.
		$this->assertFalse( $registry->register( $verifier ) );

		// run() returns the canonical pass result.
		$result = $registry->run( 'test_verifier', array( 'output' => 'x' ), array() );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['passed'] );
		$this->assertSame( 0.75, $result['score'] );

		// Unknown verifier.
		$this->assertWPError( $registry->run( 'nope', array(), array() ) );

		// Verifier's law: sharing the generator's provider is disallowed.
		$independence = $registry->check_independence( $verifier, array( 'provider' => 'openai' ) );
		$this->assertWPError( $independence );
		$this->assertSame( 'wp_mcp_ai_verifier_not_independent', $independence->get_error_code() );

		// Different provider is fine.
		$this->assertTrue( $registry->check_independence( $verifier, array( 'provider' => 'anthropic' ) ) );
	}

	public function test_budget_registry_accumulates_and_signals(): void {
		$registry = BudgetRegistry::get_instance();

		$envelope = $registry->register(
			array(
				'slug'       => 'req_cost',
				'metric_ids' => array( 'platform.cost_usd' ),
				'limit'      => 0.5,
			)
		);
		$this->assertInstanceOf( BudgetEnvelope::class, $envelope );
		$this->assertSame( $envelope, $registry->get( 'req_cost' ) );

		$this->assertSame( 0.0, $registry->get_consumption( 'req_cost' ) );
		$this->assertSame( 0.3, $registry->consume( 'req_cost', 0.3 ) );
		$this->assertSame( 0.3, $registry->get_consumption( 'req_cost' ) );

		$warned   = 0;
		$exceeded = 0;
		add_action(
			'wp_mcp_ai_budget_warned',
			static function () use ( &$warned ): void {
				++$warned;
			}
		);
		add_action(
			'wp_mcp_ai_budget_exceeded',
			static function () use ( &$exceeded ): void {
				++$exceeded;
			}
		);

		$this->assertSame( 0.5, $registry->consume( 'req_cost', 0.2 ) );
		$this->assertSame( 1, $warned );
		$this->assertSame( 1, $exceeded );

		$snapshot = $registry->snapshot();
		$this->assertSame( 'exceeded', $snapshot[0]['state'] );

		// Invalid definition and unknown slugs surface as WP_Error.
		$this->assertWPError( $registry->register( array() ) );
		$this->assertWPError( $registry->consume( 'nope', 1 ) );
	}

	public function test_budget_envelope_rejects_invalid_definitions(): void {
		$this->expectException( \InvalidArgumentException::class );
		new BudgetEnvelope( array( 'slug' => '' ) );
	}

	public function test_metric_event_store_roundtrip(): void {
		// The WP test framework rewrites every CREATE TABLE / DROP TABLE
		// issued through $wpdb to TEMPORARY variants (see
		// suspend_temporary_table_rewrite()). Suspend it so this test
		// exercises the real schema contract install() implements.
		$this->suspend_temporary_table_rewrite();

		try {
			$store = MetricEventStore::get_instance();
			$store->drop();

			$this->assertTrue( $store->install() );
			$this->assertTrue( $store->table_exists() );
			$this->assertSame(
				MetricEventStore::SCHEMA_VERSION,
				(int) get_option( MetricEventStore::SCHEMA_OPTION )
			);

			$now    = time();
			$events = array(
				array(
					'id'        => 'platform.test.count',
					'value'     => 2,
					'type'      => MeasurementRegistry::TYPE_COUNTER,
					'unit'      => 'count',
					'privacy'   => MeasurementRegistry::PRIVACY_INTERNAL,
					'timestamp' => $now - 100,
					'context'   => array( 'tool' => 'get_posts' ),
				),
				// Restricted tier must never be persisted (defensive barrier).
				array(
					'id'        => 'platform.test.count',
					'value'     => 1,
					'type'      => MeasurementRegistry::TYPE_COUNTER,
					'unit'      => 'count',
					'privacy'   => MeasurementRegistry::PRIVACY_RESTRICTED,
					'timestamp' => $now,
					'context'   => array(),
				),
			);

			$this->assertSame( 1, $store->insert_batch( $events ) );

			$rows = $store->query_by_metric( 'platform.test.count', $now - 3600, $now + 3600, 100 );
			$this->assertCount( 1, $rows );
			$this->assertSame( 2.0, $rows[0]['metric_value'] );
			$this->assertSame( array( 'tool' => 'get_posts' ), $rows[0]['context'] );

			$this->assertSame(
				array( MeasurementRegistry::PRIVACY_INTERNAL => 1 ),
				$store->count_by_privacy()
			);

			// Retention purge removes old rows per tier.
			$this->assertSame(
				1,
				$store->purge_older_than( MeasurementRegistry::PRIVACY_INTERNAL, $now + 10 )
			);
			$this->assertSame( 0, $store->total_count() );
		} finally {
			// Drop the real table before re-arming the rewrite so cleanup
			// is not redirected to a TEMPORARY table.
			MetricEventStore::get_instance()->drop();
			$this->restore_temporary_table_rewrite();
		}
	}

	public function test_retention_default_ttls(): void {
		$ttls = MetricRetention::resolve_ttls_days();

		$this->assertSame(
			array(
				MeasurementRegistry::PRIVACY_PUBLIC    => 365,
				MeasurementRegistry::PRIVACY_INTERNAL  => 90,
				MeasurementRegistry::PRIVACY_SENSITIVE => 30,
			),
			$ttls
		);
	}

	public function test_chat_turn_stock_definitions_shape(): void {
		$definitions = ChatTurnMetrics::definitions();

		$this->assertCount( 7, $definitions );
		foreach ( $definitions as $definition ) {
			$this->assertNotEmpty( $definition['counter_metric'] );
			$this->assertSame( 'internal', $definition['privacy_tier'] );
		}

		$sse_definitions = SseMetrics::definitions();
		$this->assertCount( 7, $sse_definitions );
		foreach ( $sse_definitions as $definition ) {
			$this->assertNotEmpty( $definition['counter_metric'] );
		}
	}

	public function test_chat_turn_observer_records_turn_metrics(): void {
		ChatTurnMetrics::register( MeasurementRegistry::get_instance() );

		$observer = ChatTurnObserver::get_instance();
		$observer->on_before(
			'assistant-42',
			array(),
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o',
			)
		);
		$observer->on_after(
			'assistant-42',
			array(
				'choices' => array( array( 'message' => array( 'content' => 'hi' ) ) ),
				'usage'   => array(
					'prompt_tokens'     => 10,
					'completion_tokens' => 5,
				),
			),
			null
		);

		$buffered = MetricCollector::get_instance()->buffered();
		$ids      = array_column( $buffered, 'id' );

		$this->assertContains( ChatTurnMetrics::CHAT_TURN_COUNT, $ids );
		$this->assertContains( ChatTurnMetrics::CHAT_TURN_DURATION_MS, $ids );
		$this->assertContains( ChatTurnMetrics::TOKEN_USAGE_PROMPT, $ids );
		$this->assertContains( ChatTurnMetrics::TOKEN_USAGE_COMPLETION, $ids );
		$this->assertNotContains( ChatTurnMetrics::CHAT_TURN_ERROR_COUNT, $ids );
		$this->assertSame( 0, $observer->depth() );

		// An errored turn records the error counter.
		MetricCollector::get_instance()->clear_buffer();
		$observer->on_before( 'assistant-42', array(), array() );
		$observer->on_after( 'assistant-42', new \WP_Error( 'boom', 'failed' ), null );

		$ids = array_column( MetricCollector::get_instance()->buffered(), 'id' );
		$this->assertContains( ChatTurnMetrics::CHAT_TURN_ERROR_COUNT, $ids );
	}

	public function test_shim_surface_and_bootstrap_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin owns the measurement wiring.' );
		}

		// The shim is loaded by MeasurementService::register() in standalone.
		MeasurementService::instance()->register();

		$this->assertTrue( function_exists( 'wp_mcp_ai_measurement_bootstrap' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_measurement_ensure_capabilities' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_register_reference_verifiers' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_register_reference_rewards' ) );

		// Hook wiring mirrors the base bootstrap file (this test triggers the
		// shim load, so the wiring lives in the current hook globals).
		$this->assertSame( 50, has_action( 'plugins_loaded', 'wp_mcp_ai_measurement_bootstrap' ) );
		$this->assertSame( 5, has_action( 'admin_init', 'wp_mcp_ai_measurement_ensure_capabilities' ) );
		$this->assertSame( 20, has_action( 'wp_mcp_ai_register_verifiers', 'wp_mcp_ai_register_reference_verifiers' ) );
		$this->assertSame( 20, has_action( 'wp_mcp_ai_register_reward_functions', 'wp_mcp_ai_register_reference_rewards' ) );

		// WP_UnitTestCase resets hook globals between tests, so plugins_loaded
		// never re-fires in this process — invoke the bootstrap directly.
		// Suspend the framework's CREATE TABLE → TEMPORARY rewrite first:
		// the bootstrap installs the metric-events table, and temporary
		// tables are invisible to the table_exists() assertion below.
		$this->suspend_temporary_table_rewrite();

		try {
			wp_mcp_ai_measurement_bootstrap();

			// Chat-turn + SSE stock definitions land through the shim's own
			// wp_mcp_ai_register_metrics wiring (priority 20).
			$registry = MeasurementRegistry::get_instance();
			$this->assertTrue( $registry->has( ChatTurnMetrics::CHAT_TURN_COUNT ) );
			$this->assertTrue( $registry->has( SseMetrics::STREAM_COUNT ) );

			// The metric-events table is installed as a bootstrap side effect.
			$this->assertTrue( MetricEventStore::get_instance()->table_exists() );

			// Base-only reference verifiers and rewards are intentionally absent.
			$this->assertNull( VerifierRegistry::get_instance()->get( 'rule_verifier' ) );
			$this->assertSame( array(), RewardFunctionRegistry::get_instance()->all() );

			// The ported budget registry boots too (budgets/ is part of the port).
			$this->assertNotNull( BudgetRegistry::get_instance() );
		} finally {
			// Drop the real table before re-arming the rewrite so cleanup
			// is not redirected to a TEMPORARY table.
			MetricEventStore::get_instance()->drop();
			$this->restore_temporary_table_rewrite();
		}
	}

	public function test_service_register_is_safe_in_both_modes(): void {
		// Monolith mode: no-op after the admin UI block (base owns wiring).
		// Standalone mode: loads the shim once; require_once keeps it idempotent.
		MeasurementService::instance()->register();
		MeasurementService::instance()->register();

		$this->assertTrue( true );
	}

	/**
	 * Invoke a protected method on an object for contract testing.
	 *
	 * @param object $instance Object instance.
	 * @param string $method   Method name.
	 * @param mixed  $arg      Optional single method argument.
	 * @return mixed Method result.
	 */
	private function invoke_protected( $instance, $method, $arg = null ) {
		$reflection = new \ReflectionMethod( $instance, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( $instance, $arg );
	}

	/**
	 * Suspend the WP test framework's CREATE/DROP TABLE → TEMPORARY rewrite.
	 *
	 * WP_UnitTestCase::start_transaction() rewrites every `CREATE TABLE` and
	 * `DROP TABLE` issued through $wpdb->query() to operate on TEMPORARY
	 * tables so tests cannot touch the real database. Temporary tables are
	 * invisible to `SHOW TABLES`, so MetricEventStore::table_exists() can
	 * never observe them. The event-store tests in this file exercise the
	 * real schema contract, so they opt out for their duration and clean up
	 * in a finally block.
	 *
	 * Also drops any TEMPORARY shadow table left on this connection: MySQL
	 * prefers the temporary table over a real one of the same name, which
	 * would silently divert the subsequent real CREATE TABLE.
	 *
	 * @return void
	 */
	private function suspend_temporary_table_rewrite(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$table_name = MetricEventStore::get_instance()->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness shadow cleanup on a plugin-owned table; the name comes from a class constant.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );
	}

	/**
	 * Re-arm the framework's TEMPORARY-table rewrite for subsequent tests.
	 *
	 * @return void
	 */
	private function restore_temporary_table_rewrite(): void {
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}
}
