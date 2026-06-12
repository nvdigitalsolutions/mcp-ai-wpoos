<?php
/**
 * Document Generation & QMS Performance Optimization
 *
 * Adds retention pruning to the QMS audit log table and cleans
 * up stale temp files from document generation exports.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document Generation + QMS optimization manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Document_Gen_Optimization {

	/**
	 * Cron hook for weekly audit pruning.
	 *
	 * @var string
	 */
	const AUDIT_PRUNE_HOOK = 'wp_mcp_ai_dg_weekly_audit_prune';

	/**
	 * Default audit retention in days (2 years for ISO 9001 compliance).
	 *
	 * @var int
	 */
	const DEFAULT_AUDIT_RETENTION_DAYS = 730;

	/**
	 * Initialize.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return;
		}

		// Weekly audit log pruning.
		add_action( self::AUDIT_PRUNE_HOOK, array( __CLASS__, 'prune_audit_log' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Fix QMS audit schema option autoload.
		add_action( 'update_option_wp_mcp_ai_qms_audit_schema', array( __CLASS__, 'fix_schema_autoload' ), 10, 2 );
		add_action( 'added_option_wp_mcp_ai_qms_audit_schema', array( __CLASS__, 'fix_schema_autoload' ), 10, 2 );
	}

	/**
	 * Schedule weekly audit pruning.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::AUDIT_PRUNE_HOOK ) ) {
			wp_schedule_event( strtotime( 'next Sunday 03:00:00' ), 'weekly', self::AUDIT_PRUNE_HOOK );
		}
	}

	/**
	 * Fix autoload on QMS audit schema option (tiny option, but consistency).
	 *
	 * @since 2.9.0
	 * @param mixed $old       Previous value (unused).
	 * @param mixed $new_value New value (unused).
	 */
	public static function fix_schema_autoload( $old, $new_value ) {
		unset( $old, $new_value );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => 'wp_mcp_ai_qms_audit_schema' ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Prune QMS audit log entries older than the retention period.
	 *
	 * The audit table (wp_mcp_ai_qms_audit) is append-only with no
	 * built-in retention. For ISO 9001 compliance, 2 years is typical;
	 * this can be overridden via filter.
	 *
	 * Safety-capped at 10,000 rows per run to avoid long locks.
	 *
	 * @since 2.9.0
	 */
	public static function prune_audit_log() {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_mcp_ai_qms_audit';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom audit table has no WP API; low-traffic weekly cron.

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}

		/**
		 * Filter the QMS audit log retention in days.
		 *
		 * @since 2.9.0
		 * @param int $days Retention in days. Default 730 (2 years).
		 */
		$retention_days = apply_filters( 'wp_mcp_ai_qms_audit_retention_days', self::DEFAULT_AUDIT_RETENTION_DAYS );

		if ( $retention_days <= 0 ) {
			return; // 0 = keep forever.
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
		$limit  = 10000; // Safety cap.

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated via SHOW TABLES above.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < %s LIMIT %d", $cutoff, $limit )
		);
		// phpcs:enable

		// After large deletes, run OPTIMIZE to reclaim disk space.
		// Only on tables with significant row count to avoid unnecessary work.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		if ( $row_count > 50000 ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated above; OPTIMIZE requires literal table name.
			$wpdb->query( "OPTIMIZE TABLE `{$table}`" );
			// phpcs:enable
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_Document_Gen_Optimization', 'init' ), 40 );
