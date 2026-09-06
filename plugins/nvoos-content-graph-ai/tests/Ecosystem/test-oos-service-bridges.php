<?php
/**
 * OOS service-bridge + flag-helper port tests (Wave E6, sub-cluster 6).
 *
 * Characterization suite for the ported OOS wave-1 service bridges
 * (`NvoosContentGraphAi\Engine\OosServiceBridges` — the semantic
 * compressor, data budget tracker, Erlang C, error tracking, and cost
 * tracking resolvers) and the remaining oos-bridge flag helpers on
 * `OosEngineFlags` (session log/telemetry, canary). Monolith matrix
 * exercises the base's legacy wrappers through the deferred global
 * functions (including the data-budget dead-branch quirk); standalone
 * resolves the real `nvoos/core` + `nvoos/wordpress-adapter`
 * implementations. Runs in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\OosEngineFlags;
use NvoosContentGraphAi\Engine\OosServiceBridges;

/**
 * @group oos
 */
class Test_Oos_Service_Bridges extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_option( 'wp_mcp_ai_settings' );
	}

	public function tearDown(): void {
		\delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	// ─── Flag helpers ────────────────────────────────────────────

	public function test_session_log_flag_defaults_off_and_honors_setting_and_filter(): void {
		$this->assertFalse( OosEngineFlags::session_log_enabled() );

		\update_option( 'wp_mcp_ai_settings', array( 'enable_oos_session_log' => true ) );
		$this->assertTrue( OosEngineFlags::session_log_enabled() );
		\delete_option( 'wp_mcp_ai_settings' );

		\add_filter( 'wp_mcp_ai_enable_session_log', '__return_true' );
		$this->assertTrue( OosEngineFlags::session_log_enabled() );
		\remove_filter( 'wp_mcp_ai_enable_session_log', '__return_true' );
	}

	public function test_session_telemetry_flag_defaults_off_and_honors_setting_and_filter(): void {
		$this->assertFalse( OosEngineFlags::session_telemetry_enabled() );

		\update_option( 'wp_mcp_ai_settings', array( 'enable_oos_session_telemetry' => true ) );
		$this->assertTrue( OosEngineFlags::session_telemetry_enabled() );
		\delete_option( 'wp_mcp_ai_settings' );

		\add_filter( 'wp_mcp_ai_enable_session_telemetry', '__return_true' );
		$this->assertTrue( OosEngineFlags::session_telemetry_enabled() );
		\remove_filter( 'wp_mcp_ai_enable_session_telemetry', '__return_true' );
	}

	public function test_canary_flag_requires_filter_and_assistant_meta(): void {
		// Without the canary gate filter: always false.
		$this->assertFalse( OosEngineFlags::canary_enabled() );

		$assistant_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertFalse( OosEngineFlags::canary_enabled() );

		// With the gate + matching post meta (via a request): true.
		\add_filter( 'wp_mcp_ai_oos_canary', '__return_true' );
		$this->assertFalse( OosEngineFlags::canary_enabled() ); // No assistant yet.

		\update_post_meta( $assistant_id, '_wp_mcp_ai_engine', 'oos' );
		$request = new \WP_REST_Request();
		$request->set_param( 'assistant_id', $assistant_id );
		$this->assertTrue( OosEngineFlags::canary_enabled( $request ) );

		// Non-oos meta: false.
		\update_post_meta( $assistant_id, '_wp_mcp_ai_engine', 'legacy' );
		$this->assertFalse( OosEngineFlags::canary_enabled( $request ) );

		\remove_filter( 'wp_mcp_ai_oos_canary', '__return_true' );
	}

	// ─── Semantic compressor ─────────────────────────────────────

	public function test_semantic_compressor_resolves_interface_instance(): void {
		$compressor = OosServiceBridges::semantic_compressor();

		$this->assertInstanceOf( \Nvoos\Core\Domain\Contract\SemanticCompressorInterface::class, $compressor );
		$this->assertTrue( $compressor->isValidAggressiveness( 1 ) );
		$this->assertTrue( $compressor->isValidAggressiveness( 3 ) );
		$this->assertFalse( $compressor->isValidAggressiveness( 9 ) );

		$tokens = $compressor->estimateTokens( 'Some text to estimate.' );
		$this->assertIsInt( $tokens );
		$this->assertGreaterThanOrEqual( 0, $tokens );
	}

	public function test_semantic_compressor_compress_shape(): void {
		$compressor = OosServiceBridges::semantic_compressor();
		$result     = $compressor->compress( 'The quick brown fox jumps over the lazy dog. Repeated filler here.', 2 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'compressed', $result );
		$this->assertArrayHasKey( 'original_bytes', $result );
		$this->assertArrayHasKey( 'compressed_bytes', $result );
		$this->assertArrayHasKey( 'compression_ratio', $result );
		$this->assertArrayHasKey( 'tokens_estimate', $result );
		$this->assertIsString( $result['compressed'] );
		$this->assertGreaterThan( 0, $result['original_bytes'] );
	}

	public function test_semantic_compressor_is_cached_singleton(): void {
		$this->assertSame( OosServiceBridges::semantic_compressor(), OosServiceBridges::semantic_compressor() );
	}

	// ─── Data budget tracker ─────────────────────────────────────

	public function test_data_budget_tracker_contract(): void {
		$tracker = OosServiceBridges::data_budget_tracker( 'req-123' );

		$this->assertInstanceOf( \Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface::class, $tracker );
		$this->assertGreaterThan( 0, $tracker->getRequestBudget() );
		$this->assertGreaterThan( 0, $tracker->getPerMessageBudget() );

		$tracker->record( 100 );
		$this->assertSame( 100, $tracker->consumed() );
		$this->assertSame( $tracker->getRequestBudget() - 100, $tracker->remaining() );
		$this->assertFalse( $tracker->isExhausted() );
		$this->assertTrue( $tracker->shouldSpill( PHP_INT_MAX ) );
		$this->assertSame( 0, $tracker->spillCount() );
		$tracker->noteSpill();
		$this->assertSame( 1, $tracker->spillCount() );

		$tracker->reset( 'req-456' );
		$this->assertSame( 0, $tracker->consumed() );
	}

	public function test_data_budget_tracker_resolves_per_install_mode(): void {
		$tracker = OosServiceBridges::data_budget_tracker();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Preserved base quirk: the bridge's engine-enabled branch
			// constructs the adapter and discards it — the legacy wrapper
			// is returned in BOTH engine states.
			$this->assertNotInstanceOf( \Nvoos\WordPress\Adapter\DataBudgetTracker::class, $tracker );
		} else {
			// Standalone: no legacy engine — the real adapter resolves.
			$this->assertInstanceOf( \Nvoos\WordPress\Adapter\DataBudgetTracker::class, $tracker );
		}
	}

	// ─── Erlang C ────────────────────────────────────────────────

	public function test_erlang_c_resolves_interface_instance(): void {
		$erlang = OosServiceBridges::erlang_c();

		$this->assertInstanceOf( \Nvoos\Core\Domain\Contract\ErlangCInterface::class, $erlang );
		$this->assertSame( OosServiceBridges::erlang_c(), $erlang ); // Cached.
	}

	public function test_erlang_c_math(): void {
		$erlang = OosServiceBridges::erlang_c();

		$utilisation = $erlang->utilisation( 4.0, 8 );
		$this->assertEqualsWithDelta( 0.5, $utilisation, 0.0001 );

		$erlangs = $erlang->toErlangs( 30.0, 180.0 );
		$this->assertEqualsWithDelta( 1.5, $erlangs, 0.0001 );

		$probability = $erlang->probabilityWait( 4.0, 8 );
		$this->assertGreaterThanOrEqual( 0.0, $probability );
		$this->assertLessThanOrEqual( 1.0, $probability );

		$level = $erlang->serviceLevel( 4.0, 8, 180.0, 30.0 );
		$this->assertGreaterThanOrEqual( 0.0, $level );

		$agents = $erlang->minAgentsForServiceLevel( 4.0, 180.0, 0.8, 30.0 );
		$this->assertGreaterThan( 4, $agents );

		$wait = $erlang->averageWaitTime( 4.0, 8, 180.0 );
		$this->assertGreaterThanOrEqual( 0.0, $wait );
	}

	// ─── Error tracking ──────────────────────────────────────────

	public function test_error_tracking_contract(): void {
		$tracking = OosServiceBridges::error_tracking();

		$this->assertInstanceOf( \Nvoos\Core\Domain\Contract\ErrorTrackingServiceInterface::class, $tracking );
		$this->assertSame( OosServiceBridges::error_tracking(), $tracking ); // Cached.

		$id = $tracking->track( 'test-component', 'A test failure.', array( 'k' => 'v' ) );
		$this->assertIsString( $id );
		$this->assertIsArray( $tracking->getRecent( 10 ) );
		$this->assertGreaterThanOrEqual( 0.0, $tracking->getRate( 'test-component', 60 ) );
		$this->assertIsBool( $tracking->isEnabled() );
		$tracking->clear();
		$this->addToAssertionCount( 1 );
	}

	// ─── Cost tracking ───────────────────────────────────────────

	public function test_cost_tracking_contract(): void {
		$tracking = OosServiceBridges::cost_tracking();

		$this->assertInstanceOf( \Nvoos\Core\Domain\Contract\CostTrackingServiceInterface::class, $tracking );
		$this->assertSame( OosServiceBridges::cost_tracking(), $tracking ); // Cached.

		$user = $tracking->getUserCostBreakdown( 1, '2026-01-01', '2026-01-31' );
		$this->assertIsArray( $user );
		$this->assertArrayHasKey( 'total_cost', $user );

		$site = $tracking->getSiteCostBreakdown( '2026-01-01', '2026-01-31' );
		$this->assertIsArray( $site );
		$this->assertArrayHasKey( 'total_cost', $site );
		$this->assertArrayHasKey( 'total_tokens', $site );
	}
}
