<?php
/**
 * Tool that deletes an event from a connected Google Calendar.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-credentials.php';

/**
 * Provides an assistant tool that permanently removes a Google Calendar event.
 *
 * Deletion is idempotent from the caller's perspective: Google answers a repeat
 * delete with `410 deleted`, which the shared client converts into
 * `array( 'already_deleted' => true )`. That is reported as success, because the
 * caller's intent — "this event should not exist" — has been satisfied.
 */
class WP_MCP_AI_Pro_Tool_Delete_Google_Calendar_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Capability required before the tool talks to the Calendar API.
	 *
	 * @var string
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Default HTTP timeout for the Calendar request, in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Values Google accepts for the `sendUpdates` query parameter.
	 *
	 * @var array<string>
	 */
	const ALLOWED_SEND_UPDATES = array( 'all', 'externalOnly', 'none' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_google_calendar_event';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Google Calendar Event', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Permanently deletes an event from a Google Calendar. This cannot be undone. Deleting an event that is already gone is reported as success with already_deleted set to true. To hide an event without destroying it, use update_google_calendar_event with status "cancelled" instead.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Google Calendar connection ID from Remote Sites. When omitted, the connection configured under Tools → Connections → Google Calendar is used.', 'mcp-ai-wpoos-pro' ),
				),
				'calendar_id'   => array(
					'type'        => 'string',
					'description' => __( 'Calendar identifier holding the event. Defaults to the configured calendar, or "primary".', 'mcp-ai-wpoos-pro' ),
				),
				'event_id'      => array(
					'type'        => 'string',
					'description' => __( 'Identifier of the event to delete, as returned by list_google_calendar_events.', 'mcp-ai-wpoos-pro' ),
				),
				'send_updates'  => array(
					'type'        => 'string',
					'description' => __( 'Controls whether attendees are emailed about the cancellation: "all", "externalOnly", or "none".', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::ALLOWED_SEND_UPDATES,
				),
			),
			'required'             => array( 'event_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters(
			'wp_mcp_ai_delete_google_calendar_event_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_forbidden', __( 'You do not have permission to delete Google Calendar events.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// Gate 1: sanitise every caller-supplied value before any logic runs.
		$connection_id     = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$calendar_override = isset( $arguments['calendar_id'] ) ? sanitize_text_field( $arguments['calendar_id'] ) : '';
		$event_id          = isset( $arguments['event_id'] ) ? sanitize_text_field( $arguments['event_id'] ) : '';
		$send_updates      = isset( $arguments['send_updates'] ) ? sanitize_text_field( $arguments['send_updates'] ) : '';

		if ( '' === $event_id ) {
			return new WP_Error( 'wp_mcp_ai_calendar_missing_event_id', __( 'An event ID is required to delete a Google Calendar event.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		if ( '' !== $send_updates && ! in_array( $send_updates, self::ALLOWED_SEND_UPDATES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_send_updates',
				sprintf(
					/* translators: %s: comma-separated list of accepted sendUpdates values. */
					__( 'The send_updates value must be one of: %s.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', self::ALLOWED_SEND_UPDATES )
				),
				array( 'status' => 400 )
			);
		}

		$credentials = WP_MCP_AI_Google_Calendar_Credentials::resolve( $connection_id, $context, $arguments );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Destroying events requires the writable events scope.
		$scope_check = WP_MCP_AI_Google_Calendar_Credentials::require_scope(
			$credentials,
			WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$calendar_id = WP_MCP_AI_Google_Calendar_Credentials::resolve_calendar_id( $credentials, $calendar_override );

		if ( '' === $calendar_id ) {
			return new WP_Error( 'wp_mcp_ai_calendar_missing_calendar', __( 'A target Google Calendar identifier is required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$timeout = (int) apply_filters( 'wp_mcp_ai_google_calendar_request_timeout', self::DEFAULT_TIMEOUT, $context, $arguments, $this );

		if ( $timeout <= 0 ) {
			$timeout = self::DEFAULT_TIMEOUT;
		}

		$client = WP_MCP_AI_Google_Calendar_Credentials::make_client(
			$credentials,
			array( 'timeout' => $timeout )
		);

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$query = array();

		if ( '' !== $send_updates ) {
			$query['sendUpdates'] = $send_updates;
		}

		$result = $client->delete_event( $calendar_id, $event_id, $query );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$already_deleted = is_array( $result ) && ! empty( $result['already_deleted'] );

		$message = $already_deleted
			? __( 'The event was already deleted from Google Calendar.', 'mcp-ai-wpoos-pro' )
			: __( 'The event was deleted from Google Calendar.', 'mcp-ai-wpoos-pro' );

		return array(
			'success'           => true,
			'event_id'          => $event_id,
			'calendar_id'       => $calendar_id,
			'already_deleted'   => $already_deleted,
			'message'           => $message,
			'credential_source' => isset( $credentials['source'] ) ? (string) $credentials['source'] : '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                     // Pro tier tool.
			'write',                   // Modifies calendar data.
			'state-changing',          // Mutates remote calendar state.
			'irreversible',            // Deletion cannot be undone.
			'data-destruction',        // Permanently removes the event.
			'external-api',            // Calls Google Calendar API.
			'network-dependent',       // Requires internet connectivity.
			'external-communication',  // May email attendees via sendUpdates.
			'requires-capability',     // Requires user capabilities.
		);
	}
}
