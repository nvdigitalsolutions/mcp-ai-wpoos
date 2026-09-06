<?php
/**
 * Metric Event Store
 *
 * Owns the `{prefix}mcp_ai_metric_events` custom table. Provides schema
 * migration, batched writes, time-range queries, and retention purge.
 *
 * Design rules this class enforces:
 *   1. **Restricted tier is never persisted.** `privacy-matrix.md`
 *      commits to "restricted raw never persisted (in-memory + immediate
 *      aggregate)"; that invariant is stronger than the rollout-plan
 *      7d retention and wins. The persister drops restricted events
 *      before they reach this store, and this store also drops them
 *      defensively as a second barrier.
 *   2. **Schema is migration-driven**, not assumed. `{table_name}`
 *      tracks the installed schema version in
 *      `wp_mcp_ai_metric_events_schema_version`. `install()` is
 *      idempotent — calling it on every page load (via the bootstrap)
 *      is cheap because dbDelta short-circuits when the schema matches.
 *   3. **Writes are always batched.** Single-event writes would add an
 *      INSERT per `do_action('wp_mcp_ai_metric_recorded')`. The store
 *      exposes `insert_batch( array $events )` and the persister
 *      accumulates per-request and flushes once on `shutdown`.
 *   4. **Queries are always bounded.** No unbounded `SELECT *`. The
 *      query methods require either a time range or a LIMIT.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metric event store.
 */
class MetricEventStore {

	/**
	 * Table base name (without wpdb prefix).
	 */
	const TABLE_BASE = 'mcp_ai_metric_events';

	/**
	 * Schema version. Bump when the SQL changes; dbDelta will migrate.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Option key that records the installed schema version.
	 */
	const SCHEMA_OPTION = 'wp_mcp_ai_metric_events_schema_version';

	/**
	 * Maximum rows per batch INSERT. Chosen to stay well inside
	 * MySQL's default `max_allowed_packet` (4MB on most hosts) with
	 * generous headroom for large context JSON blobs.
	 */
	const MAX_BATCH_ROWS = 200;

	/**
	 * Singleton instance.
	 *
	 * @var MetricEventStore|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MetricEventStore
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (tests only).
	 *
	 * @return void
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Resolve the full table name including wpdb prefix.
	 *
	 * @return string
	 */
	public function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	/**
	 * Install (or upgrade) the schema. Idempotent and cheap on repeat
	 * calls because dbDelta short-circuits when the table matches.
	 *
	 * @return bool True when the installed schema version matches SCHEMA_VERSION.
	 */
	public function install() {
		global $wpdb;

		$installed = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( self::SCHEMA_VERSION === $installed && $this->table_exists() ) {
			return true;
		}

		$table_name      = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			metric_id VARCHAR(128) NOT NULL,
			metric_value DOUBLE NOT NULL,
			metric_type VARCHAR(32) NOT NULL,
			metric_unit VARCHAR(32) NOT NULL,
			privacy VARCHAR(16) NOT NULL,
			recorded_at DATETIME NOT NULL,
			context LONGTEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY metric_recorded (metric_id, recorded_at),
			KEY privacy_recorded (privacy, recorded_at)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		return $this->table_exists();
	}

