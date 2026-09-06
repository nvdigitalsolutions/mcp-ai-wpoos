<?php
/**
 * Agentic workflow optimizer port tests (Wave E1, sub-cluster 6).
 *
 * Characterization suite for the ported `AgenticWorkflowOptimizer`:
 * byte-identical constants, the constructor-driven enable gate + hook
 * registration, the tool-result cache cycle (allowlist + filter + key
 * normalization + hit/miss metrics), the gzip/base64 result-compression
 * contract (threshold, savings gate, Accept-Encoding gating), the
 * metrics lifecycle, the low-necessity iteration tracker, iteration
 * prediction (95th percentile + buffer + bounds), the percentile math,
 * and the per-mode logging seams. Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Workflows\AgenticWorkflowOptimizer;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Seam exposing protected members + forcing the logging paths.
 */
class OptimizerSeam extends AgenticWorkflowOptimizer {

	/**
	 * Forced iteration history.
	 *
	 * @var array
	 */
	public static $forced_history = array();

	/**
	 * Forced logging flag (null = per-mode resolution).
	 *
	 * @var bool|null
	 */
	public static $forced_logging_enabled = null;

	/**
	 * Recorded log events.
	 *
	 * @var array
	 */
	public static $logged = array();

	/**
	 * Probe a protected method.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed Method result.
	 */
	public function probe( $method, array $args = array() ) {
		return $this->$method( ...$args );
	}

	/**
	 * Forced iteration history.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	protected function get_iteration_history( $assistant_id ) {
		return self::$forced_history;
	}

	/**
	 * Forced logging gate.
	 *
	 * @return bool
	 */
	protected function logging_enabled() {
		if ( null !== self::$forced_logging_enabled ) {
			return self::$forced_logging_enabled;
		}
		return parent::logging_enabled();
	}

	/**
	 * Record instead of delegating to the base logger.
	 *
	 * @param string $type    Event type.
	 * @param string $message Human-readable message.
	 * @param array  $context Event context.
	 * @return void
	 */
	protected function log_event( $type, $message, $context = array() ): void {
		self::$logged[] = array( $type, $message, $context );
	}
}

/**
 * @group workflows
 */
