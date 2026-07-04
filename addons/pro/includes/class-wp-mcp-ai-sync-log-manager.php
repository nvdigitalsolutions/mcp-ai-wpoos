<?php
/**
 * Sync Log Manager.
 *
 * Provides a unified, persistent audit trail for all sync toolkit operations:
 * EZuite, FlowHub, Shopify Sync, and WooCommerce Product Inventory.
 *
 * Stores structured run-level summaries and per-item detail entries in
 * WordPress options, with automatic retention pruning and configurable caps.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {

	/**
	 * Sync Log Manager.
	 *
	 * Records sync runs with per-item detail so operators can diagnose
	 * "silent success" scenarios. Entries are stored in WordPress options
	 * and pruned automatically.
	 *
	 * @since 1.9.1
	 */
	class WP_MCP_AI_Sync_Log_Manager {

		/**
		 * Maximum runs to retain per toolkit.
		 *
		 * @since 1.9.1
		 * @var int
		 */
		const MAX_RUNS = 50;

		/**
		 * Maximum per-item detail entries per run.
		 *
		 * @since 1.9.1
		 * @var int
		 */
		const MAX_ITEMS_PER_RUN = 200;

		/**
		 * Default retention period in days.
		 *
		 * @since 1.9.1
		 * @var int
		 */
		const DEFAULT_RETENTION_DAYS = 30;

		/**
		 * Action Scheduler hook for log pruning.
		 *
		 * @since 1.9.1
		 * @var string
		 */
		const PRUNE_HOOK = 'wp_mcp_ai_sync_log_prune';

		/**
		 * Action Scheduler group for log maintenance tasks.
		 *
		 * @since 1.9.1
		 * @var string
		 */
		const PRUNE_GROUP = 'sync_log_maintenance';

		/**
		 * In-memory cache of loaded runs keyed by toolkit slug.
		 *
		 * Avoids repeated get_option() calls during a sync when log_item()
		 * updates the same run entry for every item processed.
		 *
		 * @since 1.9.2
		 * @var array<string, array>
		 */
		protected static $runs_cache = array();

		/**
		 * Initialize pruning schedule.
		 *
		 * Hooks into WordPress to schedule a daily log pruning job.
		 *
		 * @since 1.9.1
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'schedule_pruning' ) );
			add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune_all_logs' ) );
		}

		/**
		 * Schedule the daily pruning job if not already scheduled.
		 *
		 * @since 1.9.1
		 */
		public static function schedule_pruning() {
			if ( ! function_exists( 'as_has_scheduled_action' ) ) {
				return;
			}

			if ( ! as_has_scheduled_action( self::PRUNE_HOOK ) ) {
				as_schedule_recurring_action(
					strtotime( 'tomorrow 03:00:00' ),
					DAY_IN_SECONDS,
					self::PRUNE_HOOK,
					array(),
					self::PRUNE_GROUP,
					true
				);
			}
		}

		/**
		 * Start a new sync run.
		 *
		 * @since 1.9.1
		 *
		 * @param string      $toolkit_slug  Toolkit identifier (ezuite, flowhub, shopify_sync, woocommerce).
		 * @param string|null $connection_id Optional Remote Sites connection ID.
		 * @param bool        $dry_run       Whether this is a dry run.
		 * @return string Run ID for subsequent log_item() and end_run() calls.
		 */
		public static function start_run( $toolkit_slug, $connection_id = null, $dry_run = false ) {
			$run_id = self::generate_run_id( $toolkit_slug );

			$entry = array(
				'run_id'         => $run_id,
				'toolkit_slug'   => sanitize_key( $toolkit_slug ),
				'connection_id'  => ! empty( $connection_id ) ? sanitize_key( $connection_id ) : '',
				'dry_run'        => (bool) $dry_run,
				'status'         => 'in_progress',
				'started_at'     => current_time( 'mysql' ),
				'started_at_ts'  => microtime( true ),
				'ended_at'       => '',
				'duration_secs'  => 0,
				'items_total'    => 0,
				'items_inserted' => 0,
				'items_updated'  => 0,
				'items_skipped'  => 0,
				'items_errored'  => 0,
				'items'          => array(),
				'items_capped'   => false,
				'error_message'  => '',
				'summary_extra'  => array(),
			);

			self::save_run_entry( $toolkit_slug, $entry );

			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					sprintf(
						'[Sync Log] Run %s started for %s%s.',
						$run_id,
						$toolkit_slug,
						$dry_run ? ' (dry run)' : ''
					),
					'info'
				);
			}

			return $run_id;
		}

		/**
		 * Log a per-item operation within a sync run.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @param string $run_id       Run ID from start_run().
		 * @param string $operation    Operation type: insert, update, skip, error,
		 *                             would_insert, would_update, would_skip, wc_writeback.
		 * @param string $item_key     Identifying key (SKU, product ID, etc.).
		 * @param array  $details      Optional. Additional details (old/new quantities, error messages, etc.).
		 */
		public static function log_item( $toolkit_slug, $run_id, $operation, $item_key, $details = array() ) {
			$toolkit_slug = sanitize_key( $toolkit_slug );

			$entry = self::load_run_entry( $toolkit_slug, $run_id );
			if ( null === $entry ) {
				return;
			}

			if ( count( $entry['items'] ) >= self::MAX_ITEMS_PER_RUN ) {
				$entry['items_capped'] = true;
				// Defer — end_run() will persist the final capped state.
				self::save_run_entry( $toolkit_slug, $entry, true );
				return;
			}

			$item_entry = array(
				'operation' => sanitize_key( $operation ),
				'item_key'  => sanitize_text_field( $item_key ),
				'timestamp' => current_time( 'mysql' ),
			);

			if ( ! empty( $details ) ) {
				$item_entry['details'] = self::sanitize_details( $details );
			}

			$entry['items'][] = $item_entry;

			// Increment the appropriate counter.
			switch ( $operation ) {
				case 'insert':
				case 'would_insert':
					++$entry['items_inserted'];
					++$entry['items_total'];
					break;
				case 'update':
				case 'would_update':
					++$entry['items_updated'];
					++$entry['items_total'];
					break;
				case 'skip':
				case 'would_skip':
					++$entry['items_skipped'];
					break;
				case 'error':
					++$entry['items_errored'];
					break;
				default:
					++$entry['items_total'];
					break;
			}

			// Defer the write: end_run() will persist the final state once.
			self::save_run_entry( $toolkit_slug, $entry, true );
		}

		/**
		 * End a sync run with a summary.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @param string $run_id       Run ID from start_run().
		 * @param array  $summary      Summary data. Keys: status (completed|failed),
		 *                             error_message, items_total, items_inserted,
		 *                             items_updated, items_skipped, items_errored,
		 *                             summary_extra (array of extra data like location_count, wc_updated, etc.).
		 */
		public static function end_run( $toolkit_slug, $run_id, $summary = array() ) {
			$toolkit_slug = sanitize_key( $toolkit_slug );

			$entry = self::load_run_entry( $toolkit_slug, $run_id );
			if ( null === $entry ) {
				return;
			}

			$entry['ended_at'] = current_time( 'mysql' );

			if ( ! empty( $entry['started_at_ts'] ) ) {
				// Preferred: monotonic UTC epoch captured in start_run().
				$entry['duration_secs'] = max( 0, round( microtime( true ) - (float) $entry['started_at_ts'], 2 ) );
			} else {
				// Legacy entries only have the site-local started_at string, so
				// compare against the same local-time basis. Mixing it with
				// microtime( true ) (UTC) skews the result by the site's UTC
				// offset and can produce negative durations.
				$start_timestamp        = strtotime( $entry['started_at'] );
				$end_timestamp          = strtotime( $entry['ended_at'] );
				$entry['duration_secs'] = ( $start_timestamp && $end_timestamp && $end_timestamp >= $start_timestamp )
					? round( $end_timestamp - $start_timestamp, 2 )
					: 0;
			}

			$entry['status'] = isset( $summary['status'] )
				? sanitize_key( $summary['status'] )
				: 'completed';

			if ( ! empty( $summary['error_message'] ) ) {
				$entry['error_message'] = sanitize_text_field( $summary['error_message'] );
			}

			// Override counters if provided in summary (takes precedence over
			// incrementally-tracked counts from log_item).
			$counter_fields = array(
				'items_total',
				'items_inserted',
				'items_updated',
				'items_skipped',
				'items_errored',
			);
			foreach ( $counter_fields as $field ) {
				if ( isset( $summary[ $field ] ) ) {
					$entry[ $field ] = absint( $summary[ $field ] );
				}
			}

			if ( ! empty( $summary['summary_extra'] ) && is_array( $summary['summary_extra'] ) ) {
				$entry['summary_extra'] = self::sanitize_details( $summary['summary_extra'] );
			}

			self::save_run_entry( $toolkit_slug, $entry );

			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				$level = 'failed' === $entry['status'] ? 'error' : 'info';
				wp_mcp_ai_log(
					sprintf(
						'[Sync Log] Run %s ended: %s — %d total, %d inserted, %d updated, %d skipped, %d errors (%ss).',
						$run_id,
						$entry['status'],
						$entry['items_total'],
						$entry['items_inserted'],
						$entry['items_updated'],
						$entry['items_skipped'],
						$entry['items_errored'],
						$entry['duration_secs']
					),
					$level
				);
			}
		}

		/**
		 * Get recent sync runs for a toolkit.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @param int    $limit        Maximum runs to return. Default 20.
		 * @return array<int,array> Array of run entries, newest first.
		 */
		public static function get_runs( $toolkit_slug, $limit = 20 ) {
			$toolkit_slug = sanitize_key( $toolkit_slug );
			$option_key   = self::get_option_key( $toolkit_slug );
			$runs         = get_option( $option_key, array() );

			if ( ! is_array( $runs ) ) {
				return array();
			}

			// Sort by started_at descending.
			usort(
				$runs,
				function ( $a, $b ) {
					return strcmp(
						isset( $b['started_at'] ) ? $b['started_at'] : '',
						isset( $a['started_at'] ) ? $a['started_at'] : ''
					);
				}
			);

			return array_slice( $runs, 0, absint( $limit ) );
		}

		/**
		 * Get a single run entry by ID.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @param string $run_id       Run ID.
		 * @return array|null Run entry or null if not found.
		 */
		public static function get_run( $toolkit_slug, $run_id ) {
			return self::load_run_entry( $toolkit_slug, $run_id );
		}

		/**
		 * Get the latest (most recent) run for a toolkit.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @return array|null Latest run entry or null if none exist.
		 */
		public static function get_latest_run( $toolkit_slug ) {
			$runs = self::get_runs( $toolkit_slug, 1 );
			return ! empty( $runs ) ? $runs[0] : null;
		}

		/**
		 * Get the items for a specific run.
		 *
		 * @since 1.9.1
		 *
		 * @param string      $toolkit_slug Toolkit identifier.
		 * @param string      $run_id       Run ID.
		 * @param string|null $operation    Optional. Filter by operation type.
		 * @return array<int,array> Array of item entries.
		 */
		public static function get_run_items( $toolkit_slug, $run_id, $operation = null ) {
			$entry = self::load_run_entry( $toolkit_slug, $run_id );
			if ( null === $entry || empty( $entry['items'] ) ) {
				return array();
			}

			if ( null === $operation ) {
				return $entry['items'];
			}

			$operation = sanitize_key( $operation );
			return array_values(
				array_filter(
					$entry['items'],
					function ( $item ) use ( $operation ) {
						return isset( $item['operation'] ) && $item['operation'] === $operation;
					}
				)
			);
		}

		/**
		 * Clear all sync logs for a toolkit.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 */
		public static function clear_logs( $toolkit_slug ) {
			$toolkit_slug = sanitize_key( $toolkit_slug );
			delete_option( self::get_option_key( $toolkit_slug ) );

			// Invalidate in-memory cache so the next read re-hydrates from DB.
			unset( self::$runs_cache[ $toolkit_slug ] );

			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					sprintf( '[Sync Log] Cleared all sync logs for %s.', $toolkit_slug ),
					'info'
				);
			}
		}

		/**
		 * Prune all sync logs for all toolkits, removing entries older than
		 * the configured retention period.
		 *
		 * Callback for the daily Action Scheduler pruning job.
		 *
		 * @since 1.9.1
		 */
		public static function prune_all_logs() {
			$toolkits       = array( 'ezuite', 'flowhub', 'shopify_sync', 'woocommerce' );
			$retention_days = absint(
				apply_filters( 'wp_mcp_ai_sync_log_retention_days', self::DEFAULT_RETENTION_DAYS )
			);
			$cutoff         = strtotime( "-{$retention_days} days" );

			$total_pruned = 0;

			foreach ( $toolkits as $toolkit_slug ) {
				$pruned        = self::prune_toolkit_logs( $toolkit_slug, $cutoff );
				$total_pruned += $pruned;
			}

			if ( $total_pruned > 0 && function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					sprintf(
						'[Sync Log] Pruned %d sync log entries older than %d days.',
						$total_pruned,
						$retention_days
					),
					'info'
				);
			}
		}

		/**
		 * Prune old entries for a single toolkit.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @param int    $cutoff       Unix timestamp cutoff.
		 * @return int Number of entries pruned.
		 */
		protected static function prune_toolkit_logs( $toolkit_slug, $cutoff ) {
			$option_key = self::get_option_key( $toolkit_slug );
			$runs       = get_option( $option_key, array() );

			if ( ! is_array( $runs ) ) {
				return 0;
			}

			$before_count = count( $runs );
			$runs         = array_values(
				array_filter(
					$runs,
					function ( $run ) use ( $cutoff ) {
						$timestamp = isset( $run['started_at'] )
							? strtotime( $run['started_at'] )
							: 0;
						return $timestamp >= $cutoff;
					}
				)
			);
			$pruned_count = $before_count - count( $runs );

			if ( $pruned_count > 0 ) {
				update_option( $option_key, $runs, false ); // Do not autoload.
			}

			// Invalidate in-memory cache so the next read re-hydrates from DB.
			unset( self::$runs_cache[ $toolkit_slug ] );

			return $pruned_count;
		}

		// ------------------------------------------------------------------ //
		// Internal Helpers                                                     //
		// ------------------------------------------------------------------ //

		/**
		 * Generate a unique run ID.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @return string Unique run ID.
		 */
		protected static function generate_run_id( $toolkit_slug ) {
			return sprintf(
				'%s_%s_%s',
				sanitize_key( $toolkit_slug ),
				gmdate( 'YmdHis' ),
				substr( wp_generate_uuid4(), 0, 8 )
			);
		}

		/**
		 * Get the WordPress option key for a toolkit's sync logs.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @return string Option key.
		 */
		protected static function get_option_key( $toolkit_slug ) {
			return 'wp_mcp_ai_sync_log_' . sanitize_key( $toolkit_slug );
		}

		/**
		 * Load all runs for a toolkit from the option store.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @return array<int,array> Array of run entries.
		 */
		protected static function load_all_runs( $toolkit_slug ) {
			if ( isset( self::$runs_cache[ $toolkit_slug ] ) ) {
				return self::$runs_cache[ $toolkit_slug ];
			}

			$option_key = self::get_option_key( $toolkit_slug );
			$runs       = get_option( $option_key, array() );
			$runs       = is_array( $runs ) ? $runs : array();

			self::$runs_cache[ $toolkit_slug ] = $runs;

			return $runs;
		}

		/**
		 * Load a single run entry by ID.
		 *
		 * @since 1.9.1
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @param string $run_id       Run ID.
		 * @return array|null Run entry or null.
		 */
		protected static function load_run_entry( $toolkit_slug, $run_id ) {
			$runs = self::load_all_runs( $toolkit_slug );

			foreach ( $runs as $run ) {
				if ( isset( $run['run_id'] ) && $run['run_id'] === $run_id ) {
					return $run;
				}
			}

			return null;
		}

		/**
		 * Save a run entry (insert or update) for a toolkit.
		 *
		 * When $defer is true the entry is only written to the in-memory
		 * cache.  Callers that invoke this method inside a tight loop
		 * (e.g. log_item() for every synced item) should pass $defer = true
		 * and let end_run() flush the final state to the database once.
		 *
		 * @since 1.9.1
		 * @since 1.9.2 Added $defer parameter for batched writes.
		 *
		 * @param string $toolkit_slug Toolkit identifier.
		 * @param array  $entry        Run entry.
		 * @param bool   $defer        If true, only update in-memory cache.
		 */
		protected static function save_run_entry( $toolkit_slug, $entry, $defer = false ) {
			// Ensure the cache is hydrated.
			if ( ! isset( self::$runs_cache[ $toolkit_slug ] ) ) {
				self::load_all_runs( $toolkit_slug );
			}

			$runs = &self::$runs_cache[ $toolkit_slug ];

			// Find and replace existing entry, or append new one.
			$found  = false;
			$run_id = isset( $entry['run_id'] ) ? $entry['run_id'] : '';

			foreach ( $runs as $index => $run ) {
				if ( isset( $run['run_id'] ) && $run['run_id'] === $run_id ) {
					$runs[ $index ] = $entry;
					$found          = true;
					break;
				}
			}

			if ( ! $found ) {
				$runs[] = $entry;
			}

			// Enforce MAX_RUNS cap.
			if ( count( $runs ) > self::MAX_RUNS ) {
				// Sort by started_at descending, keep newest.
				usort(
					$runs,
					function ( $a, $b ) {
						return strcmp(
							isset( $b['started_at'] ) ? $b['started_at'] : '',
							isset( $a['started_at'] ) ? $a['started_at'] : ''
						);
					}
				);
				$runs = array_slice( $runs, 0, self::MAX_RUNS );
			}

			if ( $defer ) {
				return; // Batch with other log_item() calls — end_run() will flush.
			}

			$option_key = self::get_option_key( $toolkit_slug );
			update_option( $option_key, $runs, false ); // Do not autoload.
		}

		/**
		 * Sanitize detail arrays for storage.
		 *
		 * Recursively sanitizes text fields and limits nesting depth.
		 *
		 * @since 1.9.1
		 *
		 * @param array $details Raw details array.
		 * @param int   $depth   Current recursion depth.
		 * @return array Sanitized details.
		 */
		protected static function sanitize_details( $details, $depth = 0 ) {
			if ( $depth > 3 ) {
				return array( '_truncated' => true );
			}

			$sanitized = array();
			foreach ( $details as $key => $value ) {
				$safe_key = sanitize_key( $key );
				if ( is_array( $value ) ) {
					$sanitized[ $safe_key ] = self::sanitize_details( $value, $depth + 1 );
				} elseif ( is_scalar( $value ) ) {
					$sanitized[ $safe_key ] = sanitize_text_field( (string) $value );
				}
			}
			return $sanitized;
		}
	}
}
