<?php
/**
 * Agent Memory Retention Manager
 *
 * Provides lifecycle management for stored agent memories, context
 * embeddings, and memory drawer assets. Implements short-term vs
 * long-term memory tiering with configurable retention windows.
 *
 * Features:
 *  - Daily cron marks dormant memories (not accessed in N days)
 *  - Weekly cron prunes memories past their retention period
 *  - Orphaned embedding cleanup (vectors without source memory)
 *  - Per-user memory cap enforcement
 *  - Memory health dashboard stats
 *
 * Industry references:
 *  - Mem0 / LangMem: short-term context window vs long-term persistent store
 *  - GDPR data minimisation: only retain what's actively used
 *  - Embedding cache TTL best practice: 30 days
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memory retention manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Memory_Retention {

	/**
	 * Cron hook for daily memory health sweep.
	 *
	 * @var string
	 */
	const DAILY_HOOK = 'wp_mcp_ai_memory_daily_sweep';

	/**
	 * Cron hook for weekly deep memory cleanup.
	 *
	 * @var string
	 */
	const WEEKLY_HOOK = 'wp_mcp_ai_memory_weekly_cleanup';

	/**
	 * Default memory retention in days.
	 *
	 * @var int
	 */
	const DEFAULT_MEMORY_RETENTION_DAYS = 365;

	/**
	 * Default dormancy threshold in days (mark as dormant if not accessed).
	 *
	 * @var int
	 */
	const DEFAULT_DORMANCY_DAYS = 30;

	/**
	 * Default per-user max memories.
	 *
	 * @var int
	 */
	const DEFAULT_PER_USER_MAX = 1000;

	/**
	 * Max memories deleted per sweep.
	 *
	 * @var int
	 */
	const MAX_DELETES_PER_RUN = 500;

	/**
	 * Initialize.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$chat_memory_enabled = defined( 'WP_MCP_AI_PRO_VERSION' )
			&& WP_MCP_AI_Settings_Registry::get_setting( 'enable_chat_memory', true );
		if ( ! $chat_memory_enabled ) {
			return;
		}

		// Register cron handlers.
		add_action( self::DAILY_HOOK, array( __CLASS__, 'run_daily_sweep' ) );
		add_action( self::WEEKLY_HOOK, array( __CLASS__, 'run_weekly_cleanup' ) );

		// Schedule on init.
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Add default settings.
		add_filter( 'wp_mcp_ai_default_settings', array( __CLASS__, 'add_default_settings' ) );
	}

	/**
	 * Schedule cron jobs.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00:00' ), 'daily', self::DAILY_HOOK );
		}
		if ( ! wp_next_scheduled( self::WEEKLY_HOOK ) ) {
			wp_schedule_event( strtotime( 'next Sunday 04:00:00' ), 'weekly', self::WEEKLY_HOOK );
		}
	}

	/**
	 * Add default settings for memory retention.
	 *
	 * @since 2.9.0
	 * @param array $defaults Existing defaults.
	 * @return array
	 */
	public static function add_default_settings( $defaults ) {
		$defaults['memory_retention_days']  = self::DEFAULT_MEMORY_RETENTION_DAYS;
		$defaults['memory_dormancy_days']   = self::DEFAULT_DORMANCY_DAYS;
		$defaults['memory_per_user_max']    = self::DEFAULT_PER_USER_MAX;
		return $defaults;
	}

	/**
	 * Daily memory health sweep: mark dormant memories, enforce per-user caps.
	 *
	 * @since 2.9.0
	 */
	public static function run_daily_sweep() {
		$table = self::get_memories_table();
		if ( ! $table ) {
			return;
		}

		global $wpdb;
		$dormancy_days    = (int) WP_MCP_AI_Settings_Registry::get_setting( 'memory_dormancy_days', self::DEFAULT_DORMANCY_DAYS );
		$per_user_max     = (int) WP_MCP_AI_Settings_Registry::get_setting( 'memory_per_user_max', self::DEFAULT_PER_USER_MAX );
		$dormancy_cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$dormancy_days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery

		// 1. Mark dormant: memories not accessed in > dormancy_days.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'dormant' WHERE status = 'active' AND last_accessed_at < %s",
				$dormancy_cutoff
			)
		);

		// 2. Enforce per-user memory cap.
		if ( $per_user_max > 0 ) {
			self::enforce_per_user_cap( $table, $per_user_max );
		}

		// phpcs:enable
	}

	/**
	 * Weekly deep cleanup: prune expired memories, clean orphaned embeddings.
	 *
	 * @since 2.9.0
	 */
	public static function run_weekly_cleanup() {
		// 1. Prune memories past retention period.
		self::prune_expired_memories();

		// 2. Clean orphaned embeddings.
		self::clean_orphaned_embeddings();
	}

	/**
	 * Prune memories older than the retention period.
	 *
	 * @since 2.9.0
	 * @return int Number pruned.
	 */
	private static function prune_expired_memories() {
		$table = self::get_memories_table();
		if ( ! $table ) {
			return 0;
		}

		$retention_days = (int) WP_MCP_AI_Settings_Registry::get_setting( 'memory_retention_days', self::DEFAULT_MEMORY_RETENTION_DAYS );
		if ( $retention_days <= 0 ) {
			return 0;
		}

		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
		$limit  = self::MAX_DELETES_PER_RUN;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE cct_created < %s AND status = 'dormant' LIMIT %d",
				$cutoff,
				$limit
			)
		);
		// phpcs:enable

		return false !== $deleted ? (int) $deleted : 0;
	}

	/**
	 * Enforce per-user memory cap by deleting oldest dormant memories first.
	 *
	 * @since 2.9.0
	 * @param string $table        Table name.
	 * @param int    $max_per_user Maximum memories per user.
	 */
	private static function enforce_per_user_cap( $table, $max_per_user ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$over_limit = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cct_author_id, COUNT(*) AS cnt FROM `{$table}` WHERE cct_author_id > 0 GROUP BY cct_author_id HAVING cnt > %d ORDER BY cnt DESC LIMIT 20",
				$max_per_user
			)
		);
		// phpcs:enable

		if ( empty( $over_limit ) ) {
			return;
		}

		foreach ( $over_limit as $row ) {
			$user_id      = (int) $row->cct_author_id;
			$excess_count = (int) $row->cnt - $max_per_user;
			$to_delete    = min( $excess_count, 50 );

			// Delete oldest dormant memories for this user first.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE cct_author_id = %d AND status = 'dormant' ORDER BY cct_created ASC LIMIT %d",
					$user_id,
					$to_delete
				)
			);
			// phpcs:enable
		}
	}

	/**
	 * Clean orphaned embeddings that no longer map to an existing memory.
	 *
	 * @since 2.9.0
	 */
	private static function clean_orphaned_embeddings() {
		global $wpdb;

		// Check for embedding context records without corresponding memories.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$embedding_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_wp_mcp_ai_embedding_id'
			)
		);
		// phpcs:enable

		// Only run if there's a significant number to process.
		if ( $embedding_count < 100 ) {
			return;
		}

		// Find embeddings for posts that no longer exist.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$orphaned = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.ID IS NULL
				LIMIT %d",
				'_wp_mcp_ai_embedding_id',
				200
			)
		);
		// phpcs:enable

		foreach ( $orphaned as $post_id ) {
			delete_post_meta( $post_id, '_wp_mcp_ai_embedding_id' );
		}
	}

	/**
	 * Get the agent memories CCT table name.
	 *
	 * @since 2.9.0
	 * @return string Table name or empty string.
	 */
	private static function get_memories_table() {
		global $wpdb;

		$table = $wpdb->prefix . 'jet_cct_ai_chat_agent_memories';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $exists ? $table : '';
	}

	/**
	 * Get memory health statistics.
	 *
	 * @since 2.9.0
	 * @return array
	 */
	public static function get_memory_health_stats() {
		$table = self::get_memories_table();
		if ( ! $table ) {
			return array( 'available' => false );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dormant = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'dormant'" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active'" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$oldest = $wpdb->get_var( "SELECT cct_created FROM `{$table}` ORDER BY cct_created ASC LIMIT 1" );
		// phpcs:enable

		$retention_days = (int) WP_MCP_AI_Settings_Registry::get_setting( 'memory_retention_days', self::DEFAULT_MEMORY_RETENTION_DAYS );

		return array(
			'available'      => true,
			'total'          => $total,
			'active'         => $active,
			'dormant'        => $dormant,
			'oldest_date'    => $oldest,
			'retention_days' => $retention_days,
			'dormancy_days'  => (int) WP_MCP_AI_Settings_Registry::get_setting( 'memory_dormancy_days', self::DEFAULT_DORMANCY_DAYS ),
			'per_user_max'   => (int) WP_MCP_AI_Settings_Registry::get_setting( 'memory_per_user_max', self::DEFAULT_PER_USER_MAX ),
		);
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_Memory_Retention', 'init' ), 40 );
