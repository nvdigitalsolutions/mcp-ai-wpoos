<?php
/**
 * Async job queue port tests (Wave E2).
 *
 * Characterization suite for the ported `AsyncJobQueue`: byte-identical
 * constants/schema/hook names, queue/update/read envelopes, the cron
 * scheduling surface (minute interval + polling/cleanup events), the
 * executor filter seam, retry/fail flow, idempotency, cleanup, and stats.
 * Exercises the protected seams via a subclass (SSE emission, RabbitMQ
 * gating) so no global stubs are needed.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\AsyncJobQueue;

/**
 * @group queues
 */
class Test_Async_Job_Queue extends \WP_UnitTestCase {

	/**
	 * SSE events captured during the test.
	 *
	 * @var array<int, array{event: string, data: array}>
	 */
	private $sse_events = array();

	public function setUp(): void {
		parent::setUp();

		$this->sse_events = array();

		AsyncJobQueue::create_table();
		$this->truncate_queue();

		// Deterministic cron state — the base plugin's own queue init may
		// have scheduled the same hooks in the monolith matrix.
		\wp_clear_scheduled_hook( AsyncJobQueue::CRON_HOOK );
		\wp_clear_scheduled_hook( AsyncJobQueue::CRON_CLEANUP_HOOK );

		\remove_all_filters( 'nvoos_content_graph_ai_platform/async_job_executors' );
		\remove_all_filters( 'wp_mcp_ai_emit_sse_event' );
		\remove_all_filters( 'wp_mcp_ai_job_queue_cleanup_age_days' );
	}

	public function tearDown(): void {
		\remove_all_filters( 'nvoos_content_graph_ai_platform/async_job_executors' );
		\remove_all_filters( 'wp_mcp_ai_emit_sse_event' );
		\remove_all_filters( 'wp_mcp_ai_job_queue_cleanup_age_days' );
		\wp_clear_scheduled_hook( AsyncJobQueue::CRON_HOOK );
		\wp_clear_scheduled_hook( AsyncJobQueue::CRON_CLEANUP_HOOK );

		parent::tearDown();
	}

	/**
	 * Test subclass exposing the SSE seam.
	 */
	private function sse_queue(): string {
		$queue_class = get_class(
			new class() extends AsyncJobQueue {
				protected static function sse_handler_available(): bool {
					return true;
				}
			}
		);

		return $queue_class;
	}

	/**
	 * Truncate the queue table for deterministic counts.
	 *
	 * @return void
	 */
	private function truncate_queue(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . AsyncJobQueue::TABLE_NAME ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-controlled table name; test isolation on the custom table.
	}

	/**
	 * Queue a job through the given class.
	 *
	 * @param string $queue_class Queue class.
	 * @param array  $args        Queue args.
	 * @return int|\WP_Error
	 */
	private function queue_via( string $queue_class, array $args ) {
		return $queue_class::queue_job( $args );
	}

	// ─── Constants + schema ─────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'mcp_ai_job_queue', AsyncJobQueue::TABLE_NAME );
		$this->assertSame( 'wp_mcp_ai_process_job_queue', AsyncJobQueue::CRON_HOOK );
		$this->assertSame( 'wp_mcp_ai_cleanup_job_queue', AsyncJobQueue::CRON_CLEANUP_HOOK );

		$this->assertSame( 1, AsyncJobQueue::PRIORITY_URGENT );
		$this->assertSame( 5, AsyncJobQueue::PRIORITY_BATCH );

		$this->assertSame( 'queued', AsyncJobQueue::STATUS_QUEUED );
		$this->assertSame( 'completed', AsyncJobQueue::STATUS_COMPLETED );
		$this->assertSame( 'failed', AsyncJobQueue::STATUS_FAILED );

