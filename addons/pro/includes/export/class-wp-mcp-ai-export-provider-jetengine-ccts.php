<?php
/**
 * JetEngine CCTs Export Provider.
 *
 * Exports and imports JetEngine Custom Content Type table data
 * for AI-related CCTs: agent memories, chat transcripts, channel
 * contacts/messages, and vitals log.
 *
 * Conditional on JetEngine being active.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import provider for JetEngine CCT tables.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_JetEngine_CCTs extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Known AI-related CCT table slugs (without jet_cct_ prefix).
	 *
	 * @since 1.2.0
	 *
	 * @var string[]
	 */
	const CCT_SLUGS = array(
		'ai_agent_memories',
		'ai_chat_agent_memories',
		'ai_chat_transcripts',
		'channel_contacts',
		'channel_messages',
		'vitals_log',
	);

	/**
	 * Maximum rows per export query.
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
		return 'jetengine_ccts';
	}

	/**
	 * Get the human-readable label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'JetEngine CCT Data', 'mcp-ai-wpoos-pro' );
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
			'Custom Content Type tables: AI agent memories, chat transcripts, channel contacts/messages, vitals log.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Only available when JetEngine is active and loaded.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'Jet_Engine' );
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
	 * Sum of row counts for known AI-related CCT tables.
	 *
	 * Only counts existing tables to keep the UI badge accurate.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		global $wpdb;

		$total = 0;

		foreach ( self::CCT_SLUGS as $slug ) {
			$table = $wpdb->prefix . 'jet_cct_' . $slug;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count  = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from trusted constant slugs.
			$total += (int) $count;
		}

		return $total;
	}

	/**
	 * Export all known CCT table data.
	 *
	 * Iterates over the known CCT slugs, checks for table existence,
	 * and SELECT * each (up to MAX_ROWS). Returns a nested array
	 * keyed by table name.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function export(): array {
		global $wpdb;

		$result = array();

		foreach ( self::CCT_SLUGS as $slug ) {
			$table = $wpdb->prefix . 'jet_cct_' . $slug;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				$result[ $slug ] = array();
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from trusted constant slugs.
					self::MAX_ROWS
				),
				ARRAY_A
			);

			$result[ $slug ] = is_array( $rows ) ? $rows : array();
		}

		return $result;
	}

	/**
	 * Validate import data before committing.
	 *
	 * Checks that the data is an array and that all top-level keys
	 * correspond to known CCT slugs.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data ) {
		if ( empty( $data ) ) {
			return new \WP_Error(
				'jetengine_ccts_empty',
				__( 'JetEngine CCT data is empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'jetengine_ccts_invalid',
				__( 'JetEngine CCT data is not an array.', 'mcp-ai-wpoos-pro' )
			);
		}

		foreach ( $data as $slug => $rows ) {
			if ( ! is_string( $slug ) || ! in_array( $slug, self::CCT_SLUGS, true ) ) {
				return new \WP_Error(
					'jetengine_ccts_unknown_slug',
					sprintf(
						/* translators: %s: CCT slug */
						__( 'Unknown CCT slug "%s" in JetEngine data.', 'mcp-ai-wpoos-pro' ),
						(string) $slug
					)
				);
			}

			if ( ! is_array( $rows ) ) {
				return new \WP_Error(
					'jetengine_ccts_invalid_rows',
					sprintf(
						/* translators: %s: CCT slug */
						__( 'Data for CCT "%s" is not an array.', 'mcp-ai-wpoos-pro' ),
						(string) $slug
					)
				);
			}
		}

		$this->log_action( 'validated', true );

		return true;
	}

	/**
	 * Import CCT table data into the current site.
	 *
	 * For each CCT table: TRUNCATE first, then batch INSERT rows
	 * in groups of IMPORT_BATCH_SIZE. Skips tables that do not
	 * exist or have no row data.
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
				'jetengine_ccts_empty',
				__( 'No JetEngine CCT data to import.', 'mcp-ai-wpoos-pro' )
			);
		}

		$imported_tables = 0;

		foreach ( $data as $slug => $rows ) {
			if ( ! in_array( $slug, self::CCT_SLUGS, true ) ) {
				continue;
			}

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				continue;
			}

			$table = $wpdb->prefix . 'jet_cct_' . $slug;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from trusted constant slugs.

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
