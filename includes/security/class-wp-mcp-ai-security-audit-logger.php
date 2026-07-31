<?php
/**
 * Security Audit Logger — Records security-relevant events for audit trails.
 *
 * Persists failed capability checks, blocked SSRF attempts, rate-limit hits,
 * destructive operation denials, nonce failures, and upload blocks to a
 * custom database table. Exposes the log via a REST endpoint for admin
 * dashboards and external SIEM/webhook integration via a custom action.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Security_Audit_Logger' ) ) {
	/**
	 * Security audit event logger with custom table storage.
	 *
	 * Provides a static API for recording security-relevant events,
	 * a daily purge cron, a REST endpoint for admin retrieval, and
	 * an action hook for external integration.
	 */
	class WP_MCP_AI_Security_Audit_Logger {

		/**
		 * Custom table name (without $wpdb->prefix).
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const TABLE_NAME = 'wp_mcp_ai_security_log';

		/**
		 * Schema version for dbDelta() migrations.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const TABLE_VERSION = '1.0.0';

		/**
		 * Event type: a capability check failed.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const EVENT_FAILED_CAPABILITY = 'failed_capability';

		/**
		 * Event type: an SSRF attempt was blocked.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const EVENT_BLOCKED_SSRF = 'blocked_ssrf';

		/**
		 * Event type: a rate limit was hit.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const EVENT_RATE_LIMIT_HIT = 'rate_limit_hit';

		/**
		 * Event type: a destructive operation was denied.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const EVENT_DESTRUCTIVE_OP_DENIED = 'destructive_op_denied';

		/**
		 * Event type: a nonce verification failed.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const EVENT_NONCE_FAILURE = 'nonce_failure';

		/**
		 * Event type: a file upload was blocked.
		 *
		 * @since 1.2.0
		 * @var string
		 */
		const EVENT_UPLOAD_BLOCKED = 'upload_blocked';

		/**
		 * Register hooks and schedule the purge cron.
		 *
		 * Hooks into `init` to register the REST endpoint and schedules
		 * the daily purge cron if not already scheduled.
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function register() {
			add_action( 'init', array( __CLASS__, 'register_rest_routes' ) );

			// Schedule daily purge if not already scheduled.
			if ( ! wp_next_scheduled( 'wp_mcp_ai_purge_security_events' ) ) {
				wp_schedule_event( time(), 'daily', 'wp_mcp_ai_purge_security_events' );
			}

			add_action( 'wp_mcp_ai_purge_security_events', array( __CLASS__, 'purge_old_events' ) );
		}

		/**
		 * Log a security-relevant event.
		 *
		 * Creates the audit table if it does not exist, inserts a row,
		 * and fires the `wp_mcp_ai_security_event` action for external
		 * integrations (SIEM, webhooks, etc.).
		 *
		 * @since 1.2.0
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
			 * @since 1.2.0
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
		 * @since 1.2.0
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
		 * @since 1.2.0
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
		 * @since 1.2.0
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
		 * @since 1.2.0
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
		 * @since 1.2.0
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
		 * @since 1.2.0
		 * @return void
		 */
		public static function on_deactivation() {
			$timestamp = wp_next_scheduled( 'wp_mcp_ai_purge_security_events' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wp_mcp_ai_purge_security_events' );
			}
		}
	}
}
