<?php
/**
 * Background handler for `db_export` (Proposal 167 phase P7, task 7.1).
 *
 * An asynchronous export is the same tool call with the waiting removed: the
 * caller gets a durable job id, the rows are read later by a worker, and the
 * caller reads the job back by passing that id to `db_export`. Three properties
 * make that safe, and each one is visible in this file:
 *
 *  1. **The worker re-verifies, it does not replay a snapshot.** The payload
 *     carries the raw arguments and the principal, never a resolved scope. The
 *     handler re-enters the tool, so the current grant is applied — a scope that
 *     was narrowed, re-tiered or deleted between enqueue and execution refuses,
 *     and masking and the row cap are recomputed rather than remembered.
 *  2. **Enqueue asserts the tier, but reads no row.** An assistant without the
 *     `export` tier is refused at enqueue time instead of by a worker nobody is
 *     watching, and no connection is opened on the request thread.
 *  3. **The queue depth is capped per principal.** An export holds a payload and
 *     a file until it finishes, so N of them is a policy decision, not a
 *     consequence of how often the assistant asks.
 *
 * ## Vocabulary
 *
 * The status values are the ones MCP Tasks (SEP-1686) uses — `queued`,
 * `running`, `completed`, `failed`, `cancelled` — so a future `ext-tasks`
 * adapter maps this handle onto a `taskId` instead of redesigning it. The
 * transport-level extension is not implemented here.
 *
 * ## Dispatch
 *
 * Action Scheduler when it is present (it ships with WooCommerce), WP-Cron
 * otherwise. `WP_MCP_AI_Job_Store` is the source of truth in both cases, and its
 * row is written *before* dispatch so a fast worker can always find it. The
 * WP-Cron branch needs a router because the core `QueueClient` fallback puts the
 * handler name in `_handler` and nothing in the plugin listened for it; {@see
 * self::route()} closes that hole for any handler that registered itself.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

if ( ! class_exists( 'WP_MCP_AI_Db_Export_Job' ) ) {
	/**
	 * Enqueue, execute and route the async database export.
	 */
	final class WP_MCP_AI_Db_Export_Job {

		/**
		 * Action Scheduler hook, and the job store's `handler` value.
		 */
		const HOOK = 'wp_mcp_ai_db_export';

		/**
		 * Action Scheduler group, so the toolkit's jobs are separable in the UI.
		 */
		const GROUP = 'wp_mcp_ai_database';

		/**
		 * Default concurrent exports per principal.
		 */
		const DEFAULT_CONCURRENCY = 3;

		/**
		 * Register the hooks.
		 *
		 * Runs from the toolkit initialiser, which is loaded in cron context too —
		 * that is what makes the Action Scheduler and WP-Cron branches reachable.
		 */
		public static function init(): void {
			add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 1 );
			add_action( 'wp_mcp_ai_handle_async_job', array( __CLASS__, 'route' ), 10, 1 );
		}

		/**
		 * Whether an asynchronous export can be tracked on this site.
		 *
		 * The job store is the source of truth; without its table a job would be
		 * dispatched and then be invisible, so async is refused rather than
		 * half-supported. The poll surface needs no separate check: `db_export`
		 * itself reads a job back when it is given a `job_id`, so the toolkit never
		 * depends on the engine bridge's generic job-status tool.
		 */
		public static function supports(): bool {
			return class_exists( 'WP_MCP_AI_Job_Store' ) && WP_MCP_AI_Job_Store::table_exists();
		}

		/**
		 * Queue an export.
		 *
		 * @param array<string, mixed> $arguments Sanitised tool arguments (`async` is removed).
		 * @param array<string, mixed> $context   Execution context.
		 * @return string|WP_Error Job id, or a capability error.
		 * @throws DatabaseRefusal When the principal already has the maximum number of exports queued.
		 */
		public static function enqueue( array $arguments, array $context ) {
			if ( ! self::supports() ) {
				return new WP_Error(
					'wp_mcp_ai_db_async_unavailable',
					__( 'Background exports need the job store, which is not installed on this site. Call the tool without async to export inline.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

			if ( self::pending( $user_id ) >= self::concurrency( $user_id ) ) {
				throw DatabaseRefusal::of(
					RefusalCode::EXPORT_LIMIT,
					'This principal already has the maximum number of exports queued.'
				);
			}

			// The worker must never re-enter the async branch: it runs the same
			// arguments, and `async` is what made them a job in the first place.
			unset( $arguments['async'] );

			$job_id  = 'job_' . wp_generate_uuid4();
			$payload = array(
				'arguments' => $arguments,
				'context'   => array(
					'user_id'      => $user_id,
					'assistant_id' => isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0,
				),
			);

			$inserted = WP_MCP_AI_Job_Store::insert(
				array(
					'job_id'  => $job_id,
					'handler' => self::HOOK,
					'payload' => (string) wp_json_encode( $payload ),
					'status'  => 'queued',
					'user_id' => $user_id > 0 ? $user_id : null,
				)
			);

			if ( ! $inserted ) {
				return new WP_Error(
					'wp_mcp_ai_db_async_unavailable',
					__( 'The export could not be queued: the job store rejected the record.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			if ( ! self::dispatch( $job_id, $payload ) ) {
				WP_MCP_AI_Job_Store::complete( $job_id, null, 'The export was queued but no scheduler is available to run it.' );

				return new WP_Error(
					'wp_mcp_ai_db_async_unavailable',
					__( 'The export could not be scheduled: neither Action Scheduler nor WP-Cron accepted the job.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			return $job_id;
		}

		/**
		 * Run a queued export.
		 *
		 * The Action Scheduler hook receives the payload array, with `_job_id`
		 * merged in by the dispatcher. Everything is re-derived here: the scope is
		 * resolved again, the tier is asserted again, masking and the caps are
		 * recomputed. Nothing the caller could observe is trusted from enqueue time.
		 *
		 * @param mixed $payload Payload array, or its first element.
		 */
		public static function run( $payload ): void {
			$payload = self::normalise( $payload );

			if ( '' === $payload['job_id'] ) {
				return;
			}

			WP_MCP_AI_Job_Store::update_status( $payload['job_id'], 'running' );

			try {
				$tool   = new WP_MCP_AI_Tool_Db_Export();
				$result = $tool->execute( $payload['arguments'], $payload['context'] );
			} catch ( Throwable $error ) {
				WP_MCP_AI_Job_Store::complete( $payload['job_id'], null, 'The export failed: ' . $error->getMessage() );

				return;
			}

			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				$code = is_array( $data ) && isset( $data['refusal_code'] ) ? (string) $data['refusal_code'] : $result->get_error_code();

				WP_MCP_AI_Job_Store::complete( $payload['job_id'], null, $code . ': ' . $result->get_error_message() );

				return;
			}

			WP_MCP_AI_Job_Store::complete( $payload['job_id'], is_array( $result ) ? $result : null );
		}

		/**
		 * Route a WP-Cron fallback event to the handler it names.
		 *
		 * The core queue's cron branch fires one generic hook and carries the
		 * handler in `_handler`, but nothing in the plugin listened for it, so a
		 * site without Action Scheduler left jobs `queued` forever. This router
		 * dispatches only when the named hook actually has a listener, which keeps
		 * an unknown or mistyped handler a no-op instead of a fatal.
		 *
		 * @param mixed $payload Event payload.
		 */
		public static function route( $payload ): void {
			$payload = self::normalise( $payload );
			$handler = isset( $payload['raw']['_handler'] ) ? sanitize_key( (string) $payload['raw']['_handler'] ) : '';

			if ( '' === $handler || ! has_action( $handler ) ) {
				return;
			}

			/**
			 * Fires the named async handler exactly as Action Scheduler would.
			 *
			 * @since 3.2.0
			 *
			 * @param array<string, mixed> $args Job arguments.
			 */
			do_action( $handler, $payload['raw'] );
		}

		/**
		 * Number of queued or running exports for a user.
		 *
		 * @param int $user_id Owner; 0 counts every principal.
		 */
		private static function pending( int $user_id ): int {
			$filters = array( 'handler' => self::HOOK );

			if ( $user_id > 0 ) {
				$filters['user_id'] = $user_id;
			}

			$count = 0;

			foreach ( WP_MCP_AI_Job_Store::list_jobs( $filters, 100 ) as $row ) {
				$status = is_array( $row ) ? (string) ( $row['status'] ?? '' ) : '';

				if ( in_array( $status, array( 'queued', 'pending', 'running' ), true ) ) {
					++$count;
				}
			}

			return $count;
		}

		/**
		 * Concurrent-export ceiling for a principal.
		 *
		 * @param int $user_id Owner.
		 */
		private static function concurrency( int $user_id ): int {
			/**
			 * Filters how many async exports one principal may have queued.
			 *
			 * A site with a dedicated export worker can raise this; a site where
			 * exports compete with request traffic can lower it.
			 *
			 * @since 3.2.0
			 *
			 * @param int $limit   Default 3.
			 * @param int $user_id Owner of the new job.
			 */
			$limit = (int) apply_filters( 'wp_mcp_ai_database_export_concurrency', self::DEFAULT_CONCURRENCY, $user_id );

			return max( 1, $limit );
		}

		/**
		 * Hand the job to a scheduler.
		 *
		 * @param string               $job_id  Job id.
		 * @param array<string, mixed> $payload Payload.
		 */
		private static function dispatch( string $job_id, array $payload ): bool {
			$args = $payload + array( '_job_id' => $job_id );

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				$action_id = as_enqueue_async_action( self::HOOK, $args, self::GROUP );

				if ( 0 !== (int) $action_id ) {
					return true;
				}
			}

			// WP-Cron: the generic hook, with the handler named for the router.
			$scheduled = wp_schedule_single_event(
				time(),
				'wp_mcp_ai_handle_async_job',
				array( $args + array( '_handler' => self::HOOK ) )
			);

			return false !== $scheduled;
		}

		/**
		 * Flatten the several shapes a hook payload can arrive in.
		 *
		 * Action Scheduler passes the args array; WP-Cron passes a one-element
		 * list; a direct call passes the array. All three become one shape here.
		 *
		 * @param mixed $payload Raw payload.
		 * @return array{job_id: string, arguments: array<string, mixed>, context: array<string, mixed>, raw: array<string, mixed>}
		 */
		private static function normalise( $payload ): array {
			$raw = $payload;

			if ( is_array( $raw ) && 1 === count( $raw ) && isset( $raw[0] ) && is_array( $raw[0] ) ) {
				$raw = $raw[0];
			}

			$raw = is_array( $raw ) ? $raw : array();

			return array(
				'job_id'    => isset( $raw['_job_id'] ) ? sanitize_text_field( (string) $raw['_job_id'] ) : '',
				'arguments' => isset( $raw['arguments'] ) && is_array( $raw['arguments'] ) ? $raw['arguments'] : array(),
				'context'   => isset( $raw['context'] ) && is_array( $raw['context'] ) ? $raw['context'] : array(),
				'raw'       => $raw,
			);
		}
	}
}