		$this->assertSame( 'command', AsyncJobQueue::TYPE_COMMAND );
		$this->assertSame( 'workflow', AsyncJobQueue::TYPE_WORKFLOW );
		$this->assertSame( 'tool', AsyncJobQueue::TYPE_TOOL );
		$this->assertSame( 'agentic_loop', AsyncJobQueue::TYPE_AGENTIC_LOOP );
		$this->assertSame( 'conversation_import', AsyncJobQueue::TYPE_CONVERSATION_IMPORT );

		$this->assertSame( 300, AsyncJobQueue::MAX_EXECUTION_TIME );
		$this->assertSame( 3, AsyncJobQueue::MAX_RETRIES );
		$this->assertSame( 30, AsyncJobQueue::CLEANUP_AGE_DAYS );
	}

	public function test_create_table_schema(): void {
		global $wpdb;

		$table   = $wpdb->prefix . AsyncJobQueue::TABLE_NAME;
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static test table name.

		$expected = array(
			'id',
			'job_type',
			'job_data',
			'priority',
			'status',
			'progress',
			'created_at',
			'started_at',
			'completed_at',
			'result',
			'error',
			'retries',
			'max_retries',
			'chat_session',
			'user_id',
			'assistant_id',
		);

		foreach ( $expected as $column ) {
			$this->assertContains( $column, $columns );
		}
	}

	// ─── Cron scheduling ────────────────────────────────────────────

	public function test_minute_schedule_registration(): void {
		AsyncJobQueue::register_minute_schedule();

		$schedules = \apply_filters( 'cron_schedules', array() );
		$this->assertArrayHasKey( 'minute', $schedules );
		$this->assertSame( MINUTE_IN_SECONDS, $schedules['minute']['interval'] );

		// Idempotent — the filter is registered once.
		$before = has_filter( 'cron_schedules', array( AsyncJobQueue::class, 'add_minute_schedule' ) );
		AsyncJobQueue::register_minute_schedule();
		$this->assertSame( $before, has_filter( 'cron_schedules', array( AsyncJobQueue::class, 'add_minute_schedule' ) ) );
	}

	public function test_schedule_cron_jobs_registers_and_is_idempotent(): void {
		AsyncJobQueue::schedule_cron_jobs();

		$this->assertNotFalse( \wp_next_scheduled( AsyncJobQueue::CRON_HOOK ) );
		$this->assertNotFalse( \wp_next_scheduled( AsyncJobQueue::CRON_CLEANUP_HOOK ) );

		$first  = \wp_next_scheduled( AsyncJobQueue::CRON_HOOK );
		$first2 = \wp_next_scheduled( AsyncJobQueue::CRON_CLEANUP_HOOK );

		AsyncJobQueue::schedule_cron_jobs();

		$this->assertSame( $first, \wp_next_scheduled( AsyncJobQueue::CRON_HOOK ) );
		$this->assertSame( $first2, \wp_next_scheduled( AsyncJobQueue::CRON_CLEANUP_HOOK ) );
	}

	// ─── Queueing ───────────────────────────────────────────────────

	public function test_queue_job_validation_errors(): void {
		$missing_type = AsyncJobQueue::queue_job( array( 'job_data' => array( 'a' => 1 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $missing_type );
		$this->assertSame( 'missing_job_type', $missing_type->get_error_code() );

		$missing_data = AsyncJobQueue::queue_job( array( 'job_type' => 'tool' ) );
		$this->assertInstanceOf( \WP_Error::class, $missing_data );
		$this->assertSame( 'missing_job_data', $missing_data->get_error_code() );
	}

	public function test_queue_job_defaults_and_roundtrip(): void {
		$user_id = self::factory()->user->create();
		\wp_set_current_user( $user_id );

		$job_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array(
					'tool_slug' => 'x',
					'arguments' => array( 'a' => 'b' ),
				),
			)
		);

		$this->assertIsInt( $job_id );

		$job = AsyncJobQueue::get_job( $job_id );
		$this->assertSame( 'tool', $job['job_type'] );
		$this->assertSame(
			array(
				'tool_slug' => 'x',
				'arguments' => array( 'a' => 'b' ),
			),
			$job['job_data']
		);
		$this->assertSame( AsyncJobQueue::PRIORITY_NORMAL, (int) $job['priority'] );
		$this->assertSame( AsyncJobQueue::STATUS_QUEUED, $job['status'] );
		$this->assertSame( 0, (int) $job['progress'] );
		$this->assertSame( 0, (int) $job['retries'] );
		$this->assertSame( 3, (int) $job['max_retries'] );
		$this->assertSame( $user_id, (int) $job['user_id'] );

		\wp_set_current_user( 0 );
	}

	public function test_queue_job_emits_sse_when_available(): void {
		$class = $this->sse_queue();

		\add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $event, $data ): void {
				$this->sse_events[] = array(
					'event' => $event,
					'data'  => $data,
				);
			},
			10,
			2
		);

		$job_id = $this->queue_via(
			$class,
			array(
				'job_type'     => 'tool',
				'job_data'     => array( 'tool_slug' => 'x' ),
				'chat_session' => 'session-1',
			)
		);

		$this->assertNotEmpty( $this->sse_events );
		$this->assertSame( 'job_queued', $this->sse_events[0]['event'] );
		$this->assertSame( $job_id, $this->sse_events[0]['data']['job_id'] );
		$this->assertSame( 'session-1', $this->sse_events[0]['data']['chat_session'] );

		// Without a chat session, no event fires.
		$this->sse_events = array();
		$this->queue_via(
			$class,
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 1 ),
			)
		);
		$this->assertSame( array(), $this->sse_events );
	}

	// ─── Updates + SSE progress ─────────────────────────────────────

	public function test_update_job_progress_clamp_and_completed_event(): void {
		$class  = $this->sse_queue();
		$job_id = $this->queue_via(
			$class,
			array(
				'job_type'     => 'tool',
				'job_data'     => array( 'x' => 1 ),
				'chat_session' => 'sess',
			)
		);

		\add_action(
			'wp_mcp_ai_emit_sse_event',
			function ( $event, $data ): void {
				$this->sse_events[] = array(
					'event' => $event,
					'data'  => $data,
				);
			},
			10,
			2
		);

		$class::update_job( $job_id, array( 'progress' => 250 ) );
		$job = AsyncJobQueue::get_job( $job_id );
		$this->assertSame( 100, (int) $job['progress'] );

		$class::update_job(
			$job_id,
			array(
				'status'   => AsyncJobQueue::STATUS_COMPLETED,
				'progress' => 100,
			)
		);

		$events = wp_list_pluck( $this->sse_events, 'event' );
		$this->assertContains( 'job_progress', $events );
		$this->assertContains( 'job_completed', $events );
	}

	// ─── Execution (executor seam + retries) ────────────────────────

	public function test_process_specific_job_completes_via_executor(): void {
		$job_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'workflow',
				'job_data' => array( 'workflow_id' => 7 ),
			)
		);

		$received = null;
		\add_filter(
			'nvoos_content_graph_ai_platform/async_job_executors',
			static function ( array $executors ) use ( &$received ): array {
				$executors['workflow'] = static function ( array $data, int $job_id ) use ( &$received ) {
					$received = array(
						'data'   => $data,
						'job_id' => $job_id,
					);
					return array( 'ok' => true );
				};
				return $executors;
			}
		);

		AsyncJobQueue::process_specific_job( $job_id );

		$this->assertSame( array( 'workflow_id' => 7 ), $received['data'] );
		$this->assertSame( $job_id, $received['job_id'] );

		$job = AsyncJobQueue::get_job( $job_id );
		$this->assertSame( AsyncJobQueue::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 100, (int) $job['progress'] );
		$this->assertSame( array( 'ok' => true ), $job['result'] );
	}

	public function test_process_specific_job_retries_then_fails(): void {
		$job_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 1 ),
			)
		);

		\add_filter(
			'nvoos_content_graph_ai_platform/async_job_executors',
			static function ( array $executors ): array {
				$executors['tool'] = static function (): void {
					throw new \Exception( 'boom', 42 );
				};
				return $executors;
			}
		);

		// Attempts: retries < max_retries → back to queued with retries+1.
		AsyncJobQueue::process_specific_job( $job_id );
		$job = AsyncJobQueue::get_job( $job_id );
		$this->assertSame( AsyncJobQueue::STATUS_QUEUED, $job['status'] );
		$this->assertSame( 1, (int) $job['retries'] );
		$this->assertSame( 'boom', $job['error']['message'] );
		$this->assertSame( 42, $job['error']['code'] );

		AsyncJobQueue::process_specific_job( $job_id );
		AsyncJobQueue::process_specific_job( $job_id );

		// Third attempt exhausts max_retries (3) → failed.
		AsyncJobQueue::process_specific_job( $job_id );
		$job = AsyncJobQueue::get_job( $job_id );
		$this->assertSame( AsyncJobQueue::STATUS_FAILED, $job['status'] );
		$this->assertSame( 3, (int) $job['retries'] );
	}

	public function test_process_specific_job_unknown_type_fails(): void {
		$job_id = AsyncJobQueue::queue_job(
			array(
				'job_type'    => 'mystery',
				'job_data'    => array( 'x' => 1 ),
				'max_retries' => 0,
			)
		);

		AsyncJobQueue::process_specific_job( $job_id );

		$job = AsyncJobQueue::get_job( $job_id );
		$this->assertSame( AsyncJobQueue::STATUS_FAILED, $job['status'] );
		$this->assertStringContainsString( 'Unknown job type: mystery', $job['error']['message'] );
	}

	public function test_process_specific_job_invalid_json_fails(): void {
		global $wpdb;

		$job_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 1 ),
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Corrupting the row on purpose.
			$wpdb->prefix . AsyncJobQueue::TABLE_NAME,
			array( 'job_data' => '{not-json' ),
			array( 'id' => $job_id )
		);

		AsyncJobQueue::process_specific_job( $job_id );

		$job = AsyncJobQueue::get_job( $job_id );
		$this->assertSame( AsyncJobQueue::STATUS_FAILED, $job['status'] );
		$this->assertSame( 'invalid_job_data', $job['error']['code'] );
	}

	public function test_process_specific_job_skips_non_queued(): void {
		$job_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 1 ),
			)
		);
		AsyncJobQueue::cancel_job( $job_id );

		$executions = 0;
		\add_filter(
			'nvoos_content_graph_ai_platform/async_job_executors',
			static function ( array $executors ) use ( &$executions ): array {
				$executors['tool'] = static function () use ( &$executions ) {
					++$executions;
					return 'ran';
				};
				return $executors;
			}
		);

		AsyncJobQueue::process_specific_job( $job_id );

		$this->assertSame( 0, $executions );
		$this->assertSame( AsyncJobQueue::STATUS_CANCELLED, AsyncJobQueue::get_job( $job_id )['status'] );
	}

	public function test_process_queue_priority_order(): void {
		$low_id  = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'workflow',
				'job_data' => array( 'workflow_id' => 1 ),
				'priority' => AsyncJobQueue::PRIORITY_LOW,
			)
		);
		$high_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'workflow',
				'job_data' => array( 'workflow_id' => 2 ),
				'priority' => AsyncJobQueue::PRIORITY_URGENT,
			)
		);

		$processed = array();
		\add_filter(
			'nvoos_content_graph_ai_platform/async_job_executors',
			static function ( array $executors ) use ( &$processed ): array {
				$executors['workflow'] = static function ( array $data, int $job_id ) use ( &$processed ) {
					$processed[] = $job_id;
					return array( 'done' => $data['workflow_id'] );
				};
				return $executors;
			}
		);

		AsyncJobQueue::process_queue();

		// One job per tick — the urgent job wins regardless of insert order.
		$this->assertSame( array( $high_id ), $processed );
		$this->assertSame( AsyncJobQueue::STATUS_COMPLETED, AsyncJobQueue::get_job( $high_id )['status'] );
		$this->assertSame( AsyncJobQueue::STATUS_QUEUED, AsyncJobQueue::get_job( $low_id )['status'] );
	}

	// ─── Lifecycle + stats ──────────────────────────────────────────

	public function test_pause_resume_cancel_transitions(): void {
		$job_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 1 ),
			)
		);

		$this->assertTrue( AsyncJobQueue::pause_job( $job_id ) );
		$this->assertSame( AsyncJobQueue::STATUS_PAUSED, AsyncJobQueue::get_job( $job_id )['status'] );

		$this->assertTrue( AsyncJobQueue::resume_job( $job_id ) );
		$this->assertSame( AsyncJobQueue::STATUS_QUEUED, AsyncJobQueue::get_job( $job_id )['status'] );

		$this->assertTrue( AsyncJobQueue::cancel_job( $job_id ) );
		$job = AsyncJobQueue::get_job( $job_id );
		$this->assertSame( AsyncJobQueue::STATUS_CANCELLED, $job['status'] );
		$this->assertNotEmpty( $job['completed_at'] );
	}

	public function test_get_jobs_by_status_decodes_and_limits(): void {
		AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 1 ),
			)
		);
		AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 2 ),
			)
		);
		$completed_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 3 ),
			)
		);

		\add_filter(
			'nvoos_content_graph_ai_platform/async_job_executors',
			static function ( array $executors ): array {
				$executors['tool'] = static function (): string {
					return 'done';
				};
				return $executors;
			}
		);
		AsyncJobQueue::process_specific_job( $completed_id );

		$queued    = AsyncJobQueue::get_jobs_by_status( AsyncJobQueue::STATUS_QUEUED, 1 );
		$completed = AsyncJobQueue::get_jobs_by_status( AsyncJobQueue::STATUS_COMPLETED );

		$this->assertCount( 1, $queued );
		$this->assertIsArray( $queued[0]['job_data'] );

		$this->assertCount( 1, $completed );
		$this->assertSame( 'done', $completed[0]['result'] );
	}

	public function test_get_queue_stats_counts(): void {
		AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 1 ),
			)
		);
		AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 2 ),
			)
		);

		$stats = AsyncJobQueue::get_queue_stats();

		$this->assertSame( 2, (int) $stats['total'] );
		$this->assertSame( 2, (int) $stats['queued'] );
		$this->assertSame( 0, (int) $stats['running'] );
		$this->assertSame( 0, (int) $stats['completed'] );
		$this->assertSame( 0, (int) $stats['failed'] );
	}

	public function test_cleanup_old_jobs_removes_terminal_states_only(): void {
		global $wpdb;

		$keep_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 1 ),
			)
		);
		$done_id = AsyncJobQueue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array( 'x' => 2 ),
			)
		);
		AsyncJobQueue::cancel_job( $done_id );

		// Age the terminal row — the cleanup compares against the DB clock
		// and a row completed in the same second is not "old" yet.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static plugin-controlled table name.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aging the row on purpose.
			$wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . AsyncJobQueue::TABLE_NAME . ' SET completed_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = %d',
				$done_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		\add_filter(
			'wp_mcp_ai_job_queue_cleanup_age_days',
			static function (): int {
				return 0; // Anything terminal counts as old.
			}
		);

		AsyncJobQueue::cleanup_old_jobs();

		$this->assertNotNull( AsyncJobQueue::get_job( $keep_id ) );
		$this->assertNull( AsyncJobQueue::get_job( $done_id ) );
	}

	public function test_init_registers_processing_hooks(): void {
		AsyncJobQueue::init();

		$this->assertSame(
			10,
			has_action( AsyncJobQueue::CRON_HOOK, array( AsyncJobQueue::class, 'process_queue' ) )
		);
		$this->assertSame(
			10,
			has_action( AsyncJobQueue::CRON_CLEANUP_HOOK, array( AsyncJobQueue::class, 'cleanup_old_jobs' ) )
		);
	}
}
