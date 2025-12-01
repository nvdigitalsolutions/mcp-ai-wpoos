<?php
/**
 * Token Tracking Database Management
 *
 * Manages the enhanced hourly token usage tracking database table with
 * provider, model, and cost information for accurate cost attribution.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token Tracking Database class.
 *
 * Handles database schema creation, migration, and data access for
 * enhanced token tracking with real-time cost attribution.
 */
class WP_MCP_AI_Token_Tracking_Database {

	/**
	 * Database version for schema migrations.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key for storing database version.
	 */
	const DB_VERSION_OPTION = 'wp_mcp_ai_token_tracking_db_version';

	/**
	 * Table name (without prefix).
	 */
	const TABLE_NAME = 'mcp_ai_hourly_token_usage';

	/**
	 * Initialize the database management.
	 */
	public static function init() {
		// Create/update table on plugin activation or upgrade.
		add_action( 'wp_mcp_ai_plugin_activated', array( __CLASS__, 'maybe_create_or_update_table' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_or_update_table' ) );
	}

	/**
	 * Get the full table name with WordPress prefix.
	 *
	 * @return string Full table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Check if table creation or update is needed.
	 */
	public static function maybe_create_or_update_table() {
		$current_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( $current_version, self::DB_VERSION, '<' ) ) {
			self::create_or_update_table();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Create or update the hourly token usage table.
	 *
	 * Schema includes:
	 * - id: Primary key
	 * - user_id: WordPress user ID
	 * - tool: Tool slug
	 * - provider: AI provider (openai, gemini, anthropic, ollama, lm_studio)
	 * - model: Model identifier (gpt-4o, gemini-1.5-pro, etc.)
	 * - input_tokens: Input/prompt tokens
	 * - output_tokens: Output/completion tokens
	 * - total_tokens: Total tokens (input + output)
	 * - cost_usd: Calculated cost in USD
	 * - is_estimated: Whether cost is estimated (1) or actual (0)
	 * - timestamp: When the usage occurred
	 * - created_at: When the record was created
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public static function create_or_update_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			tool varchar(100) NOT NULL DEFAULT '',
			provider varchar(50) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			input_tokens bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			output_tokens bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			total_tokens bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			cost_usd decimal(10,4) NOT NULL DEFAULT 0.0000,
			is_estimated tinyint(1) NOT NULL DEFAULT 1,
			timestamp datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY tool (tool),
			KEY provider (provider),
			KEY timestamp (timestamp),
			KEY user_timestamp (user_id,timestamp)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Verify table was created.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( $table_exists !== $table_name ) {
			error_log( 'WP MCP AI: Failed to create token tracking table: ' . $table_name );
		}
	}

	/**
	 * Record token usage with enhanced tracking.
	 *
	 * @param int    $user_id       WordPress user ID.
	 * @param string $tool          Tool slug.
	 * @param string $provider      AI provider.
	 * @param string $model         Model identifier.
	 * @param int    $input_tokens  Input/prompt tokens.
	 * @param int    $output_tokens Output/completion tokens.
	 * @param float  $cost_usd      Cost in USD (optional, will calculate if not provided).
	 * @param bool   $is_estimated  Whether cost is estimated.
	 * @param string $timestamp     Timestamp (defaults to current time).
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function record_usage( $user_id, $tool, $provider, $model, $input_tokens, $output_tokens, $cost_usd = null, $is_estimated = true, $timestamp = null ) {
		global $wpdb;

		// Validate inputs.
		$user_id       = absint( $user_id );
		$tool          = sanitize_text_field( $tool );
		$provider      = sanitize_text_field( $provider );
		$model         = sanitize_text_field( $model );
		$input_tokens  = absint( $input_tokens );
		$output_tokens = absint( $output_tokens );
		$total_tokens  = $input_tokens + $output_tokens;

		if ( ! $user_id || ! $provider || ! $model || $total_tokens === 0 ) {
			return false;
		}

		// Calculate cost if not provided.
		if ( null === $cost_usd && class_exists( 'WP_MCP_AI_Cost_Calculator' ) ) {
			$cost_usd = WP_MCP_AI_Cost_Calculator::calculate_cost(
				$provider,
				$model,
				$input_tokens,
				$output_tokens
			);
		}

		$cost_usd = floatval( $cost_usd );

		// Use current time if not provided.
		if ( null === $timestamp ) {
			$timestamp = current_time( 'mysql' );
		}

		$data = array(
			'user_id'       => $user_id,
			'tool'          => $tool,
			'provider'      => $provider,
			'model'         => $model,
			'input_tokens'  => $input_tokens,
			'output_tokens' => $output_tokens,
			'total_tokens'  => $total_tokens,
			'cost_usd'      => $cost_usd,
			'is_estimated'  => $is_estimated ? 1 : 0,
			'timestamp'     => $timestamp,
			'created_at'    => current_time( 'mysql' ),
		);

		$format = array(
			'%d', // user_id.
			'%s', // tool.
			'%s', // provider.
			'%s', // model.
			'%d', // input_tokens.
			'%d', // output_tokens.
			'%d', // total_tokens.
			'%f', // cost_usd.
			'%d', // is_estimated.
			'%s', // timestamp.
			'%s', // created_at.
		);

		$result = $wpdb->insert( self::get_table_name(), $data, $format );

		if ( false === $result ) {
			error_log( 'WP MCP AI: Failed to insert token usage record: ' . $wpdb->last_error );
			return false;
		}

		/**
		 * Fires after token usage has been recorded in the enhanced tracking system.
		 *
		 * @param int    $insert_id     The inserted record ID.
		 * @param int    $user_id       WordPress user ID.
		 * @param string $tool          Tool slug.
		 * @param string $provider      AI provider.
		 * @param string $model         Model identifier.
		 * @param int    $input_tokens  Input tokens.
		 * @param int    $output_tokens Output tokens.
		 * @param float  $cost_usd      Cost in USD.
		 * @param bool   $is_estimated  Whether cost is estimated.
		 */
		do_action(
			'wp_mcp_ai_token_usage_recorded',
			$wpdb->insert_id,
			$user_id,
			$tool,
			$provider,
			$model,
			$input_tokens,
			$output_tokens,
			$cost_usd,
			$is_estimated
		);

		return $wpdb->insert_id;
	}

	/**
	 * Get token usage for a user within a date range.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date   End date (Y-m-d H:i:s).
	 * @param string $tool       Optional tool filter.
	 * @return array Array of usage records.
	 */
	public static function get_user_usage( $user_id, $start_date, $end_date, $tool = '' ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}

		$table_name = self::get_table_name();
		$where      = array( 'user_id = %d' );
		$params     = array( $user_id );

		if ( $start_date ) {
			$where[]  = 'timestamp >= %s';
			$params[] = $start_date;
		}

		if ( $end_date ) {
			$where[]  = 'timestamp <= %s';
			$params[] = $end_date;
		}

		if ( $tool ) {
			$where[]  = 'tool = %s';
			$params[] = sanitize_text_field( $tool );
		}

		$where_clause = implode( ' AND ', $where );

		$query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY timestamp DESC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get cost summary for a user.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date   End date (Y-m-d H:i:s).
	 * @return array Cost summary with total_cost, total_tokens, estimated_cost, actual_cost.
	 */
	public static function get_user_cost_summary( $user_id, $start_date, $end_date ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array(
				'total_cost'     => 0.0,
				'total_tokens'   => 0,
				'estimated_cost' => 0.0,
				'actual_cost'    => 0.0,
			);
		}

		$table_name = self::get_table_name();

		$query = "
			SELECT 
				SUM(cost_usd) as total_cost,
				SUM(total_tokens) as total_tokens,
				SUM(CASE WHEN is_estimated = 1 THEN cost_usd ELSE 0 END) as estimated_cost,
				SUM(CASE WHEN is_estimated = 0 THEN cost_usd ELSE 0 END) as actual_cost
			FROM {$table_name}
			WHERE user_id = %d
			AND timestamp >= %s
			AND timestamp <= %s
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_row( $wpdb->prepare( $query, $user_id, $start_date, $end_date ), ARRAY_A );

		if ( ! $result ) {
			return array(
				'total_cost'     => 0.0,
				'total_tokens'   => 0,
				'estimated_cost' => 0.0,
				'actual_cost'    => 0.0,
			);
		}

		return array(
			'total_cost'     => floatval( $result['total_cost'] ),
			'total_tokens'   => intval( $result['total_tokens'] ),
			'estimated_cost' => floatval( $result['estimated_cost'] ),
			'actual_cost'    => floatval( $result['actual_cost'] ),
		);
	}

