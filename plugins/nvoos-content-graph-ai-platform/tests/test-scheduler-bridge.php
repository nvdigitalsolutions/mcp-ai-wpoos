<?php
/**
 * Scheduler bridge port tests (Wave E2).
 *
 * Characterization suite for the ported `SchedulerBridge`: byte-identical
 * runner hook and default group, availability contract (AS presence +
 * enable filter), idempotent hook registration, group filter with
 * empty-value fallback, enqueue semantics (invalid-ID and unavailable
 * bail, action-ID return, hook/args/group forwarding), per-install-mode
 * queue-class resolution for `run_job()`, and the `AsyncJobQueue` bridge
 * seams resolving per install mode.
 *
 * Action Scheduler is not loaded in the test environment, so a global
 * `as_enqueue_async_action()` stub — required from
 * `helpers/as-scheduler-stub.php` because unqualified function lookups
 * only fall back to the global namespace, which the namespaced test
 * files cannot reach — records dispatches into `AsStub`. This affects
 * every suite running in the same process — the platform suite is the
 * only consumer here.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Queues\AsyncJobQueue;
use NvoosContentGraphAiPlatform\Queues\SchedulerBridge;

require_once __DIR__ . '/helpers/as-scheduler-stub.php';

/**
 * Static capture holder for the Action Scheduler stub.
 */
class AsStub {

	/**
	 * Recorded dispatches.
	 *
	 * @var array
	 */
	public static $actions = array();

	/**
	 * Monotonic fake action ID.
	 *
	 * @var int
	 */
	public static $next_id = 0;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixtures share this file with their test cases.

/**
 * Seam subclass exposing protected members for contract testing.
 */
class SchedulerBridgeSeam extends SchedulerBridge {

	/**
	 * Expose queue_class().
	 *
	 * @return string|null
	 */
	public static function seam_queue_class() {
		return self::queue_class();
	}

	/**
	 * Reset the hook-registration flag for test isolation.
	 *
	 * @return void
	 */
	public static function seam_reset_hooks(): void {
		self::$hooks_registered = false;
	}
}

/**
 * Seam subclass forcing the unavailable-queue no-op path in run_job().
 */
class SchedulerBridgeNullQueueSeam extends SchedulerBridge {

	/**
	 * Force null resolution.
	 *
	 * @return string|null
	 */
	protected static function queue_class(): ?string {
		return null;
	}
}

/**
 * Seam subclass exposing the AsyncJobQueue bridge resolution seams.
 */
class AsyncJobQueueBridgeSeam extends AsyncJobQueue {

	/**
	 * Expose scheduler_bridge_available().
	 *
	 * @return bool
	 */
	public static function seam_bridge_available(): bool {
		return self::scheduler_bridge_available();
	}

