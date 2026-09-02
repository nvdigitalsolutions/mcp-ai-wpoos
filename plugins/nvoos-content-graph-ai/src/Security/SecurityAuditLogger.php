<?php
/**
 * Security Audit Logger for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-security-audit-logger.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Table name, schema version, event-type constants, the
 * `wp_mcp_ai_security_event` action, the REST endpoint shape
 * (`mcp-ai/v1/security/events`), and the purge cron hook keep their base
 * names and semantics.
 *
 * Decoupling (documented, additive):
 * - `register()` is registered standalone-only by `Plugin.php` — in
 *   monolith installs the base plugin owns the same REST route and purge
 *   cron; double registration would conflict.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security audit event logger with custom table storage.
 *
 * Provides a static API for recording security-relevant events,
 * a daily purge cron, a REST endpoint for admin retrieval, and
 * an action hook for external integration.
 *
 * @since 1.1.0
 */
class SecurityAuditLogger {

	/**
	 * Custom table name (without $wpdb->prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'wp_mcp_ai_security_log';

	/**
	 * Schema version for dbDelta() migrations.
	 *
	 * @var string
	 */
	const TABLE_VERSION = '1.0.0';

	/**
	 * Event type: a capability check failed.
	 *
	 * @var string
	 */
	const EVENT_FAILED_CAPABILITY = 'failed_capability';

	/**
	 * Event type: an SSRF attempt was blocked.
	 *
	 * @var string
	 */
	const EVENT_BLOCKED_SSRF = 'blocked_ssrf';

	/**
	 * Event type: a rate limit was hit.
	 *
	 * @var string
	 */
	const EVENT_RATE_LIMIT_HIT = 'rate_limit_hit';

	/**
	 * Event type: a destructive operation was denied.
	 *
	 * @var string
	 */
	const EVENT_DESTRUCTIVE_OP_DENIED = 'destructive_op_denied';

	/**
	 * Event type: a nonce verification failed.
	 *
	 * @var string
	 */
	const EVENT_NONCE_FAILURE = 'nonce_failure';

	/**
	 * Event type: a file upload was blocked.
	 *
	 * @var string
	 */
	const EVENT_UPLOAD_BLOCKED = 'upload_blocked';

	/**
	 * Event type: an assistant tool executed (session-log sourced,
	 * Proposal 029 Phase 5.8).
	 *
	 * @var string
	 */
	const EVENT_TOOL_EXECUTION = 'tool_execution';

	/**
	 * Event type: a chat turn boundary (session-log sourced,
	 * Proposal 029 Phase 5.8).
	 *
	 * @var string
	 */
	const EVENT_CHAT_TURN = 'chat_turn';