	/**
	 * Drop the table. Used by uninstall and test teardown only.
	 *
	 * @return void
	 */
	public function drop() {
		global $wpdb;
		$table_name = esc_sql( $this->table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query on custom plugin table; table name from class constant, not user input. WP_Query does not support custom table DDL.
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
		delete_option( self::SCHEMA_OPTION );
	}

	/**
	 * Whether the backing table exists in the database.
	 *
	 * @return bool
	 */
	public function table_exists() {
		global $wpdb;
		$table_name = $this->table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query on custom plugin table; table name from class constant, not user input. WP_Query does not support SHOW TABLES.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return $found === $table_name;
	}

	/**
	 * Insert a batch of metric events.
	 *
	 * Events with `privacy == restricted` are dropped silently — this
	 * is a defensive barrier; the persister should already have
	 * dropped them.
	 *
	 * @param array<int,array<string,mixed>> $events Events from the collector.
	 * @return int Number of rows written.
	 */
	public function insert_batch( array $events ) {
		global $wpdb;

		if ( array() === $events ) {
			return 0;
		}
		if ( ! $this->table_exists() ) {
			return 0;
		}

		$rows = array();
		foreach ( $events as $event ) {
			$row = $this->event_to_row( $event );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}
		if ( array() === $rows ) {
			return 0;
		}

		$table_name = $this->table_name();
		$written    = 0;

		// Chunk into batches bounded by MAX_BATCH_ROWS to keep the
		// generated SQL well under max_allowed_packet.
		foreach ( array_chunk( $rows, self::MAX_BATCH_ROWS ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s, %f, %s, %s, %s, %s, %s)';
				array_push(
					$values,
					$row['metric_id'],
					$row['metric_value'],
					$row['metric_type'],
					$row['metric_unit'],
					$row['privacy'],
					$row['recorded_at'],
					$row['context']
				);
			}

			$sql = 'INSERT INTO ' . $table_name
				. ' (metric_id, metric_value, metric_type, metric_unit, privacy, recorded_at, context) VALUES '
				. implode( ', ', $placeholders );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Batch insert on custom plugin table; dynamic SQL construction is necessary for variable batch sizes. WP_Query does not support custom table inserts.
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
			if ( false !== $result ) {
				$written += (int) $result;
			}
		}

		return $written;
	}

	/**
	 * Translate a buffered collector event into a DB row. Returns null
	 * when the event is invalid or must be dropped (restricted tier).
	 *
	 * @param array<string,mixed> $event Buffered event.
	 * @return array<string,mixed>|null
	 */
	private function event_to_row( array $event ) {
		if ( empty( $event['id'] ) || ! is_string( $event['id'] ) ) {
			return null;
		}
		if ( ! isset( $event['value'] ) || ! is_numeric( $event['value'] ) ) {
			return null;
		}

		$privacy = isset( $event['privacy'] ) ? (string) $event['privacy'] : '';
		if ( MeasurementRegistry::PRIVACY_RESTRICTED === $privacy ) {
			// Defensive barrier: restricted events must never land in the table.
			return null;
		}

		$allowed_privacies = array(
			MeasurementRegistry::PRIVACY_PUBLIC,
			MeasurementRegistry::PRIVACY_INTERNAL,
			MeasurementRegistry::PRIVACY_SENSITIVE,
		);
		if ( ! in_array( $privacy, $allowed_privacies, true ) ) {
			// Unknown tier — treat as internal by default rather than dropping
			// so operators still get visibility while they fix the classification.
			$privacy = MeasurementRegistry::PRIVACY_INTERNAL;
		}

		$timestamp = isset( $event['timestamp'] ) && is_numeric( $event['timestamp'] )
			? (int) $event['timestamp']
			: time();

		$context      = isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array();
		$context_json = wp_json_encode( $context );
		if ( false === $context_json ) {
			$context_json = '{}';
		}

		return array(
			'metric_id'    => substr( (string) $event['id'], 0, 128 ),
			'metric_value' => (float) $event['value'],
			'metric_type'  => substr( isset( $event['type'] ) ? (string) $event['type'] : '', 0, 32 ),
			'metric_unit'  => substr( isset( $event['unit'] ) ? (string) $event['unit'] : '', 0, 32 ),
			'privacy'      => $privacy,
			'recorded_at'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'context'      => $context_json,
		);
	}

