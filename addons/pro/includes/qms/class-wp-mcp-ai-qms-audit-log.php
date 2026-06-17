<?php
/**
 * QMS Audit Log.
 *
 * Implements the immutable audit table required by ISO 9001:2015 Clause 7.5.3
 * (control of documented information). Every QMS state transition, signature,
 * release, and disposition writes a row here.
 *
 * The same table is reused by the PARA subsystem (subsystem column distinguishes
 * the two) so reporting can be unified.
 *
 * Schema:
 *   id          BIGINT UNSIGNED PK AUTO_INCREMENT
 *   subsystem   VARCHAR(20)   -- 'qms' | 'para'
 *   event       VARCHAR(64)   -- e.g. 'state_transition', 'signed', 'archived'
 *   actor_id    BIGINT UNSIGNED
 *   post_id     BIGINT UNSIGNED
 *   doc_id      VARCHAR(64)   -- controlled-doc ID (e.g. SOP-001) or empty
 *   revision    VARCHAR(16)   -- e.g. '1.0', '2.1'
 *   from_state  VARCHAR(32)
 *   to_state    VARCHAR(32)
 *   before_hash CHAR(64)
 *   after_hash  CHAR(64)
 *   ip          VARCHAR(64)
 *   user_agent  VARCHAR(255)
 *   meta        LONGTEXT       -- JSON
 *   created_at  DATETIME
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit log writer/reader.
 */
class WP_MCP_AI_QMS_Audit_Log {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wp_mcp_ai_qms_audit';
	}

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
	}

	/**
	 * Get the current schema version.
	 *
	 * @return int
	 */
	public static function schema_version() {
		return 1;
	}

	/**
	 * Install or upgrade the table.
	 */
	public static function maybe_install() {
		$installed = (int) get_option( 'wp_mcp_ai_qms_audit_schema', 0 );
		if ( $installed >= self::schema_version() ) {
			return;
		}
		self::install();
		update_option( 'wp_mcp_ai_qms_audit_schema', self::schema_version() );
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subsystem VARCHAR(20) NOT NULL DEFAULT 'qms',
			event VARCHAR(64) NOT NULL DEFAULT '',
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			doc_id VARCHAR(64) NOT NULL DEFAULT '',
			revision VARCHAR(16) NOT NULL DEFAULT '',
			from_state VARCHAR(32) NOT NULL DEFAULT '',
			to_state VARCHAR(32) NOT NULL DEFAULT '',
			before_hash CHAR(64) NOT NULL DEFAULT '',
			after_hash CHAR(64) NOT NULL DEFAULT '',
			ip VARCHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY subsystem_event (subsystem, event),
			KEY post_id (post_id),
			KEY doc_id (doc_id),
			KEY actor_id (actor_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Write an audit entry.
	 *
	 * @param array $args Audit data.
	 * @return int|false Inserted ID or false on failure.
	 */
	public static function record( array $args ) {
		global $wpdb;

		$defaults = array(
			'subsystem'   => 'qms',
			'event'       => '',
			'actor_id'    => get_current_user_id(),
			'post_id'     => 0,
			'doc_id'      => '',
			'revision'    => '',
			'from_state'  => '',
			'to_state'    => '',
			'before_hash' => '',
			'after_hash'  => '',
			'ip'          => self::get_client_ip(),
			'user_agent'  => self::get_user_agent(),
			'meta'        => array(),
			'created_at'  => current_time( 'mysql', true ),
		);
		$data     = array_merge( $defaults, $args );

		$row = array(
			'subsystem'   => substr( sanitize_key( (string) $data['subsystem'] ), 0, 20 ),
			'event'       => substr( sanitize_key( (string) $data['event'] ), 0, 64 ),
			'actor_id'    => (int) $data['actor_id'],
			'post_id'     => (int) $data['post_id'],
			'doc_id'      => substr( sanitize_text_field( (string) $data['doc_id'] ), 0, 64 ),
			'revision'    => substr( sanitize_text_field( (string) $data['revision'] ), 0, 16 ),
			'from_state'  => substr( sanitize_key( (string) $data['from_state'] ), 0, 32 ),
			'to_state'    => substr( sanitize_key( (string) $data['to_state'] ), 0, 32 ),
			'before_hash' => substr( preg_replace( '/[^a-f0-9]/i', '', (string) $data['before_hash'] ), 0, 64 ),
			'after_hash'  => substr( preg_replace( '/[^a-f0-9]/i', '', (string) $data['after_hash'] ), 0, 64 ),
			'ip'          => substr( sanitize_text_field( (string) $data['ip'] ), 0, 64 ),
			'user_agent'  => substr( sanitize_text_field( (string) $data['user_agent'] ), 0, 255 ),
			'meta'        => wp_json_encode( $data['meta'] ? $data['meta'] : array() ),
			'created_at'  => sanitize_text_field( (string) $data['created_at'] ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- audit log writes.
		$inserted = $wpdb->insert( self::table_name(), $row );

		if ( false === $inserted ) {
			return false;
		}

		$insert_id = (int) $wpdb->insert_id;

		/**
		 * Fires after an audit row is written.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $insert_id Audit row ID.
		 * @param array $row       Row data.
		 */
		do_action( 'wp_mcp_ai_qms_audit_logged', $insert_id, $row );

		return $insert_id;
	}

	/**
	 * Query audit entries.
	 *
	 * @param array $args Query args (post_id, doc_id, subsystem, event, limit).
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'post_id'   => 0,
			'doc_id'    => '',
			'subsystem' => '',
			'event'     => '',
			'limit'     => 50,
		);
		$args     = array_merge( $defaults, $args );

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}
		if ( ! empty( $args['doc_id'] ) ) {
			$where[]  = 'doc_id = %s';
			$params[] = (string) $args['doc_id'];
		}
		if ( ! empty( $args['subsystem'] ) ) {
			$where[]  = 'subsystem = %s';
			$params[] = sanitize_key( (string) $args['subsystem'] );
		}
		if ( ! empty( $args['event'] ) ) {
			$where[]  = 'event = %s';
			$params[] = sanitize_key( (string) $args['event'] );
		}

		$limit = max( 1, min( 500, (int) $args['limit'] ) );
		$sql   = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT ' . $limit;
		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name and limit are safe; placeholders are prepared.
			$prepared = $wpdb->prepare( $sql, $params );
		} else {
			$prepared = $sql;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- read-only audit query.
		$rows = $wpdb->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['meta'] = json_decode( (string) ( $row['meta'] ?? '' ), true );
		}
		return $rows;
	}

	/**
	 * Best-effort client IP capture.
	 *
	 * @return string
	 */
	protected static function get_client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip;
	}

	/**
	 * Best-effort user agent capture.
	 *
	 * @return string
	 */
	protected static function get_user_agent() {
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
		}
		return '';
	}
}
