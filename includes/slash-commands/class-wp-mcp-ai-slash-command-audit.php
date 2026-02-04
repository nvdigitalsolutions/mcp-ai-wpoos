<?php
/**
 * Slash Command Audit Logger
 *
 * Manages persistent audit logging for slash command execution.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slash Command Audit Logger Class
 *
 * Handles creation of audit table and provides query methods
 * for retrieving audit logs.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Slash_Command_Audit {

	/**
	 * Database table name
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'mcp_ai_slash_command_audit';
	}

	/**
	 * Create audit table
	 *
	 * @return bool True on success, false on failure.
	 */
	public function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			command varchar(255) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			duration_ms float NOT NULL,
			correlation_id varchar(100) NOT NULL,
			result text,
			timestamp datetime NOT NULL,
			ip_address varchar(45) NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY timestamp (timestamp),
			KEY correlation_id (correlation_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Check if table was created successfully.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) ) === $this->table_name;

		if ( $table_exists ) {
			update_option( 'wp_mcp_ai_slash_audit_table_version', '1.0' );
			return true;
		}

		return false;
	}

	/**
	 * Drop audit table
	 *
	 * @return bool True on success, false on failure.
	 */
	public function drop_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( "DROP TABLE IF EXISTS {$this->table_name}" );

		if ( false !== $result ) {
			delete_option( 'wp_mcp_ai_slash_audit_table_version' );
			return true;
		}

		return false;
	}

	/**
	 * Get audit logs with filters
	 *
	 * @param array $args Query arguments.
	 * @return array Array of log entries.
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'user_id'        => null,
			'status'         => null,
			'command'        => null,
			'correlation_id' => null,
			'date_from'      => null,
			'date_to'        => null,
			'limit'          => 100,
			'offset'         => 0,
			'order_by'       => 'timestamp',
			'order'          => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause.
		$where = array( '1=1' );

		if ( ! empty( $args['user_id'] ) ) {
			$where[] = $wpdb->prepare( 'user_id = %d', $args['user_id'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}

		if ( ! empty( $args['command'] ) ) {
			$where[] = $wpdb->prepare( 'command LIKE %s', '%' . $wpdb->esc_like( $args['command'] ) . '%' );
		}

		if ( ! empty( $args['correlation_id'] ) ) {
			$where[] = $wpdb->prepare( 'correlation_id = %s', $args['correlation_id'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[] = $wpdb->prepare( 'timestamp >= %s', $args['date_from'] );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[] = $wpdb->prepare( 'timestamp <= %s', $args['date_to'] );
		}

		$where_clause = implode( ' AND ', $where );

		// Build ORDER BY clause.
		$order_by = sanitize_sql_orderby( $args['order_by'] . ' ' . $args['order'] );
		if ( ! $order_by ) {
			$order_by = 'timestamp DESC';
		}

		// Build query.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$order_by}";

		if ( $args['limit'] > 0 ) {
			$query .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Get audit log by correlation ID
	 *
	 * @param string $correlation_id Correlation ID.
	 * @return array|null Log entry or null if not found.
	 */
	public function get_by_correlation_id( $correlation_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE correlation_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$correlation_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get audit log statistics
	 *
	 * @param array $args Query arguments.
	 * @return array Statistics.
	 */
	public function get_statistics( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'user_id'   => null,
			'date_from' => null,
			'date_to'   => null,
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause.
		$where = array( '1=1' );

		if ( ! empty( $args['user_id'] ) ) {
			$where[] = $wpdb->prepare( 'user_id = %d', $args['user_id'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[] = $wpdb->prepare( 'timestamp >= %s', $args['date_from'] );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[] = $wpdb->prepare( 'timestamp <= %s', $args['date_to'] );
		}

		$where_clause = implode( ' AND ', $where );

		// Get statistics.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$stats = $wpdb->get_row(
			"SELECT 
				COUNT(*) as total_executions,
				COUNT(DISTINCT user_id) as unique_users,
				COUNT(DISTINCT command) as unique_commands,
				AVG(duration_ms) as avg_duration,
				MIN(duration_ms) as min_duration,
				MAX(duration_ms) as max_duration,
				SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
			FROM {$this->table_name}
			WHERE {$where_clause}",
			ARRAY_A
		);

		return $stats;
	}

	/**
	 * Clean old audit logs
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of rows deleted.
	 */
	public function clean_old_logs( $days = 30 ) {
		global $wpdb;

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE timestamp < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff_date
			)
		);
	}
}
