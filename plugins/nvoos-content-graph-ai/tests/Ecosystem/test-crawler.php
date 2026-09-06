<?php
/**
 * Crawler port tests (Wave E6, sub-cluster 5).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Engine\Crawler` coordinator: registration paths
 * (URL validation gate, defaults/clamps, job shape, cron scheduling,
 * initial-result caching), the tick-lock + poll outcomes (expiry
 * timeout, skip-polling, check-failure retry with backoff, permanent
 * failure), storage/scheduling helpers, the per-mode collaborator
 * seams, and the inline-async-tick trait primitives. Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\Crawler\Crawler;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test-only exposer fixture shares this file with its test case.

/**
 * Test-only exposer: the poll internals and trait primitives are
 * protected statics; this subclass publishes them as public wrappers.
 */
class CrawlerExposer extends Crawler {

	public static function seed_job( string $task_id, array $overrides = array() ): void {
		$job = \array_merge(
			array(
				'task_id'       => $task_id,
				'base_url'      => 'https://example.com',
				'status'        => 'pending',
				'created_at'    => \time(),
				'updated_at'    => \time(),
				'poll_interval' => 30,
				'max_runtime'   => 600,
				'arguments'     => array(),
				'context'       => array(),
				'retry_count'   => 0,
				'max_retries'   => 3,
			),
			$overrides
		);
		self::save_job( $job );
	}

	public static function run_poll( string $task_id ): void {
		self::do_poll_event( $task_id );
	}

	public static function force_finalise( array $job, \WP_Error $error, string $status ): void {
		self::finalise_with_error( $job, $error, $status );
	}

	public static function persist( array $job, array $filtered ): void {
		self::persist_progress( $job, $filtered );
	}

	public static function job_exists( string $task_id ): bool {
		return null !== self::get_job( $task_id );
	}

	public static function acquire( string $key, string $group, int $ttl ): bool {
		return self::inline_async_acquire_tick_lock( $key, $group, $ttl );
	}

	public static function release( string $key, string $group ): void {
		self::inline_async_release_tick_lock( $key, $group );
	}

	public static function kick_enabled( string $job_id ): bool {
		return self::inline_async_kick_enabled( $job_id, self::class );
	}

	public static function should_loop( int $started_at, bool $has_work ): bool {
		return self::inline_async_should_loop( $started_at, $has_work );
	}

	public static function run_kick_body( string $job_id, callable $body ): void {
		self::inline_async_run_kick( self::class, $job_id, $body );
	}

	public static function detach(): void {
		self::inline_async_detach_worker_from_client();
	}

	public static function storage_key( string $task_id ): string {
		return self::get_storage_key( $task_id );
	}

	public static function sanitize( string $task_id ): string {
		return self::sanitize_task_id( $task_id );
	}

	public static function resolve_settings(): array {
		return self::settings();
	}
}

/**
 * @group crawler
 */
