<?php
/**
 * Custom Tables Export Provider.
 *
 * Exports and imports custom database table data in two tiers:
 * Tier 1 (recommended, default checked) for core operational data,
 * and Tier 2 (unchecked by default) for high-volume audit/event/metrics data.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import provider for custom database tables.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_Custom_Tables extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Tier 1 tables — default checked, recommended for backup.
	 *
	 * These tables hold core operational data: embeddings, tenants,
	 * threads, and thread checkpoints.
	 *
	 * @since 1.2.0
	 *
	 * @var string[]
	 */
	const TIER_1 = array(
		'wp_mcp_ai_content_embeddings',
		'wp_mcp_ai_context_embeddings',
		'wp_mcp_ai_tool_embeddings',
		'mcp_ai_tenants',
		'mcp_ai_tenant_user_map',
		'mcp_ai_threads',
		'mcp_ai_thread_messages',
		'mcp_ai_thread_checkpoints',
	);

	/**
	 * Tier 2 tables — unchecked by default, high volume.
	 *
	 * These tables hold audit trails, compliance data, risk/control/evidence
	 * records, job queues, events, and metrics.
	 *
	 * @since 1.2.0
	 *
	 * @var string[]
	 */
	const TIER_2 = array(
		'mcp_ai_audit_trail',
		'mcp_ai_slash_command_audit',
		'mcp_ai_qms_audit',
		'mcp_ai_compliance_checks',
		'mcp_ai_risks',
		'mcp_ai_controls',
		'mcp_ai_evidence',
		'mcp_ai_eca_attendance',
		'mcp_ai_eca_enrollments',
		'mcp_ai_job_queue',
		'mcp_ai_events',
		'mcp_ai_metric_events',
		'mcp_ai_hourly_token_usage',
		'mcp_ai_custom_metrics',
	);

	/**
	 * All known table names (without prefix).
	 *
	 * @since 1.2.0
	 *
	 * @var string[]
	 */
	const ALL_TABLES = array(
		'wp_mcp_ai_content_embeddings',
		'wp_mcp_ai_context_embeddings',
		'wp_mcp_ai_tool_embeddings',
		'mcp_ai_tenants',
		'mcp_ai_tenant_user_map',
		'mcp_ai_threads',
		'mcp_ai_thread_messages',
		'mcp_ai_thread_checkpoints',
		'mcp_ai_audit_trail',
		'mcp_ai_slash_command_audit',
		'mcp_ai_qms_audit',
		'mcp_ai_compliance_checks',
		'mcp_ai_risks',
		'mcp_ai_controls',
		'mcp_ai_evidence',
		'mcp_ai_eca_attendance',
		'mcp_ai_eca_enrollments',
		'mcp_ai_job_queue',
		'mcp_ai_events',
		'mcp_ai_metric_events',
		'mcp_ai_hourly_token_usage',
		'mcp_ai_custom_metrics',
	);

	/**
	 * Maximum rows per export query and per import batch INSERT.
	 *
	 * @since 1.2.0
	 *
	 * @var int
	 */
	const MAX_ROWS = 50000;

	/**
	 * Import batch size (rows per INSERT statement).
	 *
	 * @since 1.2.0
	 *
	 * @var int
	 */
	const IMPORT_BATCH_SIZE = 500;

	/**
	 * Get the unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'custom_tables';
	}

	/**
	 * Get the human-readable label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Database Tables', 'mcp-ai-wpoos' );
	}

	/**
	 * Get the description for the UI.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'Custom table data: embeddings, threads, tenants, audit logs, job queues, and metrics. Large tables are chunked.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Always returns true — table existence is checked per-table during
	 * export and import, so a missing table simply produces an empty dataset.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Whether exported data contains sensitive values.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool {
		return false;
	}

	/**
	 * Total row count across Tier 1 tables only (kept fast).
	 *
	 * Tier 2 tables are excluded from the count to avoid expensive
	 * COUNT(*) queries on large audit/event/metrics tables.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		global $wpdb;

		$total = 0;

		foreach ( self::TIER_1 as $table_name ) {
			$table = $wpdb->prefix . $table_name;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count  = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from a trusted constant list.
			$total += (int) $count;
		}

		return $total;
	}

	/**
	 * Export all custom table data.
	 *
	 * Iterates over Tier 1 and Tier 2 tables, checks existence via
	 * SHOW TABLES, and SELECT * each (up to MAX_ROWS). Returns a nested
	 * array keyed by table name.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function export(): array {
		global $wpdb;

		$result = array();
		$tables = array_merge( self::TIER_1, self::TIER_2 );

		foreach ( $tables as $table_name ) {
			$table = $wpdb->prefix . $table_name;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				$result[ $table_name ] = array();
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from a trusted constant list.
					self::MAX_ROWS
				),
				ARRAY_A
			);

			$result[ $table_name ] = is_array( $rows ) ? $rows : array();
		}

		return $result;
	}

	/**
	 * Validate import data before committing.
	 *
	 * Checks that the data is an array and that all top-level keys
	 * correspond to known table names.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data ) {
		if ( empty( $data ) ) {
			return new \WP_Error(
				'custom_tables_empty',
				__( 'Custom table data is empty.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'custom_tables_invalid',
				__( 'Custom table data is not an array.', 'mcp-ai-wpoos' )
			);
		}

		foreach ( $data as $table_name => $rows ) {
			if ( ! is_string( $table_name ) || ! in_array( $table_name, self::ALL_TABLES, true ) ) {
				return new \WP_Error(
					'custom_tables_unknown_table',
					sprintf(
						/* translators: %s: table name */
						__( 'Unknown table "%s" in custom table data.', 'mcp-ai-wpoos' ),
						(string) $table_name
					)
				);
			}

			if ( ! is_array( $rows ) ) {
				return new \WP_Error(
					'custom_tables_invalid_rows',
					sprintf(
						/* translators: %s: table name */
						__( 'Data for table "%s" is not an array.', 'mcp-ai-wpoos' ),
						(string) $table_name
					)
				);
			}
		}

		$this->log_action( 'validated', true );

		return true;
	}

	/**
	 * Import custom table data into the current site.
	 *
	 * For each table: TRUNCATE first, then batch INSERT rows in groups
	 * of IMPORT_BATCH_SIZE. Skips tables that do not exist or have no
	 * row data.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function import( array $data ) {
		global $wpdb;

		if ( empty( $data ) ) {
			return new \WP_Error(
				'custom_tables_empty',
				__( 'No custom table data to import.', 'mcp-ai-wpoos' )
			);
		}

		$imported_tables = 0;

		foreach ( $data as $table_name => $rows ) {
			if ( ! in_array( $table_name, self::ALL_TABLES, true ) ) {
				continue;
			}

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				continue;
			}

			$table = $wpdb->prefix . $table_name;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from a trusted constant list.

			// Batch INSERT.
			$chunks = array_chunk( $rows, self::IMPORT_BATCH_SIZE );

			foreach ( $chunks as $chunk ) {
				$first_row    = reset( $chunk );
				$columns      = array_keys( $first_row );
				$col_list     = '`' . implode( '`, `', array_map( 'esc_sql', $columns ) ) . '`';
				$placeholders = array();
				$values       = array();

				foreach ( $chunk as $row ) {
					$row_placeholders = array();
					foreach ( $columns as $col ) {
						$row_placeholders[] = isset( $row[ $col ] ) ? '%s' : '%s';
						$values[]           = isset( $row[ $col ] ) ? $row[ $col ] : '';
					}
					$placeholders[] = '(' . implode( ', ', $row_placeholders ) . ')';
				}

				$sql = "INSERT INTO `{$table}` ({$col_list}) VALUES " . implode( ', ', $placeholders );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is dynamically built from trusted column and table names; values are prepared via $wpdb->prepare.
			}

			++$imported_tables;
		}

		$this->log_action(
			'imported',
			array(
				'provider' => $this->get_id(),
				'tables'   => $imported_tables,
			)
		);

		return true;
	}
}
