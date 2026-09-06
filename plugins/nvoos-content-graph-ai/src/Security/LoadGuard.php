<?php
/**
 * System Load Guard for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-load-guard.php` (behaviour-
 * preserving; base copy retained permanently — ecosystem port plan
 * D-NOBASE). Running-counter transient key, default maximum, REST hook
 * priority (5), 429 envelope shape, and the
 * `wp_mcp_ai_load_guard_max_concurrent` filter keep their base names and
 * semantics.
 *
 * Decoupling (documented, additive):
 * - Maximum-concurrency resolution delegates to the base
 *   `WP_MCP_AI_Resource_Manager` in monolith installs; standalone
 *   installs use the filter default until the resource manager ports
 *   (tracked in the ecosystem port tracker).
 * - The job-queue integrations (Job_Queue_Manager, Async_Job_Queue) only
 *   aggregate in monolith installs; standalone installs count the
 *   transient running-async counter only.
 * - `register()` is registered standalone-only by `Plugin.php` — the
 *   base plugin owns the same REST hook in monolith installs.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces system-wide load limits at the REST entry point.
 *
 * @since 1.1.0
 */
class LoadGuard {

	/**
	 * Transient key suffix for tracking running async tool count.
	 *
	 * @var string
	 */
	const RUNNING_COUNT_KEY = 'wp_mcp_ai_load_guard_running_async';

	/**
	 * Default maximum concurrent jobs system-wide.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_CONCURRENT = 10;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'check_load' ), 5, 3 );
	}

	/**
	 * Check system load before dispatching a REST request.
	 *
	 * Sums active jobs across all three queuing systems and compares
	 * against the configured maximum. Returns WP_Error with 429 when
	 * at capacity.
	 *
	 * @param mixed           $result  Current pre-dispatch result.
	 * @param WP_REST_Server  $server  REST server instance.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed|WP_Error Null to continue, WP_Error to reject.
	 */
	public static function check_load( $result, $server, $request ) {
		// Only validate plugin routes.
		if ( ! self::is_plugin_route( $request ) ) {
			return $result;
		}

		$max_concurrent = self::get_max_concurrent();
		if ( $max_concurrent <= 0 ) {
			return $result; // No limit configured.
		}

		$active_jobs = self::count_all_active_jobs();

		if ( $active_jobs >= $max_concurrent ) {
			return new \WP_Error(
				'system_overloaded',
				sprintf(
					/* translators: %d: maximum concurrent jobs */
					__( 'The system is currently under heavy load (%d active jobs). Please try again later.', 'nvoos-content-graph-ai' ),
					$active_jobs
				),
				array(
					'status'      => 429,
					'retry_after' => 30,
					'active_jobs' => $active_jobs,
					'max_jobs'    => $max_concurrent,
				)
			);
		}

		return $result;
	}

	/**
	 * Check if the request targets a plugin REST route.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool
	 */
	private static function is_plugin_route( $request ) {
		$route = $request instanceof \WP_REST_Request ? $request->get_route() : '';
		return 0 === strpos( $route, '/mcp-ai/' ) || 0 === strpos( $route, '/nvoos-' );
	}

	/**
	 * Get the configured maximum concurrent jobs.
	 *
	 * Falls back to Resource_Manager if available, then DEFAULT_MAX_CONCURRENT.
	 *
	 * @return int
	 */
	private static function get_max_concurrent() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
			$resource_mgr   = \WP_MCP_AI_Resource_Manager::instance();
			$max_concurrent = $resource_mgr->get_max_concurrent_requests();
			if ( $max_concurrent > 0 ) {
				return absint( $max_concurrent );
			}
		}

		/**
		 * Filter the maximum concurrent jobs system-wide.
		 *
		 * @param int $max_concurrent Maximum concurrent jobs. Default 10.
		 */
		return apply_filters( 'wp_mcp_ai_load_guard_max_concurrent', self::DEFAULT_MAX_CONCURRENT );
	}

	/**
	 * Sum active jobs across all queuing systems.
	 *
	 * Aggregates from:
	 * 1. WP_MCP_AI_Job_Queue_Manager (custom table: mcp_ai_concurrent_jobs)
	 * 2. WP_MCP_AI_Async_Job_Queue (custom table: mcp_ai_job_queue)
	 * 3. WP_MCP_AI_Tool_Async_Executor (transient-based running metadata)
	 *
	 * @return int
	 */
	private static function count_all_active_jobs() {
		$count = 0;

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// 1. Job_Queue_Manager active jobs.
			if ( class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
				$count += \WP_MCP_AI_Job_Queue_Manager::count_active_jobs();
			}

			// 2. Async_Job_Queue running jobs.
			if ( class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
				$stats  = \WP_MCP_AI_Async_Job_Queue::get_queue_stats();
				$count += isset( $stats['running'] ) ? (int) $stats['running'] : 0;
			}
		}

		// 3. Tool_Async_Executor: count 'running' metadata entries via transient.
		$count += self::count_running_async_tools();

		return $count;
	}

	/**
	 * Count running async tool jobs from transient metadata.
	 *
	 * Uses a lightweight cached count updated by the async executor
	 * during status transitions, avoiding a full transient prefix scan.
	 *
	 * @return int
	 */
	private static function count_running_async_tools() {
		$count = get_transient( self::RUNNING_COUNT_KEY );
		return is_numeric( $count ) ? max( 0, (int) $count ) : 0;
	}

	/**
	 * Increment the running async tool counter.
	 *
	 * Called by Tool_Async_Executor when a job transitions to 'running'.
	 *
	 * @return void
	 */
	public static function increment_running_async() {
		$count = max( 0, (int) get_transient( self::RUNNING_COUNT_KEY ) );
		set_transient( self::RUNNING_COUNT_KEY, $count + 1, 3600 );
	}

	/**
	 * Decrement the running async tool counter.
	 *
	 * Called by Tool_Async_Executor when a job transitions away from 'running'.
	 *
	 * @return void
	 */
	public static function decrement_running_async() {
		$count = max( 0, (int) get_transient( self::RUNNING_COUNT_KEY ) );
		if ( $count <= 1 ) {
			delete_transient( self::RUNNING_COUNT_KEY );
		} else {
			set_transient( self::RUNNING_COUNT_KEY, $count - 1, 3600 );
		}
	}
}