class Test_Crawler extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Suppress the cron loopback (spawn_cron makes a real HTTP request).
		\add_filter( 'wp_mcp_ai_crawl4ai_auto_spawn_cron', '__return_false', 999 );

		// Block any real HTTP the monolith check tool might attempt.
		\add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'blocked_http', 'HTTP blocked in tests.' );
			},
			999
		);

		// Reset the cron-hook wiring state so each test can call init().
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			Crawler::init();
		}
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_crawl4ai_auto_spawn_cron', 999 );
		\remove_all_filters( 'pre_http_request', 999 );

		parent::tearDown();
	}

	// ─── Registration ────────────────────────────────────────────

	public function test_register_remote_job_success(): void {
		$task_id = 'task-123.abc';
		$result  = Crawler::register_remote_job(
			$task_id,
			array(
				'base_url'   => 'https://example.com/crawl',
				'poll_interval' => 10,
				'wait_timeout'  => 120,
			)
		);

		$this->assertTrue( $result );

		$status = Crawler::get_job_status( $task_id );
		$this->assertSame( $task_id, $status['task_id'] );
		$this->assertSame( 'https://example.com/crawl', $status['base_url'] );
		$this->assertSame( 'pending', $status['status'] );
		$this->assertSame( 10, $status['poll_interval'] );
		$this->assertSame( 120, $status['max_runtime'] );

		$this->assertNotFalse( \wp_next_scheduled( Crawler::CRON_HOOK, array( $task_id ) ) );

		// Cleanup.
		\wp_unschedule_event( \wp_next_scheduled( Crawler::CRON_HOOK, array( $task_id ) ), Crawler::CRON_HOOK, array( $task_id ) );
		\delete_transient( Crawler::JOB_STORAGE_PREFIX . \md5( $task_id ) );
	}

	public function test_register_remote_job_rejects_empty_task_id(): void {
		$this->assertFalse( Crawler::register_remote_job( '', array( 'base_url' => 'https://example.com' ) ) );
	}

	public function test_register_remote_job_rejects_empty_base_url(): void {
		$this->assertFalse( Crawler::register_remote_job( 'task-1', array() ) );
	}

	public function test_register_remote_job_rejects_invalid_base_url(): void {
		$this->assertFalse( Crawler::register_remote_job( 'task-1', array( 'base_url' => 'not a valid url' ) ) );
	}

	public function test_register_remote_job_applies_defaults_and_clamps(): void {
		$task_id = 'task-defaults';
		Crawler::register_remote_job( $task_id, array( 'base_url' => 'https://example.com' ) );

		$status = Crawler::get_job_status( $task_id );
		$this->assertSame( Crawler::DEFAULT_POLL_INTERVAL, $status['poll_interval'] );
		$this->assertSame( Crawler::DEFAULT_MAX_RUNTIME, $status['max_runtime'] );

		$clamped = 'task-clamped';
		Crawler::register_remote_job(
			$clamped,
			array(
				'base_url'      => 'https://example.com',
				'poll_interval' => 2,
				'wait_timeout'  => 30,
			)
		);
		$status = Crawler::get_job_status( $clamped );
		$this->assertSame( 5, $status['poll_interval'] );
		$this->assertSame( 60, $status['max_runtime'] );
	}

	public function test_register_remote_job_initial_result_cached(): void {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			// Standalone: the result-cache seam is dormant (documented).
			$this->addToAssertionCount( 1 );
			return;
		}

		$task_id = 'task-initial';
		Crawler::register_remote_job(
			$task_id,
			array(
				'base_url'       => 'https://example.com',
				'initial_result' => array( 'status' => 'queued' ),
			)
		);

		$cached = \WP_MCP_AI_Crawl4AI_Local_API::retrieve_task_result( $task_id );
		$this->assertIsArray( $cached );
		$this->assertSame( 'queued', $cached['status'] );
		$this->assertSame( $task_id, $cached['task_id'] );
		$this->assertSame( 30, $cached['metadata']['poll_interval'] );
	}

	public function test_register_completed_job(): void {
		$events = array();
		\add_action(
			'wp_mcp_ai_crawl4ai_job_registered',
			function ( $task_id, $job ) use ( &$events ): void {
				$events[] = array( $task_id, $job );
			},
			10,
			2
		);

		$task_id = 'task-done';
		$result  = Crawler::register_completed_job(
			$task_id,
			array(
				'status' => 'completed',
				'result' => array( 'status' => 'completed' ),
			)
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $events );
		$this->assertSame( $task_id, $events[0][0] );
		$this->assertTrue( $events[0][1]['skip_polling'] );
		// No polling scheduled for completed jobs.
		$this->assertFalse( \wp_next_scheduled( Crawler::CRON_HOOK, array( $task_id ) ) );
	}

	public function test_get_job_status_unknown_returns_null(): void {
		$this->assertNull( Crawler::get_job_status( 'ghost-task' ) );
	}

	// ─── Poll + lock ─────────────────────────────────────────────

	public function test_handle_poll_event_respects_tick_lock(): void {
		CrawlerExposer::seed_job( 'locked-task', array( 'created_at' => \time() - 700 ) );
		$lock_key = Crawler::TICK_LOCK_PREFIX . \md5( 'locked-task' );

		$this->assertTrue( CrawlerExposer::acquire( $lock_key, Crawler::TICK_LOCK_CACHE_GROUP, Crawler::TICK_LOCK_TTL ) );

		// Locked: the poll body must not run — the (expired) job survives.
		Crawler::handle_poll_event( 'locked-task' );
		$this->assertTrue( CrawlerExposer::job_exists( 'locked-task' ) );

		CrawlerExposer::release( $lock_key, Crawler::TICK_LOCK_CACHE_GROUP );

		// Unlocked: the expired job finalises (timeout) and is removed.
		Crawler::handle_poll_event( 'locked-task' );
		$this->assertFalse( CrawlerExposer::job_exists( 'locked-task' ) );
	}

	public function test_do_poll_event_expired_finalises_as_timeout(): void {
		$events = array();
		\add_action(
			'wp_mcp_ai_crawl4ai_job_failed',
			function ( $task_id, $error ) use ( &$events ): void {
				$events[] = array( $task_id, $error->get_error_code() );
			},
			10,
			2
		);

		CrawlerExposer::seed_job( 'expired-task', array( 'created_at' => \time() - 700 ) );
		CrawlerExposer::run_poll( 'expired-task' );

		$this->assertFalse( CrawlerExposer::job_exists( 'expired-task' ) );
		$this->assertCount( 1, $events );
		$this->assertSame( 'expired-task', $events[0][0] );
		$this->assertSame( 'wp_mcp_ai_crawl4ai_timeout', $events[0][1] );
	}

	public function test_do_poll_event_skips_skip_polling_jobs(): void {
		CrawlerExposer::seed_job( 'skip-task', array( 'skip_polling' => true ) );
		CrawlerExposer::run_poll( 'skip-task' );

		$this->assertTrue( CrawlerExposer::job_exists( 'skip-task' ) );
	}

	public function test_do_poll_event_unknown_task_is_noop(): void {
		CrawlerExposer::run_poll( 'never-registered' );
		$this->addToAssertionCount( 1 );
	}

	public function test_do_poll_event_check_failure_enters_retry(): void {
		CrawlerExposer::seed_job( 'retry-task' );
		CrawlerExposer::run_poll( 'retry-task' );

		// The check fails in both matrices (standalone seam error; monolith
		// HTTP blocked) — the job enters the retry state with backoff.
		$job = Crawler::get_job_status( 'retry-task' );
		$this->assertSame( 'retrying', $job['status'] );
		$this->assertNotFalse( \wp_next_scheduled( Crawler::CRON_HOOK, array( 'retry-task' ) ) );
	}

	public function test_finalise_with_error_retry_math_and_permanent_failure(): void {
		$failed_events = array();
		\add_action(
			'wp_mcp_ai_crawl4ai_job_failed',
			function ( $task_id ) use ( &$failed_events ): void {
				$failed_events[] = $task_id;
			}
		);

		// First failure → retry (retry_count 0 < 3).
		$job = array(
			'task_id'       => 'math-task',
			'base_url'      => 'https://example.com',
			'status'        => 'pending',
			'created_at'    => \time(),
			'updated_at'    => \time(),
			'poll_interval' => 30,
			'max_runtime'   => 600,
			'retry_count'   => 0,
			'max_retries'   => 3,
		);
		CrawlerExposer::force_finalise( $job, new \WP_Error( 'boom', 'Failed.' ), 'failed' );
		$this->assertSame( 'retrying', Crawler::get_job_status( 'math-task' )['status'] );
		$this->assertEmpty( $failed_events );

		// Timeout → permanent failure immediately (timeouts are final).
		CrawlerExposer::force_finalise( $job, new \WP_Error( 'wp_mcp_ai_crawl4ai_timeout', 'Timed out.' ), 'timeout' );
		$this->assertFalse( CrawlerExposer::job_exists( 'math-task' ) );
		$this->assertSame( array( 'math-task' ), $failed_events );
	}

	public function test_persist_progress_updates_status_and_reschedules(): void {
		$job = array(
			'task_id'       => 'progress-task',
			'base_url'      => 'https://example.com',
			'status'        => 'pending',
			'created_at'    => \time(),
			'updated_at'    => \time(),
			'poll_interval' => 30,
			'max_runtime'   => 600,
		);
		CrawlerExposer::seed_job( 'progress-task' );

		CrawlerExposer::persist(
			$job,
			array(
				'status'   => 'processing',
				'task_id'  => 'progress-task',
				'results'  => array(),
				'metadata' => array(),
			)
		);

		$this->assertSame( 'processing', Crawler::get_job_status( 'progress-task' )['status'] );
		$this->assertNotFalse( \wp_next_scheduled( Crawler::CRON_HOOK, array( 'progress-task' ) ) );
	}

	// ─── Storage / scheduling helpers ─────────────────────────────

	public function test_storage_key_shape(): void {
		$this->assertSame( Crawler::JOB_STORAGE_PREFIX . \md5( 'abc' ), CrawlerExposer::storage_key( 'abc' ) );
	}

	public function test_sanitize_task_id_strips_markup(): void {
		$this->assertSame( 'clean-task', CrawlerExposer::sanitize( '<b>clean-task</b>' ) );
	}

	public function test_schedule_next_poll_is_idempotent(): void {
		CrawlerExposer::seed_job( 'schedule-task' );

		// schedule_next_poll is protected; registration exercises it, and a
		// second registration attempt with the same task must not double-book.
		Crawler::register_remote_job( 'schedule-task', array( 'base_url' => 'https://example.com' ) );

		$events = array();
		foreach ( \_get_cron_array() as $timestamp => $crons ) {
			if ( isset( $crons[ Crawler::CRON_HOOK ] ) ) {
				foreach ( $crons[ Crawler::CRON_HOOK ] as $event ) {
					if ( in_array( 'schedule-task', $event['args'], true ) ) {
						$events[] = $timestamp;
					}
				}
			}
		}
		$this->assertCount( 1, $events );
	}

	public function test_delete_job_unschedules(): void {
		CrawlerExposer::seed_job( 'delete-task' );
		\wp_schedule_single_event( \time() + 60, Crawler::CRON_HOOK, array( 'delete-task' ) );

		$ref = new \ReflectionMethod( Crawler::class, 'delete_job' );
		$ref->setAccessible( true );
		$ref->invoke( null, 'delete-task' );

		$this->assertFalse( \wp_next_scheduled( Crawler::CRON_HOOK, array( 'delete-task' ) ) );
		$this->assertNull( Crawler::get_job_status( 'delete-task' ) );
	}

	public function test_settings_resolve_per_install_mode(): void {
		\update_option( 'wp_mcp_ai_settings', array( 'test_key' => 'yes' ) );

		$settings = CrawlerExposer::resolve_settings();
		$this->assertIsArray( $settings );
		$this->assertSame( 'yes', $settings['test_key'] );
	}

	// ─── Trait primitives ─────────────────────────────────────────

	public function test_tick_lock_acquire_release_round_trip(): void {
		$key = 'wp_mcp_ai_crawl4ai_poll_lock_' . \md5( 'lock-roundtrip' );

		$this->assertTrue( CrawlerExposer::acquire( $key, 'test-group', 30 ) );
		$this->assertFalse( CrawlerExposer::acquire( $key, 'test-group', 30 ) );

		CrawlerExposer::release( $key, 'test-group' );
		$this->assertTrue( CrawlerExposer::acquire( $key, 'test-group', 30 ) );
		CrawlerExposer::release( $key, 'test-group' );
	}

	public function test_kick_enabled_respects_escape_hatch(): void {
		$this->assertTrue( CrawlerExposer::kick_enabled( 'job-1' ) );

		\add_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );
		$this->assertFalse( CrawlerExposer::kick_enabled( 'job-1' ) );
		\remove_filter( 'wp_mcp_ai_inline_kick_enabled', '__return_false' );
	}

	public function test_should_loop_respects_cron_state_budget_and_filter(): void {
		// No work → never loop.
		$this->assertFalse( CrawlerExposer::should_loop( \time(), false ) );

		// Outside the wall-clock budget → never loop.
		$this->assertFalse( CrawlerExposer::should_loop( \time() - 999, true ) );

		// With work + budget, the loop follows the ambient DISABLE_WP_CRON
		// state (defined in the monolith test process).
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$this->assertSame( $cron_disabled, CrawlerExposer::should_loop( \time(), true ) );

		// The wp_mcp_ai_inline_tick_loop_enabled filter short-circuits
		// regardless of the cron state.
		\add_filter( 'wp_mcp_ai_inline_tick_loop_enabled', '__return_false' );
		$this->assertFalse( CrawlerExposer::should_loop( \time(), true ) );
		\remove_filter( 'wp_mcp_ai_inline_tick_loop_enabled', '__return_false' );
	}

	public function test_run_kick_emits_completion_action_and_contains_failures(): void {
		$completions = array();
		\add_action(
			'wp_mcp_ai_inline_kick_completed',
			function ( $class, $job_id, $duration_ms, $success ) use ( &$completions ): void {
				$completions[] = array( $job_id, $duration_ms, $success );
			},
			10,
			4
		);

		// Success path.
		CrawlerExposer::run_kick_body(
			'kick-ok',
			static function (): void {}
		);
		$this->assertCount( 1, $completions );
		$this->assertSame( 'kick-ok', $completions[0][0] );
		$this->assertTrue( $completions[0][2] );

		// Failure path: the Throwable is contained, reported as failed.
		CrawlerExposer::run_kick_body(
			'kick-bad',
			static function (): void {
				throw new \RuntimeException( 'kaboom' );
			}
		);
		$this->assertCount( 2, $completions );
		$this->assertSame( 'kick-bad', $completions[1][0] );
		$this->assertFalse( $completions[1][2] );
	}

	public function test_detach_is_guarded_noop(): void {
		CrawlerExposer::detach();
		$this->addToAssertionCount( 1 );
	}
}
