<?php
/**
 * Job notifier port tests (Wave E2).
 *
 * Characterization suite for the ported `JobNotifier`: byte-identical
 * constants, lifecycle-hook registration, status caching roundtrip,
 * progress clamping + transitional-status promotion, step ring buffer,
 * the web-search/Veo adapters, WP_Error normalization, Little's Law
 * metrics, webhook validation/dispatch, retry→DLQ forwarding, the
 * cleanup query, and the per-install-mode collaborator seams. SSE
 * emission is exercised against the `wp_mcp_ai_emit_sse_event` action;
 * HTTP delivery is intercepted via `pre_http_request`.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\DeadLetterQueue;
use NvoosContentGraphAiPlatform\Queues\JobNotifier;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixture shares this file with its test case.

/**
 * Test seam exposing the notifier's protected surface.
 */
class JobNotifierSeam extends JobNotifier {

	/**
	 * Expose protected methods for contract testing.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed Method result.
	 */
	public static function seam( $method, array $args = array() ) {
		return self::$method( ...$args );
	}

	/**
	 * Expose the per-mode collaborator resolution.
	 *
	 * @param string $method One of job_store_class, cron_manager_class, dead_letter_queue_class.
	 * @return string|null
	 */
	public static function seam_class( $method ) {
		return self::$method();
	}
}

/**
 * @group queues
 */
class Test_Job_Notifier extends \WP_UnitTestCase {

	/**
	 * Captured `wp_mcp_ai_emit_sse_event` emissions.
	 *
	 * @var array
	 */
	private $sse_events = array();

	/**
	 * Captured `wp_mcp_ai_send_webhook` HTTP requests.
	 *
	 * @var array
	 */
	private $captured_requests = array();

