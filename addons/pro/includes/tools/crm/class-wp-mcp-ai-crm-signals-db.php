<?php
/**
 * CRM Signals DB
 *
 * Indexed observation store for prospect signals. A dedicated table is the
 * right shape for cohort queries; post-meta storage remains available as a
 * fallback through the Signals engine when a host disallows the table.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signal observation table.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_CRM_Signals_DB {

	/**
	 * Schema version option.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'wp_mcp_ai_crm_signals_db_version';

	/**
	 * Schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.1';

	/**
	 * Whether the table was checked this request.
	 *
	 * @var bool|null
	 */
	private static $table_exists = null;

	/**
	 * Whether an install was attempted this request.
	 *
	 * @var bool
	 */
	private static $install_attempted = false;

	/**
	 * Initialise / migrate.
	 *
	 * @return void
	 */
	public static function init() {
		if ( get_option( self::VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
			return;
		}

		// The stored version can outlive the table when a previous install
		// recorded the option but the CREATE never landed. Recover once.
		if ( ! self::table_is_present() ) {
			self::install();
		}
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mcp_ai_crm_signals';
	}

	/**
	 * Install the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		self::$install_attempted = true;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is the primary path because it diffs an existing table and
		// adds missing columns on upgrade. It is whitespace-sensitive and is
		// known to no-op silently on some MySQL/MariaDB combinations, so the
		// result is verified below rather than trusted.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			signal_key varchar(64) NOT NULL,
			value_num decimal(14,4) DEFAULT NULL,
			value_text text NULL,
			evidence_json longtext NULL,
			source varchar(191) NOT NULL DEFAULT '',
			confidence tinyint(3) unsigned NOT NULL DEFAULT 100,
			observed_at datetime NOT NULL,
			expires_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY lead_signal (lead_id,signal_key),
			KEY signal_value (signal_key,value_num),
			KEY observed_at (observed_at)
		) {$charset_collate};";

		dbDelta( $sql );

		dbDelta( $sql );

		if ( ! self::table_is_present() ) {
			// Guarded fallback. IF NOT EXISTS keeps this a no-op when dbDelta
			// did succeed, so it can never clobber an existing table.
			$fallback = preg_replace( '/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql );
			$wpdb->query( $fallback ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Internal schema statement.
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		self::$table_exists = null;
	}

	/**
	 * Whether the table exists right now (uncached).
	 *
	 * @return bool
	 */
	public static function table_is_present() {
		global $wpdb;
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		return $found === $table;
	}

	/**
	 * Whether the table can be used.
	 *
	 * @return bool
	 */
	public static function use_table() {
		/**
		 * Filter whether signal observations use the custom table.
		 *
		 * @param bool $use_table Use the table.
		 */
		$allowed = (bool) apply_filters( 'wp_mcp_ai_crm_signals_use_table', true );
		if ( ! $allowed ) {
			return false;
		}

		if ( null !== self::$table_exists ) {
			return self::$table_exists;
		}

		self::$table_exists = self::table_is_present();

		// A missing table must never break a send, but one repair attempt per
		// request keeps the fallback honest without re-running DDL per call.
		if ( ! self::$table_exists && ! self::$install_attempted ) {
			self::install();
			self::$table_exists = self::table_is_present();
		}

		return self::$table_exists;
	}

	/**
	 * Insert an observation.
	 *
	 * @param array $args Observation.
	 * @return int|false Row ID or false.
	 */
	public static function insert( array $args ) {
		global $wpdb;

		$lead_id    = absint( $args['lead_id'] ?? 0 );
		$signal_key = sanitize_key( $args['signal_key'] ?? '' );
		if ( ! $lead_id || '' === $signal_key ) {
			return false;
		}

		$observed_at = isset( $args['observed_at'] ) ? (string) $args['observed_at'] : gmdate( 'c' );
		$observed_ts = strtotime( $observed_at );
		$expires_at  = isset( $args['expires_at'] ) ? (string) $args['expires_at'] : '';
		$expires_ts  = $expires_at ? strtotime( $expires_at ) : 0;

		$row = array(
			'lead_id'       => $lead_id,
			'signal_key'    => $signal_key,
			'value_num'     => isset( $args['value_num'] ) && is_numeric( $args['value_num'] ) ? (float) $args['value_num'] : null,
			'value_text'    => isset( $args['value_text'] ) ? sanitize_textarea_field( (string) $args['value_text'] ) : null,
			'evidence_json' => isset( $args['evidence'] ) ? wp_json_encode( $args['evidence'] ) : null,
			'source'        => isset( $args['source'] ) ? esc_url_raw( (string) $args['source'] ) : '',
			'confidence'    => max( 0, min( 100, absint( $args['confidence'] ?? 100 ) ) ),
			'observed_at'   => gmdate( 'Y-m-d H:i:s', $observed_ts ? $observed_ts : time() ),
			'expires_at'    => $expires_ts ? gmdate( 'Y-m-d H:i:s', $expires_ts ) : null,
		);

		$formats = array( '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s' );
		$result  = $wpdb->insert( self::table_name(), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Observation write.
		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Query observations.
	 *
	 * @param array $args { lead_id, signal_key, signal_keys, limit }.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['lead_id'] ) ) {
			$where[]  = 'lead_id = %d';
			$params[] = absint( $args['lead_id'] );
		}
		if ( ! empty( $args['signal_key'] ) ) {
			$where[]  = 'signal_key = %s';
			$params[] = sanitize_key( $args['signal_key'] );
		}
		if ( ! empty( $args['signal_keys'] ) && is_array( $args['signal_keys'] ) ) {
			$keys    = array_map( 'sanitize_key', $args['signal_keys'] );
			$in      = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$where[] = "signal_key IN ({$in})";
			$params  = array_merge( $params, $keys );
		}
		if ( empty( $args['include_expired'] ) ) {
			$where[]  = '(expires_at IS NULL OR expires_at > %s)';
			$params[] = gmdate( 'Y-m-d H:i:s' );
		}

		$limit    = isset( $args['limit'] ) ? max( 1, min( 5000, absint( $args['limit'] ) ) ) : 500;
		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY observed_at DESC LIMIT %d';
		$params[] = $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['evidence'] = ! empty( $row['evidence_json'] ) ? json_decode( $row['evidence_json'], true ) : array();
			unset( $row['evidence_json'] );
		}
		return $rows;
	}

	/**
	 * Per-signal coverage.
	 *
	 * @return array signal_key => [observations, leads]
	 */
	public static function coverage() {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( "SELECT signal_key, COUNT(*) AS observations, COUNT(DISTINCT lead_id) AS leads FROM {$table} WHERE (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) GROUP BY signal_key", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['signal_key'] ] = array(
				'observations' => (int) $row['observations'],
				'leads'        => (int) $row['leads'],
			);
		}
		return $out;
	}

	/**
	 * Delete all observations for a lead.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return void
	 */
	public static function delete_for_lead( $lead_id ) {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'lead_id' => absint( $lead_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Observation delete.
	}

	/**
	 * Delete one signal's observations for a lead.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $key     Signal key.
	 * @return void
	 */
	public static function delete_observation( $lead_id, $key ) {
		global $wpdb;
		$wpdb->delete(
			self::table_name(),
			array(
				'lead_id'    => absint( $lead_id ),
				'signal_key' => sanitize_key( $key ),
			),
			array( '%d', '%s' )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Observation delete.
	}
}
