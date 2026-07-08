<?php
/**
 * ECA Enrollments Database — Schema and CRUD Operations
 *
 * Manages the {prefix}mcp_ai_eca_enrollments table for tracking
 * student enrollment in Extra-Curricular Activities across tenants.
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
 * ECA Enrollments database schema manager and CRUD layer.
 */
class WP_MCP_AI_ECA_Enrollments_DB {

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
	const VERSION_OPTION = 'wp_mcp_ai_eca_enrollments_db_version';

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
	 * Create or update the ECA enrollments database table.
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

		$table_name = $wpdb->prefix . 'mcp_ai_eca_enrollments';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$sql = "CREATE TABLE {$table_name} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_type     VARCHAR(20) NOT NULL DEFAULT 'school',
			tenant_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			eca_id          BIGINT(20) UNSIGNED NOT NULL,
			student_id      BIGINT(20) UNSIGNED NOT NULL,
			status          VARCHAR(20) NOT NULL DEFAULT 'enrolled' COMMENT 'enrolled, waitlisted, withdrawn, completed',
			enrolled_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			withdrawn_at    DATETIME DEFAULT NULL,
			created_by      BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY enrollment_unique (tenant_id, eca_id, student_id),
			KEY tenant_eca (tenant_type, tenant_id, eca_id),
			KEY tenant_student (tenant_type, tenant_id, student_id),
			KEY status_lookup (tenant_id, status)
		) {$charset_collate};";

		dbDelta( $sql );
		// phpcs:enable
	}

	/**
	 * Drop the enrollments table (used in uninstall.php only).
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_ai_eca_enrollments" );
		// phpcs:enable
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Enroll a student in an ECA.
	 *
	 * Creates an enrollment record. Returns the enrollment ID on success
	 * or a WP_Error if the student is already enrolled or validation fails.
	 *
	 * @param int    $eca_id      ECA post ID.
	 * @param int    $student_id  Student post ID.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return int|WP_Error Enrollment row ID on success, WP_Error on failure.
	 */
	public static function enroll( int $eca_id, int $student_id, string $tenant_type, int $tenant_id ) {
		global $wpdb;

		// Validate required fields.
		if ( $eca_id <= 0 ) {
			return new WP_Error( 'invalid_eca', __( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $student_id <= 0 ) {
			return new WP_Error( 'invalid_student', __( 'Invalid student ID.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $tenant_type ) ) {
			return new WP_Error( 'invalid_tenant_type', __( 'Tenant type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check for existing enrollment.
		if ( self::is_enrolled( $student_id, $eca_id, $tenant_type, $tenant_id ) ) {
			return new WP_Error( 'already_enrolled', __( 'Student is already enrolled in this ECA.', 'mcp-ai-wpoos-pro' ) );
		}

		$table_name = $wpdb->prefix . 'mcp_ai_eca_enrollments';
		$user_id    = get_current_user_id();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert(
			$table_name,
			array(
				'eca_id'      => $eca_id,
				'student_id'  => $student_id,
				'tenant_type' => $tenant_type,
				'tenant_id'   => $tenant_id,
				'status'      => 'enrolled',
				'enrolled_at' => current_time( 'mysql' ),
				'created_by'  => $user_id ? $user_id : null,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%d' )
		);
		// phpcs:enable

		if ( false === $result ) {
			return new WP_Error(
				'db_error',
				__( 'Failed to create enrollment record.', 'mcp-ai-wpoos-pro' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Withdraw a student from an ECA by enrollment ID.
	 *
	 * Sets the status to 'withdrawn' and records the withdrawal timestamp.
	 *
	 * @param int $enrollment_id The enrollment row ID.
	 * @return bool True on success, false on failure.
	 */
	public static function withdraw( int $enrollment_id ): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mcp_ai_eca_enrollments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table_name,
			array(
				'status'       => 'withdrawn',
				'withdrawn_at' => current_time( 'mysql' ),
			),
			array( 'id' => $enrollment_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable

		return false !== $result;
	}

	/**
	 * Get all enrollments for a specific ECA within a tenant.
	 *
	 * @param int    $eca_id      ECA post ID.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return array Array of enrollment rows as associative arrays.
	 */
	public static function get_enrollments( int $eca_id, string $tenant_type, int $tenant_id ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mcp_ai_eca_enrollments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT * FROM ' . esc_sql( $table_name ) . ' WHERE eca_id = %d AND tenant_type = %s AND tenant_id = %d ORDER BY enrolled_at DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$eca_id,
				$tenant_type,
				$tenant_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return $results ? $results : array();
	}

	/**
	 * Get all enrollments for a specific student within a tenant.
	 *
	 * @param int    $student_id  Student post ID.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return array Array of enrollment rows as associative arrays.
	 */
	public static function get_student_enrollments( int $student_id, string $tenant_type, int $tenant_id ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mcp_ai_eca_enrollments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT * FROM ' . esc_sql( $table_name ) . ' WHERE student_id = %d AND tenant_type = %s AND tenant_id = %d ORDER BY enrolled_at DESC';
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
	 * Check if a student is currently enrolled in an ECA.
	 *
	 * Only checks for 'enrolled' or 'waitlisted' statuses.
	 *
	 * @param int    $student_id  Student post ID.
	 * @param int    $eca_id      ECA post ID.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return bool True if enrolled or waitlisted.
	 */
	public static function is_enrolled( int $student_id, int $eca_id, string $tenant_type, int $tenant_id ): bool {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mcp_ai_eca_enrollments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT COUNT(*) FROM ' . esc_sql( $table_name ) . ' WHERE student_id = %d AND eca_id = %d AND tenant_type = %s AND tenant_id = %d AND status IN (%s, %s)';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$student_id,
				$eca_id,
				$tenant_type,
				$tenant_id,
				'enrolled',
				'waitlisted'
			)
		);
		// phpcs:enable

		return $count > 0;
	}

	/**
	 * Count the number of actively enrolled students in an ECA.
	 *
	 * Counts both 'enrolled' and 'waitlisted' statuses.
	 *
	 * @param int    $eca_id      ECA post ID.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return int Number of active enrollments.
	 */
	public static function count_enrolled( int $eca_id, string $tenant_type, int $tenant_id ): int {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mcp_ai_eca_enrollments';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT COUNT(*) FROM ' . esc_sql( $table_name ) . ' WHERE eca_id = %d AND tenant_type = %s AND tenant_id = %d AND status IN (%s, %s)';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$eca_id,
				$tenant_type,
				$tenant_id,
				'enrolled',
				'waitlisted'
			)
		);
		// phpcs:enable

		return $count;
	}
}
