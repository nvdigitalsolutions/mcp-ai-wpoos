<?php
/**
 * Sync Google Calendar Tool - Phase 2.6
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * WP_MCP_AI_Tool_Sync_Google_Calendar tool.
 */
class WP_MCP_AI_Tool_Sync_Google_Calendar implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false; }
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}
	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' ); }
		/**
		 * Get the tool slug.
		 *
		 * @return string
		 */
	public function get_slug() {
		return 'sync_google_calendar'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Sync Google Calendar', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Sync appointments with Google Calendar.', 'mcp-ai-wpoos-pro' ); }
		/**
		 * Get the parameters schema.
		 *
		 * @return array
		 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_id' => array(
					'type'        => 'integer',
					'description' => __( 'Appointment ID to sync', 'mcp-ai-wpoos-pro' ),
				),
				'sync_direction' => array(
					'type'    => 'string',
					'enum'    => array( 'to_google', 'from_google', 'bidirectional' ),
					'default' => 'to_google',
				),
				'calendar_id'    => array(
					'type'        => 'string',
					'description' => __( 'Google Calendar ID', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id'  => array(
					'type'        => 'string',
					'description' => __( 'Optional Google Calendar connection ID from Remote Sites. When omitted, the connection configured under Tools → Connections → Google Calendar is used.', 'mcp-ai-wpoos-pro' ),
				),
				'send_updates'   => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'externalOnly', 'none' ),
					'default'     => 'none',
					'description' => __( 'Whether Google should email the attendee about this change.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'appointment_id' ),
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'phase-2.6' ); }
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() ); }
		$appointment_id = ! empty( $arguments['appointment_id'] ) ? absint( $arguments['appointment_id'] ) : 0;
		if ( ! $appointment_id ) {
			return new WP_Error( 'missing_id', __( 'Appointment ID is required.', 'mcp-ai-wpoos-pro' ) ); }

		/**
		 * Filters the Google Calendar sync result for an appointment.
		 *
		 * Returning a non-empty value short-circuits the built-in sync, preserving
		 * backward compatibility for sites that implemented their own bridge while
		 * this tool was a stub.
		 *
		 * @since 1.0.0
		 *
		 * @param false|array $sync_result    Pre-computed result, or false to use the built-in sync.
		 * @param int         $appointment_id Appointment post ID.
		 * @param array       $arguments      Tool arguments.
		 */
		$sync_result = apply_filters( 'wp_mcp_ai_google_calendar_sync', false, $appointment_id, $arguments );

		if ( is_array( $sync_result ) && ! empty( $sync_result ) ) {
			return array(
				'success'         => true,
				'appointment_id'  => $appointment_id,
				'sync_status'     => isset( $sync_result['status'] ) ? (string) $sync_result['status'] : 'synced',
				'google_event_id' => isset( $sync_result['event_id'] ) ? (string) $sync_result['event_id'] : '',
				'message'         => __( 'Google Calendar sync completed by a custom handler.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $this->sync_appointment( $appointment_id, $arguments, $context );
	}

	/**
	 * Push an appointment to Google Calendar.
	 *
	 * Creates the event on first sync and updates it thereafter, keyed on the
	 * `_google_calendar_event_id` post meta so repeated calls are idempotent
	 * rather than producing duplicate events.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $appointment_id Appointment post ID.
	 * @param array $arguments      Tool arguments.
	 * @param array $context        Execution context.
	 * @return array|WP_Error Canonical envelope or WP_Error.
	 */
	protected function sync_appointment( $appointment_id, array $arguments, array $context ) {
		require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-credentials.php';

		$appointment = get_post( $appointment_id );

		if ( ! $appointment ) {
			return new WP_Error(
				'wp_mcp_ai_appointment_not_found',
				__( 'That appointment could not be found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$credentials   = WP_MCP_AI_Google_Calendar_Credentials::resolve( $connection_id, $context, $arguments );

		if ( is_wp_error( $credentials ) ) {
			update_post_meta( $appointment_id, '_google_calendar_synced', 'failed' );

			return $credentials;
		}

		$scope_check = WP_MCP_AI_Google_Calendar_Credentials::require_scope(
			$credentials,
			WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$calendar_id = WP_MCP_AI_Google_Calendar_Credentials::resolve_calendar_id(
			$credentials,
			isset( $arguments['calendar_id'] ) ? sanitize_text_field( $arguments['calendar_id'] ) : ''
		);

		$timezone = ! empty( $credentials['timezone'] )
			? (string) $credentials['timezone']
			: WP_MCP_AI_Google_Calendar_Credentials::default_timezone();

		$start_raw = (string) get_post_meta( $appointment_id, '_appointment_start', true );
		$end_raw   = (string) get_post_meta( $appointment_id, '_appointment_end', true );

		if ( '' === $start_raw ) {
			return new WP_Error(
				'wp_mcp_ai_appointment_missing_start',
				__( 'This appointment has no start time, so it cannot be synced.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$start = $this->build_time_field( $start_raw, $timezone );

		if ( is_wp_error( $start ) ) {
			return $start;
		}

		// Default to a one-hour block when no explicit end time is stored.
		if ( '' === $end_raw ) {
			$start_ts = strtotime( $start_raw );
			$end_raw  = false !== $start_ts ? gmdate( 'Y-m-d H:i:s', $start_ts + HOUR_IN_SECONDS ) : '';
		}

		$end = $this->build_time_field( $end_raw, $timezone );

		if ( is_wp_error( $end ) ) {
			return $end;
		}

		$payload = array(
			'summary'     => $appointment->post_title,
			'description' => wp_strip_all_tags( $appointment->post_content ),
			'start'       => $start,
			'end'         => $end,
		);

		$location = (string) get_post_meta( $appointment_id, '_appointment_location', true );

		if ( '' !== $location ) {
			$payload['location'] = $location;
		}

		$attendee_email = sanitize_email( (string) get_post_meta( $appointment_id, '_appointment_customer_email', true ) );

		if ( '' !== $attendee_email ) {
			// needsAction is Google's recommended value for new events; supplying an
			// accepted/declined status can silently reset the guest's response.
			$payload['attendees'] = array(
				array(
					'email'          => $attendee_email,
					'responseStatus' => 'needsAction',
				),
			);
		}

		$send_updates = isset( $arguments['send_updates'] ) ? sanitize_key( $arguments['send_updates'] ) : 'none';

		if ( ! in_array( $send_updates, array( 'all', 'externalOnly', 'none' ), true ) ) {
			$send_updates = 'none';
		}

		$client = WP_MCP_AI_Google_Calendar_Credentials::make_client( $credentials );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$existing_event_id = (string) get_post_meta( $appointment_id, '_google_calendar_event_id', true );
		$query             = array( 'sendUpdates' => $send_updates );

		if ( '' !== $existing_event_id ) {
			$result    = $client->update_event( $calendar_id, $existing_event_id, $payload, $query );
			$operation = 'updated';

			// A deleted upstream event must not permanently break syncing: fall back
			// to creating a replacement.
			if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'wp_mcp_ai_calendar_not_found', 'wp_mcp_ai_calendar_already_deleted' ), true ) ) {
				delete_post_meta( $appointment_id, '_google_calendar_event_id' );
				$result    = $client->insert_event( $calendar_id, $payload, $query );
				$operation = 'recreated';
			}
		} else {
			$result    = $client->insert_event( $calendar_id, $payload, $query );
			$operation = 'created';
		}

		if ( is_wp_error( $result ) ) {
			update_post_meta( $appointment_id, '_google_calendar_synced', 'failed' );

			return $result;
		}

		$event_id = isset( $result['id'] ) ? (string) $result['id'] : '';

		if ( '' !== $event_id ) {
			update_post_meta( $appointment_id, '_google_calendar_event_id', $event_id );
		}

		update_post_meta( $appointment_id, '_google_calendar_synced', 'synced' );
		update_post_meta( $appointment_id, '_google_calendar_calendar_id', $calendar_id );

		return array(
			'success'         => true,
			'appointment_id'  => $appointment_id,
			'sync_status'     => 'synced',
			'operation'       => $operation,
			'google_event_id' => $event_id,
			'calendar_id'     => $calendar_id,
			'html_link'       => isset( $result['htmlLink'] ) ? (string) $result['htmlLink'] : '',
			'message'         => __( 'Appointment synced to Google Calendar.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Build a Google Calendar time field from a stored appointment timestamp.
	 *
	 * A date-only value produces an all-day field; anything else produces a timed
	 * field with an explicit IANA time zone. The two shapes must never be mixed
	 * within a single event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value    Stored date or datetime.
	 * @param string $timezone IANA time zone identifier.
	 * @return array<string,string>|WP_Error Time field or WP_Error.
	 */
	protected function build_time_field( $value, $timezone ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_time',
				__( 'An appointment time could not be read.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return array( 'date' => $value );
		}

		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( $timezone ) );
		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_time',
				sprintf(
					/* translators: %s: the unparseable time value. */
					__( 'Unable to interpret the appointment time "%s".', 'mcp-ai-wpoos-pro' ),
					$value
				),
				array( 'status' => 400 )
			);
		}

		return array(
			'dateTime' => $date->format( DATE_RFC3339 ),
			'timeZone' => $timezone,
		);
	}
}