	public function setUp(): void {
		parent::setUp();

		delete_option( JobNotifier::WEBHOOK_OPTION_KEY );
		delete_option( 'wp_mcp_ai_cron_jobs' );
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_webhook_secret' );

		$this->sse_events        = array();
		$this->captured_requests = array();

		add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $event, $data ) {
				$this->sse_events[] = array(
					'event' => $event,
					'data'  => $data,
				);
			},
			10,
			2
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->captured_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);
	}

	public function tearDown(): void {
		remove_all_actions( 'wp_mcp_ai_emit_sse_event' );
		remove_all_filters( 'pre_http_request' );

		delete_option( JobNotifier::WEBHOOK_OPTION_KEY );
		delete_option( 'wp_mcp_ai_cron_jobs' );
		delete_option( 'wp_mcp_ai_webhook_secret' );

		// Sweep job-status + retry transients this suite created.
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . JobNotifier::CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-harness sweep on plugin-owned transient keys.
		$like = $wpdb->esc_like( '_transient_wp_mcp_ai_webhook_retry_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-harness sweep on plugin-owned transient keys.

		parent::tearDown();
	}

	// ─── Constants + hook registration ─────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_job_status_', JobNotifier::CACHE_PREFIX );
		$this->assertSame( 3600, JobNotifier::CACHE_DURATION );
		$this->assertSame( 'wp_mcp_ai_job_webhooks', JobNotifier::WEBHOOK_OPTION_KEY );
		$this->assertSame( 10, JobNotifier::MAX_WEBHOOKS_PER_JOB );
		$this->assertSame( 50, JobNotifier::MAX_STEPS_PER_JOB );
		$this->assertSame( 20, JobNotifier::MAX_NORMALIZE_DEPTH );
	}

	public function test_init_registers_lifecycle_and_cleanup_hooks(): void {
		JobNotifier::init();

		$this->assertSame( 10, has_action( 'wp_mcp_ai_job_started', array( JobNotifier::class, 'handle_job_started' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_job_progress', array( JobNotifier::class, 'handle_job_progress' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_job_completed', array( JobNotifier::class, 'handle_job_completed' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_job_failed', array( JobNotifier::class, 'handle_job_failed' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_crawl4ai_job_completed', array( JobNotifier::class, 'handle_job_completed' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_web_search_completed', array( JobNotifier::class, 'handle_web_search_completed' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_veo_video_completed', array( JobNotifier::class, 'handle_veo_video_completed' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_cleanup_job_cache', array( JobNotifier::class, 'cleanup_expired_jobs' ) ) );
		$this->assertSame( 10, has_action( 'init', array( JobNotifier::class, 'schedule_cleanup' ) ) );
	}

	public function test_schedule_cleanup_is_idempotent(): void {
		// Clear any bootstrap-scheduled event so the guard starts cold.
		wp_clear_scheduled_hook( 'wp_mcp_ai_cleanup_job_cache' );

		JobNotifier::schedule_cleanup();
		$this->assertNotFalse( wp_next_scheduled( 'wp_mcp_ai_cleanup_job_cache' ) );

		$first = wp_next_scheduled( 'wp_mcp_ai_cleanup_job_cache' );
		JobNotifier::schedule_cleanup();
		$this->assertSame( $first, wp_next_scheduled( 'wp_mcp_ai_cleanup_job_cache' ) );

		wp_clear_scheduled_hook( 'wp_mcp_ai_cleanup_job_cache' );
	}

	// ─── Lifecycle handlers ─────────────────────────────────────────

	public function test_handle_job_started_caches_status(): void {
		JobNotifier::handle_job_started( 'job-1', array( 'context' => array( 'assistant_id' => 42 ) ) );

		$status = JobNotifier::get_job_status( 'job-1' );

		$this->assertIsArray( $status );
		$this->assertSame( 'job-1', $status['job_id'] );
		$this->assertSame( 'started', $status['status'] );
		$this->assertNotEmpty( $status['started_at'] );
		$this->assertSame( 42, $status['metadata']['assistant_id'] );
		$this->assertSame( get_current_user_id(), $status['metadata']['user_id'] );
	}

	public function test_handle_job_progress_clamps_and_promotes(): void {
		JobNotifier::handle_job_started( 'job-1' );
		JobNotifier::handle_job_progress( 'job-1', 150, array( 'tool' => 'web_search' ) );

		$status = JobNotifier::get_job_status( 'job-1' );

		$this->assertSame( 'running', $status['status'] );
		$this->assertSame( 100, $status['progress'] );
		$this->assertSame( 'web_search', $status['metadata']['tool'] );
		// Running jobs carry Little's Law metrics.
		$this->assertArrayHasKey( 'littles_law', $status );
		$this->assertSame( 'near_realtime', $status['littles_law']['sla_tier'] );
	}

	public function test_handle_job_progress_never_resurrects_terminal_status(): void {
		JobNotifier::handle_job_completed( 'job-1', array( 'ok' => true ) );
		JobNotifier::handle_job_progress( 'job-1', 50 );

		$status = JobNotifier::get_job_status( 'job-1' );

		$this->assertSame( 'completed', $status['status'] );
		// Progress is stored via floatval() — byte-identical to the base.
		$this->assertSame( 50.0, $status['progress'] );
	}

	public function test_handle_job_completed_normalises_wp_error_results(): void {
		$error = new \WP_Error( 'boom', 'Something broke', array( 'detail' => 1 ) );

		JobNotifier::handle_job_completed( 'job-1', array( 'nested' => $error ) );

		$status = JobNotifier::get_job_status( 'job-1' );

		$this->assertSame( 'completed', $status['status'] );
		$this->assertNotEmpty( $status['completed_at'] );
		$this->assertSame(
			array(
				'error'   => true,
				'code'    => 'boom',
				'message' => 'Something broke',
				'data'    => array( 'detail' => 1 ),
			),
			$status['result']['nested']
		);
	}

	public function test_handle_job_failed_caches_error_envelope(): void {
		JobNotifier::handle_job_failed( 'job-1', new \WP_Error( 'kaput', 'It died' ) );

		$status = JobNotifier::get_job_status( 'job-1' );

		$this->assertSame( 'failed', $status['status'] );
		$this->assertNotEmpty( $status['failed_at'] );
		$this->assertSame( 'It died', $status['error']['message'] );
		$this->assertSame( 'kaput', $status['error']['code'] );
	}

	public function test_handle_job_failed_non_wp_error_defaults(): void {
		JobNotifier::handle_job_failed( 'job-1', 'just a string' );

		$status = JobNotifier::get_job_status( 'job-1' );

		$this->assertSame( 'Unknown error', $status['error']['message'] );
		$this->assertSame( 'unknown_error', $status['error']['code'] );
	}

	public function test_handle_web_search_completed_uses_task_id(): void {
		JobNotifier::handle_web_search_completed(
			array(
				'task_id'  => 'search-task-9',
				'provider' => 'brave',
			),
			array( 'query' => 'test query' ),
			array( 'user_id' => 7 )
		);

		$status = JobNotifier::get_job_status( 'search-task-9' );

		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 'web_search', $status['metadata']['tool'] );
		$this->assertSame( 'test query', $status['metadata']['query'] );
		$this->assertSame( 'brave', $status['metadata']['provider'] );
		// Byte-identical to the base: web_search metadata embeds the context
		// as-is — ensure_tracking_ids() does NOT extract user_id, so the
		// current user (0 in tests) wins.
		$this->assertSame( 0, $status['metadata']['user_id'] );
	}

	public function test_handle_web_search_completed_generates_job_id(): void {
		JobNotifier::handle_web_search_completed( array(), array( 'query' => 'q' ) );

		// The generated job used the `search-` prefix — probe the cache for it.
		global $wpdb;
		$like  = $wpdb->esc_like( '_transient_' . JobNotifier::CACHE_PREFIX . 'search-' ) . '%';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test probe on plugin-owned transient keys.

		$this->assertSame( 1, $count );
	}

	public function test_handle_veo_video_completed_uses_attachment_id(): void {
		JobNotifier::handle_veo_video_completed(
			array(
				'attachment_id' => 123,
				'model'         => 'veo-3',
			),
			array( 'prompt' => 'a cat' ),
			array( 'user_id' => 5 )
		);

		$status = JobNotifier::get_job_status( 'veo-video-123' );

		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 'generate_veo_video', $status['metadata']['tool'] );
		$this->assertSame( 'veo-3', $status['metadata']['model'] );
		$this->assertSame( 123, $status['metadata']['attachment_id'] );
		$this->assertSame( 5, $status['metadata']['user_id'] );
	}

	// ─── update_status ──────────────────────────────────────────────

	public function test_update_status_rejects_invalid_input(): void {
		$this->assertFalse( JobNotifier::update_status( '', 'running' ) );
		$this->assertFalse( JobNotifier::update_status( 'job-1', 'warping' ) );
	}

	public function test_update_status_cancelled_and_pending_reset(): void {
		JobNotifier::handle_job_completed( 'job-1', array( 'ok' => true ) );

		$this->assertTrue( JobNotifier::update_status( 'job-1', 'cancelled' ) );
		$cancelled = JobNotifier::get_job_status( 'job-1' );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertArrayHasKey( 'cancelled_at', $cancelled );

		$this->assertTrue( JobNotifier::update_status( 'job-1', 'pending' ) );
		$pending = JobNotifier::get_job_status( 'job-1' );
		$this->assertSame( 'pending', $pending['status'] );
		$this->assertArrayNotHasKey( 'completed_at', $pending );
		$this->assertArrayNotHasKey( 'cancelled_at', $pending );
	}

	// ─── record_step ────────────────────────────────────────────────

	public function test_record_step_rejects_invalid_input(): void {
		$this->assertFalse( JobNotifier::record_step( '', 'label' ) );
		$this->assertFalse( JobNotifier::record_step( 'job-1', '' ) );
	}

	public function test_record_step_appends_and_normalises_status(): void {
		$this->assertTrue( JobNotifier::record_step( 'job-1', 'Uploading frames', 'weird-status' ) );

		$status = JobNotifier::get_job_status( 'job-1' );

		$this->assertCount( 1, $status['steps'] );
		$this->assertSame( 'Uploading frames', $status['steps'][0]['label'] );
		$this->assertSame( 'running', $status['steps'][0]['status'] );
		$this->assertNotEmpty( $status['steps'][0]['recorded_at'] );
	}

	public function test_record_step_enforces_ring_buffer_cap(): void {
		for ( $i = 1; $i <= 51; $i++ ) {
			JobNotifier::record_step( 'job-1', 'Step ' . $i );
		}

		$status = JobNotifier::get_job_status( 'job-1' );

		$this->assertCount( 50, $status['steps'] );
		$this->assertSame( 'Step 2', $status['steps'][0]['label'] );
		$this->assertSame( 'Step 51', $status['steps'][49]['label'] );
	}

	public function test_record_step_fires_job_step_action(): void {
		$captured = array();
		add_action(
			'wp_mcp_ai_job_step',
			function ( $job_id, $step_record, $status ) use ( &$captured ) {
				$captured = array(
					'job_id' => $job_id,
					'step'   => $step_record,
					'status' => $status,
				);
			},
			10,
			3
		);

		JobNotifier::record_step( 'job-1', 'Parsing prompt', 'completed' );

		$this->assertSame( 'job-1', $captured['job_id'] );
		$this->assertSame( 'Parsing prompt', $captured['step']['label'] );
		$this->assertSame( 'completed', $captured['step']['status'] );
		$this->assertArrayHasKey( 'steps', $captured['status'] );
	}

	// ─── SSE emission gate ──────────────────────────────────────────

	public function test_emit_sse_event_noop_without_sse_context(): void {
		// This suite never defines WP_MCP_AI_SSE_ACTIVE before this point —
		// the emission must stay silent outside an SSE context.
		if ( defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_SSE_ACTIVE already defined by an earlier suite.' );
		}

		JobNotifier::handle_job_completed( 'job-1', array( 'ok' => true ) );

		$this->assertSame( array(), $this->sse_events );
	}

	public function test_emit_sse_event_dispatches_action_with_sse_context(): void {
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) ) {
			define( 'WP_MCP_AI_SSE_ACTIVE', true );
		}

		JobNotifier::handle_job_completed( 'job-1', array( 'ok' => true ) );

		$this->assertCount( 1, $this->sse_events );
		$this->assertSame( 'job_status_update', $this->sse_events[0]['event'] );
		$this->assertSame( 'job-1', $this->sse_events[0]['data']['job_id'] );
		$this->assertSame( 'completed', $this->sse_events[0]['data']['status'] );
		$this->assertSame( array( 'ok' => true ), $this->sse_events[0]['data']['result'] );
	}

	public function test_sse_event_name_routing(): void {
		$this->assertSame( 'crawl4ai_job_status_update', JobNotifierSeam::seam( 'get_sse_event_name_for_job', array( 'crawl4ai_abc', 'progress' ) ) );
		$this->assertSame( 'cron_job_status_update', JobNotifierSeam::seam( 'get_sse_event_name_for_job', array( 'cron-123', 'progress' ) ) );
		$this->assertSame( 'job_status_update', JobNotifierSeam::seam( 'get_sse_event_name_for_job', array( 'plain-job', 'progress' ) ) );

		// Metadata-driven routing via the cached tool slug.
		JobNotifier::handle_job_started( 'x-1', array( 'tool' => 'run_crawl4ai_job' ) );
		$this->assertSame( 'crawl4ai_job_status_update', JobNotifierSeam::seam( 'get_sse_event_name_for_job', array( 'x-1', 'progress' ) ) );
		delete_transient( JobNotifier::CACHE_PREFIX . 'x-1' );

		JobNotifier::handle_job_started( 'x-2', array( 'tool' => 'create_cron_job' ) );
		$this->assertSame( 'cron_job_status_update', JobNotifierSeam::seam( 'get_sse_event_name_for_job', array( 'x-2', 'progress' ) ) );
		delete_transient( JobNotifier::CACHE_PREFIX . 'x-2' );
	}

	public function test_status_messages(): void {
		$this->assertSame( 'Job started', JobNotifierSeam::seam( 'get_status_message', array( array( 'metadata' => array() ), 'started' ) ) );
		$this->assertSame(
			'Processing... 40%',
			JobNotifierSeam::seam(
				'get_status_message',
				array(
					array(
						'metadata' => array(),
						'progress' => 40,
					),
					'progress',
				)
			)
		);
		$this->assertSame( 'Job completed successfully', JobNotifierSeam::seam( 'get_status_message', array( array( 'metadata' => array() ), 'completed' ) ) );
		$this->assertSame(
			'It died',
			JobNotifierSeam::seam(
				'get_status_message',
				array(
					array(
						'metadata' => array(),
						'error'    => array( 'message' => 'It died' ),
					),
					'failed',
				)
			)
		);
		$this->assertSame( 'custom message', JobNotifierSeam::seam( 'get_status_message', array( array( 'metadata' => array( 'message' => 'custom message' ) ), 'started' ) ) );
	}

	// ─── Webhook registration ───────────────────────────────────────

	public function test_register_webhook_rejects_invalid_url(): void {
		$result = JobNotifier::register_webhook( 'job-1', 'not-a-url' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_webhook_url', $result->get_error_code() );
	}

	public function test_register_webhook_rejects_non_http_scheme(): void {
		$result = JobNotifier::register_webhook( 'job-1', 'ftp://example.com/hook' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_webhook_scheme', $result->get_error_code() );
	}

	public function test_register_webhook_rejects_private_ips(): void {
		$result = JobNotifier::register_webhook( 'job-1', 'http://127.0.0.1/hook' );
		$this->assertWPError( $result );
		$this->assertSame( 'private_ip_blocked', $result->get_error_code() );
	}

	public function test_register_webhook_rejects_empty_job_id(): void {
		$result = JobNotifier::register_webhook( '', 'https://example.com/hook' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_job_id', $result->get_error_code() );
	}

	public function test_register_webhook_stores_subscription_with_default_events(): void {
		$this->assertTrue( JobNotifier::register_webhook( 'job-1', 'https://example.com/hook' ) );

		$webhooks = get_option( JobNotifier::WEBHOOK_OPTION_KEY, array() );
		$this->assertCount( 1, $webhooks['job-1'] );
		$this->assertSame( 'https://example.com/hook', $webhooks['job-1'][0]['url'] );
		$this->assertSame( array( 'completed', 'failed' ), $webhooks['job-1'][0]['events'] );
	}

	public function test_register_webhook_enforces_per_job_cap(): void {
		for ( $i = 0; $i < JobNotifier::MAX_WEBHOOKS_PER_JOB; $i++ ) {
			$this->assertTrue( JobNotifier::register_webhook( 'job-1', 'https://example.com/hook-' . $i ) );
		}

		$result = JobNotifier::register_webhook( 'job-1', 'https://example.com/overflow' );
		$this->assertWPError( $result );
		$this->assertSame( 'too_many_webhooks', $result->get_error_code() );
	}

	// ─── Webhook dispatch + delivery ────────────────────────────────

	public function test_dispatch_webhooks_schedules_delivery_and_records_cron_job(): void {
		JobNotifier::register_webhook( 'job-1', 'https://example.com/hook', array( 'completed' ) );
		JobNotifier::register_webhook( '*', 'https://example.com/wildcard', array( 'failed' ) );

		JobNotifier::handle_job_completed( 'job-1', array( 'ok' => true ), array( 'user_id' => 9 ) );

		// Specific + wildcard targeting: the completed event only matches job-1's hook.
		$cron  = get_option( 'wp_mcp_ai_cron_jobs', array() );
		$hooks = array_column( $cron, 'hook' );
		$this->assertContains( 'wp_mcp_ai_send_webhook', $hooks );

		$entry = null;
		foreach ( $cron as $job ) {
			if ( 'wp_mcp_ai_send_webhook' === $job['hook'] ) {
				$entry = $job;
				break;
			}
		}
		$this->assertNotNull( $entry );
		$this->assertSame( 'single', $entry['schedule'] );
		$this->assertSame( 9, $entry['created_by'] );
		$this->assertSame( 'https://example.com/hook', $entry['args'][0] );
		$this->assertSame( 'completed', $entry['args'][1]['event'] );
		$this->assertSame( 'job-1', $entry['args'][1]['job_id'] );
	}

	public function test_send_webhook_signs_payload_with_hmac(): void {
		JobNotifier::send_webhook( 'https://example.com/hook', array( 'event' => 'completed' ) );

		$this->assertCount( 1, $this->captured_requests );
		$args = $this->captured_requests[0]['args'];

		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( 'WP-MCP-AI-Webhook/1.0', $args['headers']['User-Agent'] );
		$this->assertSame( 'sha256', $args['headers']['X-WP-MCP-AI-Signature-Algo'] );

		// Read the secret through the notifier seam — the base plugin's
		// API-key store encrypts the raw option in monolith mode.
		$secret = JobNotifierSeam::seam( 'get_webhook_secret' );
		$body   = $args['body'];
		$this->assertSame( hash_hmac( 'sha256', $body, $secret ), $args['headers']['X-WP-MCP-AI-Signature'] );
	}

	public function test_webhook_failure_moves_to_dlq_after_max_retries(): void {
		$this->open_dlq_table();

		try {
			remove_all_filters( 'pre_http_request' );
			add_filter(
				'pre_http_request',
				function () {
					return new \WP_Error( 'http_failure', 'Simulated delivery failure.' );
				},
				10
			);

			$payload = array(
				'event'   => 'failed',
				'job_id'  => 'job-1',
				'data'    => array(
					'job_id' => 'job-1',
					'status' => 'failed',
				),
				'sent_at' => '',
			);
			for ( $i = 1; $i <= 3; $i++ ) {
				JobNotifier::send_webhook( 'https://example.com/hook', $payload );
			}

			// After the 3rd failure the retry transient is cleared (moved to DLQ).
			$identifier  = md5( 'https://example.com/hook' . wp_json_encode( $payload ) );
			$retry_count = get_transient( 'wp_mcp_ai_webhook_retry_' . $identifier );
			$this->assertFalse( $retry_count );

			// The item landed in the resolved DLQ (base class monolith / platform class standalone).
			$dlq_class = JobNotifierSeam::seam_class( 'dead_letter_queue_class' );
			$items     = $dlq_class::get_by_type( $dlq_class::TYPE_WEBHOOK );
			$this->assertNotEmpty( $items );

			// Items are keyed by their generated item ID.
			$item = reset( $items );
			$this->assertSame( $identifier, $item['identifier'] );
			$this->assertStringContainsString( 'Webhook delivery failed after 3 attempts', $item['failure_reason'] );
		} finally {
			remove_all_filters( 'pre_http_request' );
			$this->close_dlq_table();
		}
	}

	// ─── Cleanup + normalization ────────────────────────────────────

	public function test_cleanup_expired_jobs_deletes_only_expired_transients(): void {
		// Plant a raw, already-expired transient row (the lazy delete path
		// never runs for it) plus an unrelated live transient.
		add_option( '_transient_' . JobNotifier::CACHE_PREFIX . 'old-job', time() - 7200, '', false );
		set_transient( 'unrelated_transient', 'keep', 3600 );

		JobNotifier::cleanup_expired_jobs();

		global $wpdb;
		$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . JobNotifier::CACHE_PREFIX . 'old-job' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test probe on plugin-owned transient keys.
		$this->assertSame( '0', $remaining );
		$this->assertSame( 'keep', get_transient( 'unrelated_transient' ) );
	}

	public function test_normalize_data_recursive_depth_guard(): void {
		$data = 'x';
		for ( $i = 0; $i < 25; $i++ ) {
			$data = array( $data );
		}

		$result = JobNotifierSeam::seam( 'normalize_data_recursive', array( $data ) );

		// Walk down to the depth-guard sentinel.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertIsArray( $result );
			$result = $result[0];
		}
		$this->assertSame( '[max recursion depth reached]', $result );
	}

	public function test_sanitize_cache_job_id_preserves_dots_and_blocks_traversal(): void {
		$this->assertSame( 'veo_69203b5b2388f5.11575461', JobNotifierSeam::seam( 'sanitize_cache_job_id', array( 'veo_69203b5b2388f5.11575461' ) ) );
		// Consecutive dots collapse entirely — byte-identical to the base.
		$this->assertSame( 'ab', JobNotifierSeam::seam( 'sanitize_cache_job_id', array( 'a..b' ) ) );
		$this->assertSame( 'abc', JobNotifierSeam::seam( 'sanitize_cache_job_id', array( 'a/b\c' ) ) );
	}

	// ─── Per-install-mode seams ─────────────────────────────────────

	public function test_collaborator_seams_resolve_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Cron_Manager', JobNotifierSeam::seam_class( 'cron_manager_class' ) );
			$this->assertSame( 'WP_MCP_AI_Dead_Letter_Queue', JobNotifierSeam::seam_class( 'dead_letter_queue_class' ) );
			$this->assertSame( 'WP_MCP_AI_Job_Store', JobNotifierSeam::seam_class( 'job_store_class' ) );
		} else {
			$this->assertSame( \NvoosContentGraphAiPlatform\Queues\CronManager::class, JobNotifierSeam::seam_class( 'cron_manager_class' ) );
			$this->assertSame( DeadLetterQueue::class, JobNotifierSeam::seam_class( 'dead_letter_queue_class' ) );
			$this->assertNull( JobNotifierSeam::seam_class( 'job_store_class' ) );
		}
	}

	// ─── Real-DLQ helpers (suspend the TEMPORARY-table rewrite) ─────

	/**
	 * Create the real dead-letter table for one test.
	 *
	 * @return void
	 */
	private function open_dlq_table(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$table_name = $wpdb->prefix . 'mcp_ai_dead_letters';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness shadow cleanup on a plugin-owned table.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );

		$dlq_class = JobNotifierSeam::seam_class( 'dead_letter_queue_class' );
		$dlq_class::create_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name; test isolation on the custom table.
		$wpdb->query( 'TRUNCATE TABLE ' . $table_name );
	}

	/**
	 * Leave the real dead-letter table in place (truncated) and re-arm the
	 * TEMPORARY rewrite.
	 *
	 * Mirrors `Test_Dead_Letter_Queue::tearDown()`: the merged
	 * `JobQueueManager` failure-forwarding path depends on the real table
	 * in later test files, and either install mode's DLQ class memoizes
	 * its SHOW TABLES probe — dropping the table behind that cache makes
	 * later `add()` calls print wpdb errors (risky). Recreate if a
	 * mid-test path removed the table, then truncate for isolation.
	 *
	 * @return void
	 */
	private function close_dlq_table(): void {
		$dlq_class = JobNotifierSeam::seam_class( 'dead_letter_queue_class' );
		$dlq_class::create_table();

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name; test isolation on the custom table.
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'mcp_ai_dead_letters' );

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}
}
