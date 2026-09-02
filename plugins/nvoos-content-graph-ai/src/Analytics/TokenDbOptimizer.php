<?php
/**
 * Token DB Optimizer for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-token-db-optimizer.php` (behaviour-preserving;
 * base copy retained permanently — ecosystem port plan D-NOBASE). Schema
 * version bookkeeping, usermeta index definitions, stats, and EXPLAIN
 * analysis are byte-identical.
 *
 * Decoupling (documented, additive): `init()` (admin_init) is registered
 * standalone-only by `Plugin.php`; logging forwards to the base logger in
 * monolith installs only.
 *
 * @package NvoosContentGraphAi\Analytics
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token management database optimizer.
 *
 * @since 1.1.0
 */
class TokenDbOptimizer {

	const SCHEMA_VERSION_OPTION  = 'wp_mcp_ai_token_db_version';
	const CURRENT_SCHEMA_VERSION = 1;

	/**
	 * Initialize database optimizations.
	 *
	 * @return void
	 */
	public static function init() {
		// Check if database needs optimization on admin_init.
		add_action( 'admin_init', array( __CLASS__, 'maybe_optimize_database' ) );
	}

	/**
	 * Check if database optimization is needed and run if necessary.
	 *
	 * @return void
	 */
	public static function maybe_optimize_database() {
		$current_version = get_option( self::SCHEMA_VERSION_OPTION, 0 );

		if ( $current_version < self::CURRENT_SCHEMA_VERSION ) {
			self::optimize_database();
			update_option( self::SCHEMA_VERSION_OPTION, self::CURRENT_SCHEMA_VERSION, false );
		}
	}

	/**
	 * Run database optimizations.
	 *
	 * @return void
	 */
	public static function optimize_database() {
		global $wpdb;

		// Check if indexes already exist before attempting to create them.
		$indexes = self::get_existing_indexes();

		// Add index for tier lookups (meta_key + user_id).
		if ( ! in_array( 'idx_wp_mcp_ai_token_tier', $indexes, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ALTER TABLE/ADD INDEX does not support placeholders; $wpdb->usermeta is an internal trusted property, not user input.
			$wpdb->query(
				"ALTER TABLE `{$wpdb->usermeta}`
				ADD INDEX idx_wp_mcp_ai_token_tier (meta_key(50), user_id)"
			);
		}

		// Add index for usage data lookups.
		if ( ! in_array( 'idx_wp_mcp_ai_usage', $indexes, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ALTER TABLE/ADD INDEX does not support placeholders; $wpdb->usermeta is an internal trusted property, not user input.
			$wpdb->query(
				"ALTER TABLE `{$wpdb->usermeta}`
				ADD INDEX idx_wp_mcp_ai_usage (meta_key(50), user_id)"
			);
		}

		// Log optimization completion.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'token_db_optimized',
				'Token management database indexes created/verified.',
				array(
					'schema_version' => self::CURRENT_SCHEMA_VERSION,
					'indexes_added'  => array_diff(
						array( 'idx_wp_mcp_ai_token_tier', 'idx_wp_mcp_ai_usage' ),
						$indexes
					),
				)
			);
		}
	}

	/**
	 * Get list of existing indexes on usermeta table.
	 *
	 * @return array List of index names.
	 */
	protected static function get_existing_indexes() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW INDEX does not support placeholders; $wpdb->usermeta is an internal trusted property, not user input.
		$indexes = $wpdb->get_results(
			"SHOW INDEX FROM `{$wpdb->usermeta}`",
			ARRAY_A
		);

		$index_names = array();
		if ( is_array( $indexes ) ) {
			foreach ( $indexes as $index ) {
				if ( isset( $index['Key_name'] ) ) {
					$index_names[] = $index['Key_name'];
				}
			}
		}

