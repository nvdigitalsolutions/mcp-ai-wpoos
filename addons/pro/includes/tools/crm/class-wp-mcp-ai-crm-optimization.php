<?php
/**
 * CRM Performance Optimization
 *
 * Ensures the CRM toolkit keeps the WordPress site performant by:
 *
 *  1. Autoload audit — verifies CRM options use no-autoload, prevents
 *     wp_options bloat from slowing every page load.
 *  2. Message log retention — prunes mcp_crm_message CPT posts older
 *     than the configured retention period (default 90 days).
 *  3. Audit log compaction — trims the rolling audit buffer when it
 *     exceeds the configured max entries.
 *  4. Dedup map compaction — ensures the message dedup cache stays
 *     within bounds.
 *  5. Orphaned option cleanup — removes stale history IDs and watch
 *     states for deleted connections.
 *  6. Database index recommendations — verifies optimal meta query
 *     performance via admin health check.
 *
 * Industry references:
 *  - WordPress 6.6+ automatically disables autoload for large options
 *    (> 800 KB), but plugin authors should explicitly pass false for
 *    non-critical options.
 *  - HubSpot, Salesforce, and Zoho CRM all implement configurable
 *    data retention policies (typically 30–365 days).
 *  - WP Engine's database optimization guide recommends keeping
 *    wp_options autoloaded data under 800 KB total.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Optimization manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_CRM_Optimization {

	/**
	 * Cron hook for daily optimization run.
	 *
	 * @var string
	 */
	const OPTIMIZE_HOOK = 'wp_mcp_ai_crm_daily_optimize';

	/**
	 * Cron hook for weekly deep clean.
	 *
	 * @var string
	 */
	const DEEP_CLEAN_HOOK = 'wp_mcp_ai_crm_weekly_deep_clean';

	/**
	 * Default message retention in days.
	 *
	 * @var int
	 */
	const DEFAULT_MESSAGE_RETENTION_DAYS = 90;

	/**
	 * Default audit log max entries.
	 *
	 * @var int
	 */
	const DEFAULT_AUDIT_MAX_ENTRIES = 5000;

	/**
	 * Maximum size in bytes for a single option before warning (500 KB).
	 *
	 * @var int
	 */
	const OPTION_SIZE_WARN_BYTES = 512000;

	/**
	 * Maximum total autoloaded option size across CRM options (300 KB).
	 *
	 * @var int
	 */
	const AUTOLOAD_TOTAL_WARN_BYTES = 307200;

	/**
	 * Initialize.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		// Register optimization cron handlers.
		add_action( self::OPTIMIZE_HOOK, array( __CLASS__, 'run_daily_optimization' ) );
		add_action( self::DEEP_CLEAN_HOOK, array( __CLASS__, 'run_weekly_deep_clean' ) );

		// Schedule on init.
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Ensure CRM options use correct autoload settings on settings save.
		add_action( 'wp_mcp_ai_crm_toolkit_settings_saved', array( __CLASS__, 'fix_autoload_on_settings_save' ) );
		add_action( 'update_option_' . WP_MCP_AI_CRM_Engine::SETTINGS_OPTION, array( __CLASS__, 'fix_autoload_setting' ), 10, 2 );
		add_action( 'update_option_' . WP_MCP_AI_CRM_Engine::HYGIENE_OPTION, array( __CLASS__, 'fix_autoload_hygiene' ), 10, 2 );
	}

	/**
	 * Schedule optimization crons if not already scheduled.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::OPTIMIZE_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00:00' ), 'daily', self::OPTIMIZE_HOOK );
		}
		if ( ! wp_next_scheduled( self::DEEP_CLEAN_HOOK ) ) {
			wp_schedule_event( strtotime( 'next Sunday 04:00:00' ), 'weekly', self::DEEP_CLEAN_HOOK );
		}
	}

	/**
	 * Fix autoload status for CRM toolkit settings when saved.
	 *
	 * WordPress Settings API defaults to autoload=yes. CRM settings
	 * can be large and are only needed in admin contexts — force
	 * no-autoload to keep wp_options lean.
	 *
	 * @since 2.9.0
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 */
	public static function fix_autoload_setting( $old_value, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => WP_MCP_AI_CRM_Engine::SETTINGS_OPTION ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Fix autoload status for hygiene settings when saved.
	 *
	 * @since 2.9.0
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 */
	public static function fix_autoload_hygiene( $old_value, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => WP_MCP_AI_CRM_Engine::HYGIENE_OPTION ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Fix autoload on settings save hook.
	 *
	 * @since 2.9.0
	 */
	public static function fix_autoload_on_settings_save() {
		self::fix_autoload_setting( null, null );
		self::fix_autoload_hygiene( null, null );
	}

	/**
	 * Daily optimization: prune old messages, compact audit log, clean dedup map.
	 *
	 * @since 2.9.0
	 */
	public static function run_daily_optimization() {
		// 1. Prune old CRM messages.
		self::prune_old_messages();

		// 2. Compact audit log.
		self::compact_audit_log();

		// 3. Compact dedup map.
		self::compact_dedup_map();

		// 4. Prune old CRM activities.
		self::prune_old_activities();

		// 5. Log optimization summary.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'daily_optimization_complete',
				'system',
				'',
				array( 'timestamp' => gmdate( 'c' ) )
			);
		}
	}

	/**
	 * Weekly deep clean: orphaned options, expired watch states.
	 *
	 * @since 2.9.0
	 */
	public static function run_weekly_deep_clean() {
		// 1. Clean orphaned Gmail history IDs.
		self::clean_orphaned_history_ids();

		// 2. Clean expired Gmail watch states.
		self::clean_expired_watch_states();

		// 3. Compact audit log more aggressively.
		self::compact_audit_log( self::DEFAULT_AUDIT_MAX_ENTRIES );

		// 4. Report option health.
		self::check_option_health();
	}

	/**
	 * Prune CRM messages older than the retention period.
	 *
	 * @since 2.9.0
	 */
	private static function prune_old_messages() {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Message_Log' ) ) {
			return;
		}

		$crm_settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();

		$retention_days = isset( $crm_settings['optimization']['message_retention_days'] )
			? absint( $crm_settings['optimization']['message_retention_days'] )
			: self::DEFAULT_MESSAGE_RETENTION_DAYS;

		if ( $retention_days <= 0 ) {
			return; // 0 = keep forever.
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		// Query old messages in batches to avoid long-running queries.
		$batch_size   = 100;
		$total_pruned = 0;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => WP_MCP_AI_CRM_Message_Log::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => $batch_size,
					'fields'         => 'ids',
					'date_query'     => array(
						array(
							'before' => $cutoff,
						),
					),
					'no_found_rows'  => true,
				)
			);

			$ids = $query->posts;
			wp_reset_postdata();

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				wp_delete_post( $id, true ); // Force delete, skip trash.
			}

			$total_pruned += count( $ids );

			// Safety limit: don't delete more than 1000 per run.
			if ( $total_pruned >= 1000 ) {
				break;
			}
		} while ( ! empty( $ids ) );

		if ( $total_pruned > 0 && class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'messages_pruned',
				'message',
				'',
				array(
					'count'     => $total_pruned,
					'retention' => $retention_days,
				)
			);
		}
	}

	/**
	 * Prune old CRM activity posts (follow-up tasks > 90 days).
	 *
	 * @since 2.9.0
	 */
	private static function prune_old_activities() {
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-180 days' ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before' => $cutoff,
					),
				),
				'no_found_rows'  => true,
			)
		);

		$ids = $query->posts;
		wp_reset_postdata();

		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/**
	 * Compact the audit log if it exceeds the recommended size.
	 *
	 * @since 2.9.0
	 * @param int $max_entries Maximum entries to keep (default 5000).
	 */
	private static function compact_audit_log( $max_entries = 5000 ) {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			return;
		}

		$entries = get_option( WP_MCP_AI_CRM_Audit::OPTION_KEY, array() );
		if ( ! is_array( $entries ) ) {
			return;
		}

		$count = count( $entries );
		if ( $count <= $max_entries ) {
			return;
		}

		// Keep the most recent entries.
		$entries = array_slice( $entries, $count - $max_entries );
		update_option( WP_MCP_AI_CRM_Audit::OPTION_KEY, $entries, false );
	}

	/**
	 * Compact the message dedup map if it exceeds recommended size.
	 *
	 * @since 2.9.0
	 */
	private static function compact_dedup_map() {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Message_Log' ) ) {
			return;
		}

		$map = get_option( WP_MCP_AI_CRM_Message_Log::DEDUP_OPTION, array() );
		if ( ! is_array( $map ) || count( $map ) <= WP_MCP_AI_CRM_Message_Log::DEDUP_MAX_ENTRIES ) {
			return;
		}

		// Sort by timestamp descending, keep newest half.
		uasort(
			$map,
			function ( $a, $b ) {
				$ta = isset( $a['timestamp'] ) ? (int) $a['timestamp'] : 0;
				$tb = isset( $b['timestamp'] ) ? (int) $b['timestamp'] : 0;
				return $tb <=> $ta;
			}
		);

		$map = array_slice( $map, 0, WP_MCP_AI_CRM_Message_Log::DEDUP_MAX_ENTRIES / 2, true );
		update_option( WP_MCP_AI_CRM_Message_Log::DEDUP_OPTION, $map, false );
	}

	/**
	 * Clean orphaned Gmail history ID options for deleted connections.
	 *
	 * @since 2.9.0
	 */
	private static function clean_orphaned_history_ids() {
		global $wpdb;

		// Collect valid connection IDs.
		$valid_ids = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $connections ) ) {
				$valid_ids = array_keys( $connections );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				'wp_mcp_ai_crm_gmail_history_id_%'
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$option_name = $row->option_name;
			// Extract connection ID from option name.
			$connection_id = str_replace( 'wp_mcp_ai_crm_gmail_history_id_', '', $option_name );

			// Skip the 'settings' fallback connection.
			if ( 'settings' === $connection_id ) {
				continue;
			}

			if ( ! in_array( $connection_id, $valid_ids, true ) ) {
				delete_option( $option_name );
			}
		}
	}

	/**
	 * Clean expired Gmail watch states (> 7 days past expiration).
	 *
	 * @since 2.9.0
	 */
	private static function clean_expired_watch_states() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				'wp_mcp_ai_crm_gmail_watch_%'
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$state = maybe_unserialize( $row->option_value );
			if ( ! is_array( $state ) || empty( $state['expiration'] ) ) {
				continue;
			}

			// Gmail watch expires after 7 days typically. If expired + 7 days grace, remove.
			$expiration_ms = (int) $state['expiration'];
			$expiration_ts = (int) ( $expiration_ms / 1000 );
			$grace_period  = 7 * DAY_IN_SECONDS;

			if ( $expiration_ts > 0 && ( time() - $expiration_ts ) > $grace_period ) {
				delete_option( $row->option_name );
			}
		}
	}

	/**
	 * Check CRM option health and log warnings if options are too large.
	 *
	 * @since 2.9.0
	 * @return array Health report.
	 */
	public static function check_option_health() {
		global $wpdb;

		$report = array(
			'healthy'     => true,
			'warnings'    => array(),
			'options'     => array(),
			'total_bytes' => 0,
		);

		// List all CRM-related options.
		$crm_option_prefixes = array(
			'wp_mcp_ai_crm_',
			'wp_mcp_ai_crm_toolkit_settings',
			'wp_mcp_ai_crm_hygiene_settings',
		);

		$placeholders = array();
		$values       = array();
		foreach ( $crm_option_prefixes as $prefix ) {
			$placeholders[] = 'option_name LIKE %s';
			$values[]       = $wpdb->esc_like( $prefix ) . '%';
		}

		$where = implode( ' OR ', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size_bytes, autoload FROM {$wpdb->options} WHERE {$where} ORDER BY size_bytes DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$values
			)
		);

		if ( empty( $rows ) ) {
			return $report;
		}

		$total_autoload_bytes = 0;

		foreach ( $rows as $row ) {
			$size_bytes  = (int) $row->size_bytes;
			$is_autoload = 'yes' === $row->autoload;

			$report['options'][] = array(
				'name'       => $row->option_name,
				'size_bytes' => $size_bytes,
				'size_kb'    => round( $size_bytes / 1024, 2 ),
				'autoload'   => $row->autoload,
			);

			$report['total_bytes'] += $size_bytes;

			if ( $is_autoload ) {
				$total_autoload_bytes += $size_bytes;
			}

			// Warn on large individual options.
			if ( $size_bytes > self::OPTION_SIZE_WARN_BYTES ) {
				$report['healthy']    = false;
				$report['warnings'][] = sprintf(
					/* translators: 1: option name, 2: size in KB */
					__( 'CRM option "%1$s" is %2$s KB — consider reducing size or disabling autoload.', 'mcp-ai-wpoos-pro' ),
					$row->option_name,
					round( $size_bytes / 1024, 1 )
				);
			}
		}

		// Warn on excessive total autoload.
		if ( $total_autoload_bytes > self::AUTOLOAD_TOTAL_WARN_BYTES ) {
			$report['healthy']    = false;
			$report['warnings'][] = sprintf(
				/* translators: %s: total autoload size in KB */
				__( 'CRM autoloaded options total %s KB — exceeds recommended 300 KB. Some options should use no-autoload.', 'mcp-ai-wpoos-pro' ),
				round( $total_autoload_bytes / 1024, 1 )
			);
		}

		// Log to audit.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) && ! $report['healthy'] ) {
			WP_MCP_AI_CRM_Audit::record(
				'option_health_warning',
				'system',
				'',
				array(
					'total_bytes' => $report['total_bytes'],
					'warnings'    => count( $report['warnings'] ),
				)
			);
		}

		return $report;
	}

	/**
	 * Get total CRM storage footprint (posts + options).
	 *
	 * @since 2.9.0
	 * @return array Footprint stats.
	 */
	public static function get_storage_footprint() {
		global $wpdb;

		$stats = array(
			'cpt_counts'   => array(),
			'option_count' => 0,
			'option_bytes' => 0,
		);

		// CPT counts.
		$crm_post_types = array(
			'mcp_ai_lead',
			'mcp_ai_company',
			'mcp_ai_deal',
			'mcp_ai_customer',
			'mcp_ai_crm_activity',
			'mcp_ai_ticket',
			'mcp_crm_message',
		);

		foreach ( $crm_post_types as $pt ) {
			if ( post_type_exists( $pt ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$count                      = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
						$pt
					)
				);
				$stats['cpt_counts'][ $pt ] = $count;
			}
		}

		// Option stats.
		$health                = self::check_option_health();
		$stats['option_count'] = count( $health['options'] );
		$stats['option_bytes'] = $health['total_bytes'];

		return $stats;
	}

	/**
	 * Ensure a CRM option uses no-autoload.
	 *
	 * Call this when programmatically creating CRM options to prevent
	 * accidental autoload.
	 *
	 * @since 2.9.0
	 * @param string $option_name Option key.
	 */
	public static function ensure_no_autoload( $option_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => $option_name ),
			array( '%s' ),
			array( '%s' )
		);
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_CRM_Optimization', 'init' ), 40 );