	/**
	 * Register hooks and schedule the purge cron.
	 *
	 * Hooks into `rest_api_init` to register the REST endpoint and
	 * schedules the daily purge cron if not already scheduled.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Schedule daily purge if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_purge_security_events' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_purge_security_events' );
		}

		add_action( 'wp_mcp_ai_purge_security_events', array( __CLASS__, 'purge_old_events' ) );

		/**
		 * Filters whether the audit logger consumes session-log events
		 * (Proposal 029 Phase 5.8, telemetry single-path).
		 *
		 * Default OFF until the session log is promoted; audit rows
		 * for tool executions and turn boundaries are then sourced
		 * from the log instead of loop hooks.
		 *
		 * @param bool $enabled Default false.
		 */
		if ( apply_filters( 'wp_mcp_ai_audit_from_session_log_enabled', false ) ) {
			add_action( 'wp_mcp_ai_session_log_event', array( __CLASS__, 'on_session_log_event' ), 10, 4 );
		}
	}

	/**
	 * Session-log-event handler: audit tool executions and turn
	 * boundaries from the append-only log (telemetry single-path).
	 *
	 * @param string $type Entry type (SessionLog::TYPE_*).
	 * @param array  $data Type-specific payload.
	 * @param int    $seq  Monotonic entry sequence.
	 * @param float  $time Entry timestamp.
	 * @return void
	 */
	public static function on_session_log_event( $type, $data, $seq, $time ) {
		unset( $time );

		if ( ! is_array( $data ) ) {
			return;
		}

		$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;

		if ( 'tool_result' === $type ) {
			self::log_event(
				self::EVENT_TOOL_EXECUTION,
				$user_id,
				array(
					'tool_slug'    => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
					'outcome'      => isset( $data['outcome'] ) ? sanitize_text_field( (string) $data['outcome'] ) : 'success',
					'duration_ms'  => isset( $data['duration_ms'] ) && is_numeric( $data['duration_ms'] ) ? (float) $data['duration_ms'] : null,
					'assistant_id' => isset( $data['assistant_id'] ) ? absint( $data['assistant_id'] ) : 0,
					'seq'          => absint( $seq ),
					'source'       => 'session_log',
				)
			);
			return;
		}

		if ( 'turn_started' === $type || 'turn_ended' === $type ) {
			self::log_event(
				self::EVENT_CHAT_TURN,
				$user_id,
				array(
					'phase'        => 'turn_started' === $type ? 'started' : 'ended',
					'reason'       => isset( $data['reason'] ) ? sanitize_text_field( (string) $data['reason'] ) : '',
					'iterations'   => isset( $data['iterations'] ) ? absint( $data['iterations'] ) : 0,
					'assistant_id' => isset( $data['assistant_id'] ) ? absint( $data['assistant_id'] ) : 0,
					'seq'          => absint( $seq ),
					'source'       => 'session_log',
				)
			);
		}
	}

	/**
	 * Log a security-relevant event.
	 *
	 * Creates the audit table if it does not exist, inserts a row,
	 * and fires the `wp_mcp_ai_security_event` action for external
	 * integrations (SIEM, webhooks, etc.).
	 *
	 * @param string $event_type Event type constant (e.g. EVENT_RATE_LIMIT_HIT).
	 * @param int    $user_id    WordPress user ID (0 for guests).
	 * @param array  $details    Optional associative array of contextual data.
	 * @return void
	 */
	public static function log_event( $event_type, $user_id, $details = array() ) {
		global $wpdb;

		self::maybe_create_table();

		$ip_address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom audit table, no Core API available.
		$wpdb->insert(
			$table_name,
			array(
				'event_type' => $event_type,
				'user_id'    => absint( $user_id ),
				'ip_address' => $ip_address,
				'event_time' => current_time( 'mysql' ),
				'details'    => wp_json_encode( $details ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		/**
		 * Fires after a security event is logged.
		 *
		 * External systems (SIEM, webhooks, monitoring) can hook into
		 * this action to forward events in real time.
		 *
		 * @param string $event_type The event type constant.
		 * @param int    $user_id    The affected user ID.
		 * @param string $ip_address The client IP address.
		 * @param array  $details    Contextual event details.
		 */
		do_action( 'wp_mcp_ai_security_event', $event_type, $user_id, $ip_address, $details );
	}

	/**
	 * Create the audit table if the schema version is outdated.
	 *
	 * Uses `dbDelta()` for safe, idempotent table creation. Compares
	 * the stored option version against `TABLE_VERSION` and only
	 * runs when the versions differ.
	 *
	 * @access private
	 * @return void
	 */
	private static function maybe_create_table() {
		$version = get_option( 'wp_mcp_ai_security_log_table_version' );

		if ( version_compare( (string) $version, self::TABLE_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			user_id bigint(20) NOT NULL DEFAULT 0,
			ip_address varchar(45) NOT NULL DEFAULT '',
			event_time datetime NOT NULL,
			details longtext NOT NULL,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY user_id (user_id),
			KEY event_time (event_time)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'wp_mcp_ai_security_log_table_version', self::TABLE_VERSION );
	}

	/**
	 * Register the REST API endpoint for retrieving audit events.
	 *
	 * Endpoint: GET /mcp-ai/v1/security/events
	 * Requires `manage_options` capability.
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'mcp-ai/v1',
			'/security/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_events' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'per_page'   => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'       => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'event_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'user_id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST callback: return paginated audit events.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_events( $request ) {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Ensure the table exists before querying.
		self::maybe_create_table();

		$per_page   = $request->get_param( 'per_page' );
		$page       = $request->get_param( 'page' );
		$event_type = $request->get_param( 'event_type' );
		$user_id    = $request->get_param( 'user_id' );

		$offset = ( $page - 1 ) * $per_page;

		$where = 'WHERE 1=1';
		$bind  = array();

		if ( ! empty( $event_type ) ) {
			$where .= ' AND event_type = %s';
			$bind[] = $event_type;
		}

		if ( ! empty( $user_id ) ) {
			$where .= ' AND user_id = %d';
			$bind[] = absint( $user_id );
		}

		// Get total count for pagination.
		$count_query = 'SELECT COUNT(*) FROM ' . $table_name . ' ' . $where;
		if ( ! empty( $bind ) ) {
			$count_query = $wpdb->prepare( $count_query, $bind ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom audit table.
		$total = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $count_query is prepared above.
		// phpcs:enable

		// Fetch page of events, newest first.
		$data_query = 'SELECT * FROM ' . $table_name . ' ' . $where . ' ORDER BY event_time DESC LIMIT %d OFFSET %d';
		$data_bind  = array_merge( $bind, array( $per_page, $offset ) );
		$data_query = $wpdb->prepare( $data_query, $data_bind ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom audit table.
		$events = $wpdb->get_results( $data_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $data_query is prepared above.
		// phpcs:enable

		// Decode JSON details for each row.
		$items = array();
		if ( is_array( $events ) ) {
			foreach ( $events as $event ) {
				$item    = array(
					'id'         => (int) $event->id,
					'event_type' => $event->event_type,
					'user_id'    => (int) $event->user_id,
					'ip_address' => $event->ip_address,
					'event_time' => $event->event_time,
					'details'    => json_decode( $event->details, true ),
				);
				$items[] = $item;
			}
		}

		$total_pages = (int) ceil( $total / $per_page );

		return rest_ensure_response(
			array(
				'events'      => $items,
				'total'       => $total,
				'total_pages' => $total_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Delete audit events older than 30 days.
	 *
	 * Called daily via the `wp_mcp_ai_purge_security_events` cron hook.
	 *
	 * @return void
	 */
	public static function purge_old_events() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Purge cron is a maintenance operation.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table_name . ' WHERE event_time < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from own constant.
				$cutoff
			)
		);
	}

	/**
	 * Run on plugin activation: create the table and schedule the purge cron.
	 *
	 * @return void
	 */
	public static function on_activation() {
		self::maybe_create_table();

		if ( ! wp_next_scheduled( 'wp_mcp_ai_purge_security_events' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_purge_security_events' );
		}
	}

	/**
	 * Run on plugin deactivation: clear the scheduled purge cron.
	 *
	 * @return void
	 */
	public static function on_deactivation() {
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_purge_security_events' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_purge_security_events' );
		}
	}
}
