<?php
/**
 * Real-Time Event Tracking Tool
 *
 * Track real-time events and user interactions.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for real-time event tracking.
 *
 * Supports:
 * - User interaction tracking
 * - Page view events
 * - Custom event types
 * - Session tracking
 * - Real-time analytics
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Real_Time_Event_Tracking implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if analytics toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_analytics_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_analytics_toolkit'] ) ) {
			return __( 'Advanced Analytics toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Real-time event tracking tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'real_time_event_tracking';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Track Real-Time Events', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Track real-time user events and interactions. Monitor page views, clicks, form submissions, and custom events as they happen.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return string Tool slug.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'event_type'  => array(
					'type'        => 'string',
					'description' => 'Type of event to track',
					'enum'        => array( 'page_view', 'click', 'form_submission', 'purchase', 'custom' ),
				),
				'event_name'  => array(
					'type'        => 'string',
					'description' => 'Name of the event',
					'maxLength'   => 255,
				),
				'event_data'  => array(
					'type'        => 'object',
					'description' => 'Event-specific data',
				),
				'page_url'    => array(
					'type'        => 'string',
					'description' => 'URL where event occurred',
				),
				'user_id'     => array(
					'type'        => 'integer',
					'description' => 'User ID (0 for anonymous)',
					'minimum'     => 0,
				),
				'session_id'  => array(
					'type'        => 'string',
					'description' => 'Session identifier',
				),
			),
			'required'   => array( 'event_type', 'event_name' ),
		);
	}

	/**
	 * Get required capability.
	 *
	 * @since 1.1.0
	 *
	 * @return string Required capability.
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array Capability flags.
	 */
	public function get_capability_flags() {
		return array(
			'analytics'      => true,
			'real_time'      => true,
			'data_collection' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( $arguments, $context ) {
		global $wpdb;

		$event_type = ! empty( $arguments['event_type'] ) ? sanitize_text_field( $arguments['event_type'] ) : '';
		$event_name = ! empty( $arguments['event_name'] ) ? sanitize_text_field( $arguments['event_name'] ) : '';
		$event_data = ! empty( $arguments['event_data'] ) && is_array( $arguments['event_data'] ) ? $arguments['event_data'] : array();
		$page_url   = ! empty( $arguments['page_url'] ) ? esc_url_raw( $arguments['page_url'] ) : '';
		$user_id    = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : get_current_user_id();
		$session_id = ! empty( $arguments['session_id'] ) ? sanitize_text_field( $arguments['session_id'] ) : $this->get_session_id();

		if ( empty( $event_type ) || empty( $event_name ) ) {
			return new WP_Error(
				'missing_parameters',
				__( 'Event type and event name are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Ensure events table exists.
		$table_name = $wpdb->prefix . 'mcp_ai_events';
		$this->ensure_table_exists( $table_name );

		// Insert event.
		$result = $wpdb->insert(
			$table_name,
			array(
				'event_type' => $event_type,
				'event_name' => $event_name,
				'event_data' => wp_json_encode( $event_data ),
				'page_url'   => $page_url,
				'user_id'    => $user_id,
				'session_id' => $session_id,
				'user_agent' => ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'ip_address' => ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error(
				'insert_failed',
				__( 'Failed to record event.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $wpdb->last_error )
			);
		}

		$event_id = $wpdb->insert_id;

		// Fire action for real-time processing.
		do_action( 'wp_mcp_ai_real_time_event', $event_id, $event_type, $event_name, $event_data );

		return array(
			'success'    => true,
			'event_id'   => $event_id,
			'event_type' => $event_type,
			'event_name' => $event_name,
			'user_id'    => $user_id,
			'session_id' => $session_id,
			'timestamp'  => current_time( 'mysql', true ),
			'message'    => sprintf(
				/* translators: 1: event type, 2: event name */
				__( 'Recorded %1$s event: %2$s', 'mcp-ai-wpoos-pro' ),
				$event_type,
				$event_name
			),
		);
	}

	/**
	 * Get or create session ID.
	 *
	 * @since 1.1.0
	 *
	 * @return string Session ID.
	 */
	private function get_session_id() {
		if ( ! isset( $_COOKIE['wp_mcp_ai_session'] ) ) {
			return 'session_' . wp_generate_password( 32, false );
		}

		return sanitize_text_field( wp_unslash( $_COOKIE['wp_mcp_ai_session'] ) );
	}

	/**
	 * Ensure events table exists.
	 *
	 * @since 1.1.0
	 *
	 * @param string $table_name Table name.
	 * @return void
	 */
	private function ensure_table_exists( $table_name ) {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			event_name varchar(255) NOT NULL,
			event_data longtext,
			page_url varchar(2048) DEFAULT '',
			user_id bigint(20) unsigned DEFAULT 0,
			session_id varchar(100) DEFAULT '',
			user_agent varchar(255) DEFAULT '',
			ip_address varchar(45) DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY event_name (event_name),
			KEY user_id (user_id),
			KEY session_id (session_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