class Test_Agentic_Optimizer extends \WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_before_tool_execute' );
		remove_all_actions( 'wp_mcp_ai_after_tool_execute' );
		remove_all_actions( 'wp_mcp_ai_agentic_loop_start' );
		remove_all_actions( 'wp_mcp_ai_agentic_loop_complete' );
		remove_all_filters( 'wp_mcp_ai_tool_result_content' );
		remove_all_filters( 'wp_mcp_ai_cacheable_tools' );
		remove_all_actions( 'wp_mcp_ai_agentic_metrics' );
		unset( $_SERVER['HTTP_ACCEPT_ENCODING'] );
		OptimizerSeam::$forced_history         = array();
		OptimizerSeam::$forced_logging_enabled = null;
		OptimizerSeam::$logged                 = array();
		parent::tearDown();
	}

	// ─── Constants + construction ─────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_tool_results', AgenticWorkflowOptimizer::CACHE_GROUP );
		$this->assertSame( 300, AgenticWorkflowOptimizer::CACHE_EXPIRATION );
		$this->assertSame( 10240, AgenticWorkflowOptimizer::COMPRESSION_THRESHOLD );
		$this->assertSame( 5, AgenticWorkflowOptimizer::MAX_LOW_NECESSITY_ITERATIONS );
	}

	public function test_constructor_registers_hooks_when_enabled(): void {
		$optimizer = new AgenticWorkflowOptimizer();

		$this->assertNotFalse( has_filter( 'wp_mcp_ai_before_tool_execute', array( $optimizer, 'check_tool_cache' ) ) );
		$this->assertNotFalse( has_action( 'wp_mcp_ai_after_tool_execute', array( $optimizer, 'cache_tool_result' ) ) );
		$this->assertNotFalse( has_action( 'wp_mcp_ai_agentic_loop_start', array( $optimizer, 'start_metrics_collection' ) ) );
		$this->assertNotFalse( has_action( 'wp_mcp_ai_agentic_loop_complete', array( $optimizer, 'log_performance_metrics' ) ) );
		$this->assertNotFalse( has_filter( 'wp_mcp_ai_tool_result_content', array( $optimizer, 'maybe_compress_result' ) ) );
	}

	public function test_constructor_skips_hooks_when_disabled(): void {
		if ( ! defined( 'WP_MCP_AI_DISABLE_AGENTIC_OPTIMIZATIONS' ) ) {
			define( 'WP_MCP_AI_DISABLE_AGENTIC_OPTIMIZATIONS', true );
		}

		$optimizer = new AgenticWorkflowOptimizer();

		$this->assertFalse( has_filter( 'wp_mcp_ai_before_tool_execute', array( $optimizer, 'check_tool_cache' ) ) );
	}

	// ─── Tool result cache ────────────────────────────────────────────

	public function test_cache_cycle_with_metrics(): void {
		$optimizer = new OptimizerSeam();
		$optimizer->start_metrics_collection();

		// Non-cacheable tool → null, no metrics.
		$this->assertNull( $optimizer->check_tool_cache( null, 'write_tool', array(), array() ) );
		$this->assertSame( 0, $optimizer->get_metrics()['cache_misses'] );

		// Cacheable miss. Byte-identical quirks: the base records the
		// singular `cache_miss` key (while start_metrics_collection() seeds
		// the plural `cache_misses`) and stores the tool name as the value
		// (non-numeric → set, not increment).
		$this->assertNull( $optimizer->check_tool_cache( null, 'search_content', array( 'query' => 'x' ), array() ) );
		$this->assertSame( 'search_content', $optimizer->get_metrics()['cache_miss'] );

		// Store + hit.
		$result = array( 'found' => 3 );
		$optimizer->cache_tool_result( $result, 'search_content', array( 'query' => 'x' ), array() );
		$this->assertSame( $result, $optimizer->check_tool_cache( null, 'search_content', array( 'query' => 'x' ), array() ) );
		$this->assertSame( 'search_content', $optimizer->get_metrics()['cache_hit'] );
	}

	public function test_cache_respects_pre_and_skips_errors(): void {
		$optimizer = new OptimizerSeam();

		// Upstream pre wins.
		$pre = array( 'upstream' => true );
		$this->assertSame( $pre, $optimizer->check_tool_cache( $pre, 'search_content', array(), array() ) );

		// WP_Error results are never cached.
		$optimizer->cache_tool_result( new \WP_Error( 'boom', 'nope' ), 'search_content', array( 'q' => 1 ), array() );
		$this->assertNull( $optimizer->check_tool_cache( null, 'search_content', array( 'q' => 1 ), array() ) );
	}

	public function test_cacheable_tool_filter(): void {
		$optimizer = new OptimizerSeam();

		$this->assertTrue( $optimizer->probe( 'is_cacheable_tool', array( 'get_site_summary' ) ) );
		$this->assertFalse( $optimizer->probe( 'is_cacheable_tool', array( 'delete_post' ) ) );

		add_filter(
			'wp_mcp_ai_cacheable_tools',
			function ( $tools ) {
				$tools[] = 'custom_read_tool';
				return $tools;
			}
		);
		$this->assertTrue( $optimizer->probe( 'is_cacheable_tool', array( 'custom_read_tool' ) ) );
	}

	public function test_cache_key_normalizes_arguments(): void {
		$optimizer = new OptimizerSeam();

		$key_a = $optimizer->probe(
			'get_cache_key',
			array(
				'search_content',
				array(
					'query' => '  hello ',
					'page'  => 1,
				),
			)
		);
		$key_b = $optimizer->probe(
			'get_cache_key',
			array(
				'search_content',
				array(
					'page'  => 1,
					'query' => 'hello',
				),
			)
		);

		$this->assertSame( $key_a, $key_b );
		$this->assertSame(
			'tool_' . md5(
				'search_content' . wp_json_encode(
					array(
						'page'  => 1,
						'query' => 'hello',
					)
				)
			),
			$key_a
		);

		// Non-array arguments normalize to an empty array.
		$this->assertSame( 'tool_' . md5( 'search_content' . wp_json_encode( array() ) ), $optimizer->probe( 'get_cache_key', array( 'search_content', 'not-an-array' ) ) );
	}

	// ─── Result compression ───────────────────────────────────────────

	public function test_compression_roundtrip(): void {
		$optimizer                       = new OptimizerSeam();
		$_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate';

		// Below threshold → unchanged.
		$small = '{"tiny":true}';
		$this->assertSame( $small, $optimizer->maybe_compress_result( $small, array() ) );

		// Highly compressible large content → compressed envelope.
		$large = str_repeat( 'abababab', 4000 ); // 32KB of repeating data.
		$out   = $optimizer->maybe_compress_result( $large, array() );

		$this->assertNotSame( $large, $out );
		$decoded = json_decode( $out, true );
		$this->assertTrue( $decoded['compressed'] );
		$this->assertSame( strlen( $large ), $decoded['original_size'] );
		$this->assertSame( $large, gzdecode( base64_decode( $decoded['data'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test roundtrip of the binary transport encoding.
	}

	public function test_compression_requires_client_support(): void {
		$optimizer = new OptimizerSeam();
		unset( $_SERVER['HTTP_ACCEPT_ENCODING'] );

		$large = str_repeat( 'abababab', 4000 );
		$this->assertSame( $large, $optimizer->maybe_compress_result( $large, array() ) );
	}

	// ─── Metrics ──────────────────────────────────────────────────────

	public function test_metrics_lifecycle_and_action(): void {
		$optimizer = new OptimizerSeam();

		// Empty metrics → no action.
		$fired = false;
		add_action(
			'wp_mcp_ai_agentic_metrics',
			function () use ( &$fired ) {
				$fired = true;
			}
		);
		$optimizer->log_performance_metrics();
		$this->assertFalse( $fired );

		$optimizer->start_metrics_collection();
		$metrics = $optimizer->get_metrics();
		$this->assertArrayHasKey( 'start_time', $metrics );
		$this->assertSame( 0, $metrics['cache_hits'] );

		$optimizer->log_performance_metrics();
		$this->assertTrue( $fired );
		$this->assertGreaterThanOrEqual( 0, $optimizer->get_metrics()['duration'] );
	}

	public function test_logging_seam_records_via_forced_logger(): void {
		$optimizer                             = new OptimizerSeam();
		OptimizerSeam::$forced_logging_enabled = true;
		$optimizer->start_metrics_collection();
		$optimizer->log_performance_metrics();

		$this->assertCount( 1, OptimizerSeam::$logged );
		$this->assertSame( 'agentic_workflow_performance', OptimizerSeam::$logged[0][0] );
		$this->assertSame( 'Agentic workflow completed', OptimizerSeam::$logged[0][1] );
	}

	// ─── Low-necessity tracker ────────────────────────────────────────

	public function test_low_necessity_tracker_nudges_after_five(): void {
		$session = 'session-' . uniqid();

		for ( $i = 1; $i < 5; $i++ ) {
			$this->assertFalse( AgenticWorkflowOptimizer::track_low_necessity_iteration( $session ) );
		}
		$this->assertTrue( AgenticWorkflowOptimizer::track_low_necessity_iteration( $session ) );

		AgenticWorkflowOptimizer::reset_low_necessity_count( $session );
		$this->assertFalse( AgenticWorkflowOptimizer::track_low_necessity_iteration( $session ) );
	}

	public function test_low_necessity_tracker_is_per_session(): void {
		$session_a = 'session-a-' . uniqid();
		$session_b = 'session-b-' . uniqid();

		AgenticWorkflowOptimizer::track_low_necessity_iteration( $session_a );
		$this->assertFalse( AgenticWorkflowOptimizer::track_low_necessity_iteration( $session_b ) );

		// Empty session normalizes to 'default' and resets cleanly.
		AgenticWorkflowOptimizer::reset_low_necessity_count( '' );
		$this->assertFalse( AgenticWorkflowOptimizer::track_low_necessity_iteration( '' ) );
	}

	// ─── Iteration prediction ─────────────────────────────────────────

	public function test_predict_iterations_default_and_history(): void {
		$optimizer = new OptimizerSeam();

		// No history → default 15.
		$this->assertSame( 15, $optimizer->predict_optimal_iterations( 1 ) );

		// 1..10 history → p95 = 9.55 → ×1.2 = 11.46 → ceil 12 (float — the
		// base's max/min chain preserves the float).
		OptimizerSeam::$forced_history = array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 );
		$this->assertSame( 12.0, $optimizer->predict_optimal_iterations( 1 ) );

		// Huge history clamps to 50 (int — min() returns its own 50 arg).
		OptimizerSeam::$forced_history = array( 1000, 2000 );
		$this->assertSame( 50, $optimizer->predict_optimal_iterations( 1 ) );

		// Tiny history floors at 5 (int from max( 5, … )).
		OptimizerSeam::$forced_history = array( 1, 1 );
		$this->assertSame( 5, $optimizer->predict_optimal_iterations( 1 ) );
	}

	public function test_percentile_math(): void {
		$optimizer = new OptimizerSeam();

		$this->assertSame( 0, $optimizer->probe( 'calculate_percentile', array( array(), 95 ) ) );

		// Single value → itself.
		$this->assertSame( 7, $optimizer->probe( 'calculate_percentile', array( array( 7 ), 95 ) ) );

		// Median of 1..5 at 50% → 3.
		$this->assertSame( 3, $optimizer->probe( 'calculate_percentile', array( array( 1, 2, 3, 4, 5 ), 50 ) ) );
	}

	// ─── Logging per-mode seam ────────────────────────────────────────

	public function test_logging_seam_resolves_per_install_mode(): void {
		$optimizer = new OptimizerSeam();
		update_option( 'wp_mcp_ai_settings', array( 'enable_logging' => true ) );

		// Both modes agree the setting is readable (base registry monolith /
		// option read standalone).
		$this->assertTrue( $optimizer->probe( 'logging_enabled' ) );

		update_option( 'wp_mcp_ai_settings', array( 'enable_logging' => false ) );
		$this->assertFalse( $optimizer->probe( 'logging_enabled' ) );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
