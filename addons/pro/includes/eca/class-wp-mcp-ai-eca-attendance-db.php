<?php
/**
 * ECA Attendance Database — Schema and CRUD Operations
 *
 * Manages the {prefix}mcp_ai_eca_attendance table for tracking
 * student attendance at ECA sessions across tenants.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ECA Attendance database schema manager and CRUD layer.
 */
class WP_MCP_AI_ECA_Attendance_DB {

	/**
	 * Schema version stored in options.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key for schema version tracking.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'wp_mcp_ai_eca_attendance_db_version';

	/**
	 * Allowed attendance statuses.
	 *
	 * @var string[]
	 */
	const ALLOWED_STATUSES = array( 'present', 'absent', 'late', 'excused' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_tables' ) );
	}

	/**
	 * Check if tables need creating or updating.
	 *
	 * @return void
	 */
	public static function maybe_create_tables(): void {
		$installed = get_option( self::VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Create or update the ECA attendance database table.
	 *
	 * Safe to call multiple times — dbDelta only applies changes.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table_name = $wpdb->prefix . 'mcp_ai_eca_attendance';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$sql = "CREATE TABLE {$table_name} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_type     VARCHAR(20) NOT NULL DEFAULT 'school',
			tenant_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			eca_id          BIGINT(20) UNSIGNED NOT NULL,
			student_id      BIGINT(20) UNSIGNED NOT NULL,
			session_date    DATE NOT NULL,
			status          VARCHAR(20) NOT NULL DEFAULT 'present' COMMENT 'present, absent, late, excused',
			marked_by       BIGINT(20) UNSIGNED DEFAULT NULL,
			marked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			notes           TEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_unique (tenant_id, eca_id, student_id, session_date),
			KEY tenant_eca_date (tenant_type, tenant_id, eca_id, session_date)
		) {$charset_collate};";

		dbDelta( $sql );
		// phpcs:enable
	}

	/**
	 * Drop the attendance table (used in uninstall.php only).
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_ai_eca_attendance" );
		// phpcs:enable
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Validate an attendance status value.
	 *
	 * @param string $status The status to validate.
	 * @return bool True if valid.
	 */
	private static function is_valid_status( string $status ): bool {
		return in_array( $status, self::ALLOWED_STATUSES, true );
	}

	/**
	 * Mark attendance for a student at an ECA session.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE to safely upsert.
	 *
	 * @param int    $eca_id      ECA post ID.
	 * @param int    $student_id  Student post ID.
	 * @param string $date        Session date in Y-m-d format.
	 * @param string $status      Attendance status: present, absent, late, excused.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return int|WP_Error Row ID on success, WP_Error on failure.
	 */
	public static function mark( int $eca_id, int $student_id, string $date, string $status, string $tenant_type, int $tenant_id ) {
		global $wpdb;

		if ( $eca_id <= 0 ) {
			return new WP_Error( 'invalid_eca', __( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $student_id <= 0 ) {
			return new WP_Error( 'invalid_student', __( 'Invalid student ID.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_valid_status( $status ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: Invalid status value */
					__( 'Invalid attendance status: %s', 'mcp-ai-wpoos-pro' ),
					$status
				)
			);
		}
		if ( empty( $tenant_type ) ) {
			return new WP_Error( 'invalid_tenant_type', __( 'Tenant type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$table_name = $wpdb->prefix . 'mcp_ai_eca_attendance';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . esc_sql( $table_name ) . ' WHERE eca_id = %d AND student_id = %d AND session_date = %s AND tenant_type = %s AND tenant_id = %d',
				$eca_id,
				$student_id,
				$date,
				$tenant_type,
				$tenant_id
			)
		);
		// phpcs:enable

		$user_id = get_current_user_id();

		if ( $existing ) {
			// Update existing attendance record.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table_name,
				array(
					'status'    => $status,
					'marked_by' => $user_id ? $user_id : null,
					'marked_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			// phpcs:enable

			if ( false === $result ) {
				return new WP_Error( 'db_error', __( 'Failed to update attendance record.', 'mcp-ai-wpoos-pro' ) );
			}

			return (int) $existing;
		}

		// Insert new attendance record.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$table_name,
			array(
				'eca_id'       => $eca_id,
				'student_id'   => $student_id,
				'session_date' => $date,
				'status'       => $status,
				'tenant_type'  => $tenant_type,
				'tenant_id'    => $tenant_id,
				'marked_by'    => $user_id ? $user_id : null,
				'marked_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		// phpcs:enable

		if ( false === $result ) {
			return new WP_Error( 'db_error', __( 'Failed to create attendance record.', 'mcp-ai-wpoos-pro' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get attendance records for an ECA on a specific date.
	 *
	 * @param int    $eca_id      ECA post ID.
	 * @param string $date        Session date in Y-m-d format.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return array Array of attendance rows as associative arrays.
	 */
	public static function get_attendance( int $eca_id, string $date, string $tenant_type, int $tenant_id ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mcp_ai_eca_attendance';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT * FROM ' . esc_sql( $table_name ) . ' WHERE eca_id = %d AND session_date = %s AND tenant_type = %s AND tenant_id = %d ORDER BY student_id ASC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$eca_id,
				$date,
				$tenant_type,
				$tenant_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return $results ? $results : array();
	}

	/**
	 * Get all attendance records for a specific student within a tenant.
	 *
	 * @param int    $student_id  Student post ID.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return array Array of attendance rows as associative arrays.
	 */
	public static function get_student_attendance( int $student_id, string $tenant_type, int $tenant_id ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mcp_ai_eca_attendance';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT * FROM ' . esc_sql( $table_name ) . ' WHERE student_id = %d AND tenant_type = %s AND tenant_id = %d ORDER BY session_date DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$student_id,
				$tenant_type,
				$tenant_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return $results ? $results : array();
	}

	/**
	 * Calculate the attendance rate for an ECA within a date range.
	 *
	 * Rate = (present + late) / total * 100.
	 *
	 * @param int    $eca_id      ECA post ID.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @param string $from        Start date in Y-m-d format (inclusive).
	 * @param string $to          End date in Y-m-d format (inclusive).
	 * @return float Attendance rate percentage (0-100). Returns 100 if no sessions.
	 */
	public static function get_attendance_rate( int $eca_id, string $tenant_type, int $tenant_id, string $from, string $to ): float {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mcp_ai_eca_attendance';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT COUNT(*) FROM ' . esc_sql( $table_name ) . ' WHERE eca_id = %d AND tenant_type = %s AND tenant_id = %d AND session_date >= %s AND session_date <= %s';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$eca_id,
				$tenant_type,
				$tenant_id,
				$from,
				$to
			)
		);
		// phpcs:enable

		if ( 0 === $total ) {
			return 100.0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql_present = 'SELECT COUNT(*) FROM ' . esc_sql( $table_name ) . ' WHERE eca_id = %d AND tenant_type = %s AND tenant_id = %d AND session_date >= %s AND session_date <= %s AND status IN (%s, %s)';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$present = (int) $wpdb->get_var(
			$wpdb->prepare(
				$sql_present, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$eca_id,
				$tenant_type,
				$tenant_id,
				$from,
				$to,
				'present',
				'late'
			)
		);
		// phpcs:enable

		return round( ( $present / $total ) * 100, 2 );
	}
}
