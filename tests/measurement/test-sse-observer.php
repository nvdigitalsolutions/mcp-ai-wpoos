<?php
/**
 * Tests for the SSE stream observer.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observer tests.
 */
class Test_WP_MCP_AI_SSE_Observer extends WP_UnitTestCase {

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_SSE_Metrics::register( WP_MCP_AI_Measurement_Registry::get_instance() );

		WP_MCP_AI_Metric_Collector::get_instance()->clear_buffer();
		WP_MCP_AI_SSE_Observer::reset_instance();
		WP_MCP_AI_SSE_Observer::get_instance()->attach();
	}

	/**
	 * Teardown.
	 */
	public function tearDown(): void {
		WP_MCP_AI_SSE_Observer::reset_instance();
		WP_MCP_AI_Metric_Collector::reset_instance();
		WP_MCP_AI_Measurement_Registry::reset_instance();
		parent::tearDown();
	}

	/**
	 * Buffered metric ids in insertion order.
	 *
	 * @return array<int,string>
	 */
	private function buffered_ids() {
		return array_column( WP_MCP_AI_Metric_Collector::get_instance()->buffered(), 'id' );
	}

	/**
	 * Get a buffered record by id (last occurrence).
	 *
	 * @param string $id Metric id.
	 * @return array<string,mixed>|null
	 */
	private function last_record( $id ) {
		$last = null;
		foreach ( WP_MCP_AI_Metric_Collector::get_instance()->buffered() as $record ) {
			if ( isset( $record['id'] ) && $record['id'] === $id ) {
				$last = $record;
			}
		}
		return $last;
	}

	/**
	 * Fire a minimal end-to-end stream sequence.
	 *
	 * @param string $job_id  Job id.
	 * @param string $outcome Outcome to emit.
	 * @param int    $chunks  Number of non-heartbeat chunks to emit.
	 * @return void
	 */
	private function simulate_stream( $job_id, $outcome, $chunks = 1 ) {
		do_action(
			'wp_mcp_ai_sse_stream_started',
			$job_id,
			array(
				'max_duration'  => 300,
				'poll_interval' => 2,
				'started_at'    => time(),
			)
		);
		for ( $i = 1; $i <= $chunks; $i++ ) {
			// Tiny sleep so timing deltas are > 0 and histograms see
			// realistic values rather than zeros.
			usleep( 1000 );
			do_action( 'wp_mcp_ai_sse_stream_chunk_sent', $job_id, 'status', $i );
		}
		do_action(
			'wp_mcp_ai_sse_stream_ended',
			$job_id,
			$outcome,
			array(
				'duration_ms' => 123,
				'iterations'  => $chunks,
				'started_at'  => time(),
			)
		);
	}

	/**
	 * Complete stream emits count + chunks + duration.
	 */
	public function test_complete_stream_emits_count_chunks_duration() {
		$this->simulate_stream( 'job-abc123', 'complete', 2 );

		$ids = $this->buffered_ids();
		$this->assertContains( 'stream.count', $ids );
		$this->assertContains( 'stream.ttfb_ms', $ids );
		$this->assertContains( 'stream.chunk_interval_ms', $ids );
		$this->assertContains( 'stream.total_duration_ms', $ids );
		$this->assertContains( 'stream.chunks.count', $ids );

		// Neither cancelled nor error should be recorded for a complete outcome.
		$this->assertNotContains( 'stream.error.count', $ids );
		$this->assertNotContains( 'stream.cancelled.count', $ids );

		$chunks = $this->last_record( 'stream.chunks.count' );
		$this->assertNotNull( $chunks );
		$this->assertSame( 2, (int) $chunks['value'] );
	}

	/**
	 * Failed stream increments stream.error.count.
	 */
	public function test_failed_stream_increments_error_count() {
		$this->simulate_stream( 'job-fail', 'failed', 1 );

		$ids = $this->buffered_ids();
		$this->assertContains( 'stream.error.count', $ids );
		$this->assertNotContains( 'stream.cancelled.count', $ids );
	}

	/**
	 * Client cancellation is counted as cancelled, NOT as error.
	 *
	 * This is the load-bearing invariant of PR 8 per the rollout plan —
	 * cancellations must not contaminate the error signal.
	 */
	public function test_client_cancellation_is_not_error() {
		$this->simulate_stream( 'job-clientcancel', 'cancelled_by_client', 1 );

		$ids = $this->buffered_ids();
		$this->assertContains( 'stream.cancelled.count', $ids );
		$this->assertNotContains( 'stream.error.count', $ids );

		$cancel = $this->last_record( 'stream.cancelled.count' );
		$this->assertNotNull( $cancel );
		$this->assertSame( 'cancelled_by_client', $cancel['context']['attributes']['outcome'] );
	}

	/**
	 * Job-level cancellation also routes to cancelled, not error.
	 */
	public function test_job_cancellation_is_not_error() {
		$this->simulate_stream( 'job-jobcancel', 'cancelled_by_job', 1 );

		$ids = $this->buffered_ids();
		$this->assertContains( 'stream.cancelled.count', $ids );
		$this->assertNotContains( 'stream.error.count', $ids );
	}

	/**
	 * Timeout is neither cancelled nor error.
	 */
	public function test_timeout_is_neither_cancelled_nor_error() {
		$this->simulate_stream( 'job-timeout', 'timeout', 0 );

		$ids = $this->buffered_ids();
		$this->assertContains( 'stream.count', $ids );
		$this->assertNotContains( 'stream.cancelled.count', $ids );
		$this->assertNotContains( 'stream.error.count', $ids );
	}

	/**
	 * First chunk emits stream.ttfb_ms; subsequent chunks emit stream.chunk_interval_ms.
	 */
	public function test_first_chunk_is_ttfb_subsequent_are_intervals() {
		do_action(
			'wp_mcp_ai_sse_stream_started',
			'job-ttfb',
			array(
				'max_duration'  => 300,
				'poll_interval' => 2,
				'started_at'    => time(),
			)
		);
		usleep( 1000 );
		do_action( 'wp_mcp_ai_sse_stream_chunk_sent', 'job-ttfb', 'status', 1 );

		$ids_after_first = $this->buffered_ids();
		$this->assertContains( 'stream.ttfb_ms', $ids_after_first );
		$this->assertNotContains( 'stream.chunk_interval_ms', $ids_after_first );

		usleep( 1000 );
		do_action( 'wp_mcp_ai_sse_stream_chunk_sent', 'job-ttfb', 'status', 2 );

		$ids_after_second = $this->buffered_ids();
		$this->assertContains( 'stream.chunk_interval_ms', $ids_after_second );

		// Clean up the open frame so tearDown doesn't leak state.
		do_action(
			'wp_mcp_ai_sse_stream_ended',
			'job-ttfb',
			'complete',
			array(
				'duration_ms' => 0,
				'iterations'  => 2,
				'started_at'  => time(),
			)
		);
	}

	/**
	 * Concurrent streams with different job_ids are tracked independently.
	 */
	public function test_concurrent_streams_do_not_confound() {
		do_action( 'wp_mcp_ai_sse_stream_started', 'job-a', array( 'started_at' => time() ) );
		do_action( 'wp_mcp_ai_sse_stream_started', 'job-b', array( 'started_at' => time() ) );

		$observer = WP_MCP_AI_SSE_Observer::get_instance();
		$this->assertSame( 2, $observer->active_streams() );

		do_action( 'wp_mcp_ai_sse_stream_ended', 'job-a', 'complete', array( 'duration_ms' => 50 ) );
		$this->assertSame( 1, $observer->active_streams() );

		do_action( 'wp_mcp_ai_sse_stream_ended', 'job-b', 'cancelled_by_client', array( 'duration_ms' => 100 ) );
		$this->assertSame( 0, $observer->active_streams() );

		$ids = $this->buffered_ids();
		$this->assertContains( 'stream.cancelled.count', $ids );
	}

	/**
	 * Unknown outcome collapses to `unknown` rather than polluting context cardinality.
	 */
	public function test_unknown_outcome_is_sanitised() {
		$this->simulate_stream( 'job-unknown', 'nonsense-outcome', 0 );

		$count = $this->last_record( 'stream.count' );
		$this->assertNotNull( $count );
		$this->assertSame( 'unknown', $count['context']['attributes']['outcome'] );
	}

	/**
	 * Stream context never leaks chunk or payload content.
	 *
	 * Canary-string scan: emit a stream and verify that nothing that
	 * looks like user content ends up in the buffered contexts. The
	 * observer never receives chunk content by construction, but we
	 * guard against future additions that might tempt someone to pass
	 * `$status` through as metadata.
	 */
	public function test_privacy_canary_no_payload_leakage() {
		$this->simulate_stream( 'job-canary', 'complete', 1 );

		foreach ( WP_MCP_AI_Metric_Collector::get_instance()->buffered() as $record ) {
			$ctx   = $record['context'];
			$attrs = isset( $ctx['attributes'] ) && is_array( $ctx['attributes'] ) ? $ctx['attributes'] : array();
			$json  = wp_json_encode( $ctx );

			// Top-level keys: only `attributes` is allowed from this observer.
			$this->assertSame(
				array(),
				array_diff( array_keys( $ctx ), array( 'attributes' ) ),
				'SSE observer emitted unexpected top-level context keys: ' . $json
			);
			// Attribute keys: only `job_id` and `outcome` are allowed.
			$this->assertSame(
				array(),
				array_diff( array_keys( $attrs ), array( 'job_id', 'outcome' ) ),
				'SSE observer emitted unexpected attribute keys: ' . $json
			);
		}
	}

	/**
	 * Job id is sanitised — URL-encoded / injected characters are stripped.
	 */
	public function test_job_id_is_sanitised() {
		$this->simulate_stream( "job-<script>alert('x')</script>/../../etc/passwd", 'complete', 0 );

		$count = $this->last_record( 'stream.count' );
		$this->assertNotNull( $count );
		$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9._\-]+$/', $count['context']['attributes']['job_id'] );
	}

	/**
	 * Observer detaches cleanly and no longer records after detach.
	 */
	public function test_detach_stops_recording() {
		WP_MCP_AI_SSE_Observer::get_instance()->detach();
		// Defensive: remove any residual listeners that earlier test bootstrap
		// phases may have left attached (e.g. the plugins_loaded auto-attach).
		remove_all_actions( 'wp_mcp_ai_sse_stream_started' );
		remove_all_actions( 'wp_mcp_ai_sse_stream_chunk_sent' );
		remove_all_actions( 'wp_mcp_ai_sse_stream_ended' );
		WP_MCP_AI_Metric_Collector::get_instance()->clear_buffer();

		$this->simulate_stream( 'job-detached', 'complete', 1 );
		$this->assertSame( array(), $this->buffered_ids() );
	}

	/**
	 * Opt-out filter suppresses attachment.
	 */
	public function test_observer_opt_out_filter() {
		// Detach all active hooks from prior setUp before switching on the filter.
		WP_MCP_AI_SSE_Observer::reset_instance();
		remove_all_actions( 'wp_mcp_ai_sse_stream_started' );
		remove_all_actions( 'wp_mcp_ai_sse_stream_chunk_sent' );
		remove_all_actions( 'wp_mcp_ai_sse_stream_ended' );

		add_filter( 'wp_mcp_ai_sse_observer_enabled', '__return_false' );

		$observer = WP_MCP_AI_SSE_Observer::get_instance();
		$this->assertFalse( $observer->attach() );

		WP_MCP_AI_Metric_Collector::get_instance()->clear_buffer();
		$this->simulate_stream( 'job-optout', 'complete', 1 );
		$this->assertSame( array(), $this->buffered_ids() );

		remove_filter( 'wp_mcp_ai_sse_observer_enabled', '__return_false' );
	}
}