		return array_unique( $index_names );
	}

	/**
	 * Remove database optimizations (for plugin uninstall).
	 *
	 * @return bool True if successful.
	 */
	public static function remove_optimizations() {
		global $wpdb;

		$indexes = self::get_existing_indexes();

		// Remove token tier index.
		if ( in_array( 'idx_wp_mcp_ai_token_tier', $indexes, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ALTER TABLE/DROP INDEX does not support placeholders; $wpdb->usermeta is an internal trusted property, not user input.
			$wpdb->query(
				"ALTER TABLE `{$wpdb->usermeta}` DROP INDEX idx_wp_mcp_ai_token_tier"
			);
		}

		// Remove usage index.
		if ( in_array( 'idx_wp_mcp_ai_usage', $indexes, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ALTER TABLE/DROP INDEX does not support placeholders; $wpdb->usermeta is an internal trusted property, not user input.
			$wpdb->query(
				"ALTER TABLE `{$wpdb->usermeta}` DROP INDEX idx_wp_mcp_ai_usage"
			);
		}

		// Remove schema version option.
		delete_option( self::SCHEMA_VERSION_OPTION );

		return true;
	}

	/**
	 * Get database optimization statistics.
	 *
	 * @return array Stats about database optimizations.
	 */
	public static function get_optimization_stats() {
		global $wpdb;

		$indexes = self::get_existing_indexes();

		// Get count of token-related user meta records.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table.
		$tier_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '_wp_mcp_ai_token_tier'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table.
		$usage_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = '_wp_mcp_ai_tool_token_usage'"
		);

		return array(
			'schema_version'       => get_option( self::SCHEMA_VERSION_OPTION, 0 ),
			'optimizations_active' => get_option( self::SCHEMA_VERSION_OPTION, 0 ) >= self::CURRENT_SCHEMA_VERSION,
			'tier_index_exists'    => in_array( 'idx_wp_mcp_ai_token_tier', $indexes, true ),
			'usage_index_exists'   => in_array( 'idx_wp_mcp_ai_usage', $indexes, true ),
			'tier_records'         => absint( $tier_count ),
			'usage_records'        => absint( $usage_count ),
			'total_indexes'        => count( $indexes ),
			'wp_mcp_ai_indexes'    => count(
				array_filter(
					$indexes,
					function ( $index ) {
						return strpos( $index, 'wp_mcp_ai' ) !== false;
					}
				)
			),
		);
	}

	/**
	 * Analyze query performance for token lookups.
	 *
	 * @return array Analysis results.
	 */
	public static function analyze_query_performance() {
		global $wpdb;

		$results = array(
			'tier_lookup'  => array(),
			'usage_lookup' => array(),
		);

		// Analyze tier lookup query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table.
		$tier_explain = $wpdb->get_results(
			$wpdb->prepare(
				"EXPLAIN SELECT meta_value FROM {$wpdb->usermeta}
				WHERE meta_key = %s AND user_id = %d",
				'_wp_mcp_ai_token_tier',
				1
			),
			ARRAY_A
		);

		if ( is_array( $tier_explain ) && ! empty( $tier_explain ) ) {
			$results['tier_lookup'] = array(
				'using_index' => isset( $tier_explain[0]['key'] ) && null !== $tier_explain[0]['key'],
				'index_name'  => $tier_explain[0]['key'] ?? 'none',
				'rows'        => $tier_explain[0]['rows'] ?? 0,
				'type'        => $tier_explain[0]['type'] ?? 'unknown',
			);
		}

		// Analyze usage lookup query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table.
		$usage_explain = $wpdb->get_results(
			$wpdb->prepare(
				"EXPLAIN SELECT meta_value FROM {$wpdb->usermeta}
				WHERE meta_key = %s AND user_id = %d",
				'_wp_mcp_ai_tool_token_usage',
				1
			),
			ARRAY_A
		);

		if ( is_array( $usage_explain ) && ! empty( $usage_explain ) ) {
			$results['usage_lookup'] = array(
				'using_index' => isset( $usage_explain[0]['key'] ) && null !== $usage_explain[0]['key'],
				'index_name'  => $usage_explain[0]['key'] ?? 'none',
				'rows'        => $usage_explain[0]['rows'] ?? 0,
				'type'        => $usage_explain[0]['type'] ?? 'unknown',
			);
		}

		return $results;
	}
}