	/**
	 * Get aggregated usage data by provider.
	 *
	 * Data access method - returns raw query results for service layer to process.
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date   End date (Y-m-d H:i:s).
	 * @return array Array of provider aggregations.
	 */
	public static function get_aggregated_by_provider( $start_date, $end_date ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$query = "
			SELECT 
				provider,
				SUM(cost_usd) as total_cost,
				SUM(total_tokens) as total_tokens
			FROM {$table_name}
			WHERE timestamp >= %s
			AND timestamp <= %s
			GROUP BY provider
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date ), ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get aggregated usage data by model.
	 *
	 * Data access method - returns raw query results for service layer to process.
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date   End date (Y-m-d H:i:s).
	 * @return array Array of model aggregations.
	 */
	public static function get_aggregated_by_model( $start_date, $end_date ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$query = "
			SELECT 
				provider,
				model,
				SUM(cost_usd) as total_cost,
				SUM(total_tokens) as total_tokens
			FROM {$table_name}
			WHERE timestamp >= %s
			AND timestamp <= %s
			GROUP BY provider, model
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date ), ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get aggregated usage data by tool.
	 *
	 * Data access method - returns raw query results for service layer to process.
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date   End date (Y-m-d H:i:s).
	 * @return array Array of tool aggregations.
	 */
	public static function get_aggregated_by_tool( $start_date, $end_date ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$query = "
			SELECT 
				tool,
				SUM(cost_usd) as total_cost,
				SUM(total_tokens) as total_tokens
			FROM {$table_name}
			WHERE timestamp >= %s
			AND timestamp <= %s
			GROUP BY tool
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date ), ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get aggregated usage data by date.
	 *
	 * Data access method - returns raw query results for service layer to process.
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date   End date (Y-m-d H:i:s).
	 * @return array Array of date aggregations.
	 */
	public static function get_aggregated_by_date( $start_date, $end_date ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$query = "
			SELECT 
				DATE(timestamp) as date,
				SUM(cost_usd) as total_cost,
				SUM(total_tokens) as total_tokens
			FROM {$table_name}
			WHERE timestamp >= %s
			AND timestamp <= %s
			GROUP BY DATE(timestamp)
			ORDER BY DATE(timestamp)
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date ), ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get aggregated usage data by user.
	 *
	 * Data access method - returns raw query results for service layer to process.
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date   End date (Y-m-d H:i:s).
	 * @return array Array of user aggregations.
	 */
	public static function get_aggregated_by_user( $start_date, $end_date ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$query = "
			SELECT 
				user_id,
				SUM(cost_usd) as total_cost,
				SUM(total_tokens) as total_tokens
			FROM {$table_name}
			WHERE timestamp >= %s
			AND timestamp <= %s
			GROUP BY user_id
			ORDER BY total_cost DESC
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date ), ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Clean up old usage records (older than retention period).
	 *
	 * @param int $days Number of days to retain (default: 90).
	 * @return int Number of rows deleted.
	 */
	public static function cleanup_old_records( $days = 90 ) {
		global $wpdb;

		$days       = absint( $days );
		$table_name = self::get_table_name();

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE timestamp < %s",
				$cutoff_date
			)
		);

		return intval( $deleted );
	}

	/**
	 * Drop the table (for uninstall).
	 *
	 * WARNING: This deletes all usage data permanently.
	 */
	public static function drop_table() {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		delete_option( self::DB_VERSION_OPTION );
	}
}