	/**
	 * Expose scheduler_bridge_class().
	 *
	 * @return string
	 */
	public static function seam_bridge_class(): string {
		return self::scheduler_bridge_class();
	}
}

/**
 * @group queues
 */
class Test_Scheduler_Bridge extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		AsStub::$actions = array();
		AsStub::$next_id = 0;
		SchedulerBridgeSeam::seam_reset_hooks();
	}

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_async_scheduler_bridge_enabled' );
		remove_all_filters( 'wp_mcp_ai_async_scheduler_group' );

		AsStub::$actions = array();
		AsStub::$next_id = 0;
		SchedulerBridgeSeam::seam_reset_hooks();

		parent::tearDown();
	}

	// ─── Constants ──────────────────────────────────────────────────

	/**
	 * Whether the real Action Scheduler API is loaded (monolith matrix).
	 *
	 * @return bool
	 */
	private function real_as(): bool {
		return function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * Count pending actions for a hook via the real AS API (monolith only).
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Hook arguments.
	 * @param string $group Group name.
	 * @return int
	 */
	private function real_as_count( string $hook, array $args, string $group ): int {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'args'   => $args,
				'group'  => $group,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);

		return is_array( $actions ) ? count( $actions ) : 0;
	}

	/**
	 * Clean up pending actions for a hook (real-AS mode only).
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Hook arguments.
	 * @param string $group Group name.
	 * @return void
	 */
	private function real_as_cleanup( string $hook, array $args, string $group ): void {
		as_unschedule_all_actions( $hook, $args, $group );
	}

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'wp_mcp_ai_run_async_job', SchedulerBridge::RUN_HOOK );
		$this->assertSame( 'wp-mcp-ai-jobs', SchedulerBridge::DEFAULT_GROUP );
	}

	// ─── Group resolution ───────────────────────────────────────────

	public function test_get_group_default(): void {
		$this->assertSame( 'wp-mcp-ai-jobs', SchedulerBridge::get_group() );
	}

	public function test_get_group_honors_filter(): void {
		add_filter( 'wp_mcp_ai_async_scheduler_group', '__return_empty_string' );

		// Empty filtered values fall back to the default group.
		$this->assertSame( 'wp-mcp-ai-jobs', SchedulerBridge::get_group() );

		add_filter(
			'wp_mcp_ai_async_scheduler_group',
			static function () {
				return 'custom-group';
			}
		);

		$this->assertSame( 'custom-group', SchedulerBridge::get_group() );
	}

	// ─── Availability ───────────────────────────────────────────────

	public function test_is_available_with_as_loaded(): void {
		// The global AS stub is defined at file load, so availability is on
		// unless the enable filter disables it.
		$this->assertTrue( SchedulerBridge::is_available() );
	}

	public function test_is_available_respects_enable_filter(): void {
		add_filter( 'wp_mcp_ai_async_scheduler_bridge_enabled', '__return_false' );

		$this->assertFalse( SchedulerBridge::is_available() );
	}

	// ─── Enqueue ────────────────────────────────────────────────────

	public function test_enqueue_job_rejects_invalid_ids(): void {
		$this->assertFalse( SchedulerBridge::enqueue_job( 0 ) );
		$this->assertFalse( SchedulerBridge::enqueue_job( -5 ) );

		$this->assertSame( array(), AsStub::$actions );
	}

	public function test_enqueue_job_bails_when_bridge_disabled(): void {
		add_filter( 'wp_mcp_ai_async_scheduler_bridge_enabled', '__return_false' );

		$this->assertFalse( SchedulerBridge::enqueue_job( 42 ) );
		$this->assertSame( array(), AsStub::$actions );

		if ( $this->real_as() ) {
			$this->assertSame( 0, $this->real_as_count( SchedulerBridge::RUN_HOOK, array( 'job_id' => 42 ), SchedulerBridge::DEFAULT_GROUP ) );
		}
	}

	public function test_enqueue_job_dispatches_with_hook_args_and_group(): void {
		$action_id = SchedulerBridge::enqueue_job( 42 );

		if ( $this->real_as() ) {
			$this->assertGreaterThan( 0, $action_id );
			$this->assertSame(
				1,
				$this->real_as_count( SchedulerBridge::RUN_HOOK, array( 'job_id' => 42 ), SchedulerBridge::DEFAULT_GROUP )
			);
			$this->real_as_cleanup( SchedulerBridge::RUN_HOOK, array( 'job_id' => 42 ), SchedulerBridge::DEFAULT_GROUP );
		} else {
			$this->assertSame( 1, $action_id );
			$this->assertCount( 1, AsStub::$actions );

			$action = AsStub::$actions[0];
			$this->assertSame( 'wp_mcp_ai_run_async_job', $action['hook'] );
			$this->assertSame( array( 'job_id' => 42 ), $action['args'] );
			$this->assertSame( 'wp-mcp-ai-jobs', $action['group'] );
		}

		// enqueue_job() binds the runner hook before dispatching.
		$this->assertSame(
			10,
			has_action( SchedulerBridge::RUN_HOOK, array( SchedulerBridge::class, 'run_job' ) )
		);
	}

	public function test_enqueue_job_honors_group_filter(): void {
		add_filter(
			'wp_mcp_ai_async_scheduler_group',
			static function () {
				return 'custom-group';
			}
		);

		$action_id = SchedulerBridge::enqueue_job( 7 );

		if ( $this->real_as() ) {
			$this->assertGreaterThan( 0, $action_id );
			$this->assertSame(
				1,
				$this->real_as_count( SchedulerBridge::RUN_HOOK, array( 'job_id' => 7 ), 'custom-group' )
			);
			$this->real_as_cleanup( SchedulerBridge::RUN_HOOK, array( 'job_id' => 7 ), 'custom-group' );
		} else {
			$this->assertSame( 'custom-group', AsStub::$actions[0]['group'] );
		}
	}

	// ─── Hook registration ──────────────────────────────────────────

	public function test_register_hooks_is_idempotent(): void {
		SchedulerBridge::register_hooks();
		SchedulerBridge::register_hooks();

		$this->assertSame(
			10,
			has_action( SchedulerBridge::RUN_HOOK, array( SchedulerBridge::class, 'run_job' ) )
		);
	}

	public function test_run_job_rejects_invalid_ids(): void {
		SchedulerBridge::run_job( 0 );
		SchedulerBridge::run_job( -3 );

		$this->assertSame( array(), AsStub::$actions );

		if ( $this->real_as() ) {
			$this->assertSame( 0, $this->real_as_count( SchedulerBridge::RUN_HOOK, array( 'job_id' => 0 ), SchedulerBridge::DEFAULT_GROUP ) );
		}
	}

	// ─── run_job delegation ─────────────────────────────────────────

	public function test_run_job_no_ops_when_queue_unavailable(): void {
		SchedulerBridgeNullQueueSeam::run_job( 99 );

		$this->assertSame( array(), AsStub::$actions );
	}

	public function test_queue_class_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Async_Job_Queue', SchedulerBridgeSeam::seam_queue_class() );
		} else {
			$this->assertSame( AsyncJobQueue::class, SchedulerBridgeSeam::seam_queue_class() );
		}
	}

	// ─── AsyncJobQueue bridge seams ─────────────────────────────────

	public function test_async_job_queue_bridge_class_resolves_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Async_Scheduler_Bridge', AsyncJobQueueBridgeSeam::seam_bridge_class() );
		} else {
			$this->assertSame( SchedulerBridge::class, AsyncJobQueueBridgeSeam::seam_bridge_class() );
		}
	}

	public function test_async_job_queue_bridge_available_follows_enable_filter(): void {
		$this->assertTrue( AsyncJobQueueBridgeSeam::seam_bridge_available() );

		add_filter( 'wp_mcp_ai_async_scheduler_bridge_enabled', '__return_false' );
		$this->assertFalse( AsyncJobQueueBridgeSeam::seam_bridge_available() );

		remove_all_filters( 'wp_mcp_ai_async_scheduler_bridge_enabled' );
		$this->assertTrue( AsyncJobQueueBridgeSeam::seam_bridge_available() );
	}

	// ─── run_job delegation (real queue table) ─────────────────────

	/**
	 * run_job() delegates to the resolved queue class without erroring on
	 * missing rows. Only meaningful standalone (monolith delegates to the
	 * base queue; its resolution is asserted in the seam test).
	 */
	public function test_run_job_delegates_to_platform_queue_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix delegates to the base queue (resolution asserted in the seam test).' );
		}

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$table_name = $wpdb->prefix . AsyncJobQueue::TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness shadow cleanup on a plugin-owned table; the name comes from a class constant.
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table_name}" );

		AsyncJobQueue::create_table();

		$this->assertSame( AsyncJobQueue::class, SchedulerBridgeSeam::seam_queue_class() );

		// Job row does not exist — process_specific_job() must return silently.
		SchedulerBridge::run_job( 999999 );

		// Drop the real table BEFORE re-arming the framework rewrite so the
		// cleanup is not redirected to a TEMPORARY table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-harness cleanup on a plugin-owned table; the name comes from a class constant.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table_name );

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}
}