	/**
	 * Query events by metric id, bounded by time range and an
	 * upper-bounded LIMIT (dashboard consumer — PR 9.1).
	 *
	 * @param string   $metric_id Metric id.
	 * @param int|null $since_ts  Inclusive lower bound as UTC timestamp (null = no lower bound).
	 * @param int|null $until_ts  Inclusive upper bound as UTC timestamp (null = now).
	 * @param int      $limit     Hard cap on returned rows (default 500, max 5000).
	 * @return array<int,array<string,mixed>>
	 */
	public function query_by_metric( $metric_id, $since_ts = null, $until_ts = null, $limit = 500 ) {
		global $wpdb;

		$metric_id = is_string( $metric_id ) ? strtolower( trim( $metric_id ) ) : '';
		if ( '' === $metric_id ) {
			return array();
		}
		if ( ! $this->table_exists() ) {
			return array();
		}

		$limit = max( 1, min( 5000, (int) $limit ) );
		$until = null !== $until_ts ? (int) $until_ts : time();
		$since = null !== $since_ts ? (int) $since_ts : 0;

		$table_name = $this->table_name();

		$sql = "SELECT id, metric_id, metric_value, metric_type, metric_unit, privacy, recorded_at, context
			FROM $table_name
			WHERE metric_id = %s
			  AND recorded_at >= %s
			  AND recorded_at <= %s
			ORDER BY recorded_at DESC
			LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a hardcoded plugin constant; neighbouring ignores in the same method already carry this justification.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query on custom plugin table; WP_Query does not support custom table queries.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic SQL with parameterised values; table name from class constant.
				$metric_id,
				gmdate( 'Y-m-d H:i:s', $since ),
				gmdate( 'Y-m-d H:i:s', $until ),
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['metric_value'] = (float) $row['metric_value'];
			if ( isset( $row['context'] ) && is_string( $row['context'] ) && '' !== $row['context'] ) {
				$decoded        = json_decode( $row['context'], true );
				$row['context'] = is_array( $decoded ) ? $decoded : array();
			} else {
				$row['context'] = array();
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Count rows per privacy tier. Used by retention + diagnostics.
	 *
	 * @return array<string,int>
	 */
	public function count_by_privacy() {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$table_name = $this->table_name();
		$sql        = "SELECT privacy, COUNT(*) AS total FROM $table_name GROUP BY privacy"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a hardcoded plugin constant; neighbouring ignores in the same method already carry this justification.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Direct aggregation query on custom plugin table; table name from class constant. WP_Query does not support custom table GROUP BY.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (string) $row['privacy'] ] = (int) $row['total'];
			}
		}
		return $out;
	}

	/**
	 * Purge events in a given privacy tier whose `recorded_at` is
	 * older than `$older_than_ts`. Returns the number of rows deleted.
	 *
	 * @param string $privacy       Privacy tier.
	 * @param int    $older_than_ts UTC timestamp; rows older than this are deleted.
	 * @return int Rows deleted (0 on no-op or error).
	 */
	public function purge_older_than( $privacy, $older_than_ts ) {
		global $wpdb;

		$allowed_privacies = array(
			MeasurementRegistry::PRIVACY_PUBLIC,
			MeasurementRegistry::PRIVACY_INTERNAL,
			MeasurementRegistry::PRIVACY_SENSITIVE,
		);
		if ( ! in_array( $privacy, $allowed_privacies, true ) ) {
			return 0;
		}
		if ( ! is_numeric( $older_than_ts ) ) {
			return 0;
		}
		if ( ! $this->table_exists() ) {
			return 0;
		}

		$cutoff     = gmdate( 'Y-m-d H:i:s', (int) $older_than_ts );
		$table_name = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct DELETE on custom plugin table; table name from class constant. WP_Query does not support custom table deletes.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from class constant, not user input.
				"DELETE FROM $table_name WHERE privacy = %s AND recorded_at < %s",
				$privacy,
				$cutoff
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Total row count. Primarily diagnostic.
	 *
	 * @return int
	 */
	public function total_count() {
		global $wpdb;
		if ( ! $this->table_exists() ) {
			return 0;
		}
		$table_name = esc_sql( $this->table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct COUNT on custom plugin table; table name from class constant. WP_Query does not support custom table queries.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
	}
}
