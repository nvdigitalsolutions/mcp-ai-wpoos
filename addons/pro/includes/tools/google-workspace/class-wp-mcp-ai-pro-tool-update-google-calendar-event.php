<?php
/**
 * Tool that updates an existing Google Calendar event.
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
 * Provides an assistant tool that modifies events in Google Calendar.
 *
 * Quota rationale for the default write strategy
 * ----------------------------------------------
 * The Calendar API bills `events.patch` at three quota units, while
 * `events.get` plus `events.update` together cost two. Because assistants
 * typically edit a handful of fields on an event they own, the cheaper
 * read-merge-replace path is the default: the current resource is fetched,
 * the caller's fields are merged over it, read-only members are stripped, and
 * the result is sent with `events.update`.
 *
 * `partial: true` opts into `events.patch` instead. That is the correct choice
 * when the caller is *not* the event organiser, since `events.update` treats
 * omitted shared properties as "reset to default" and Google answers with
 * `forbiddenForNonOrganizer`.
 */
class WP_MCP_AI_Pro_Tool_Update_Google_Calendar_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Capability required before the tool talks to the Calendar API.
	 *
	 * @var string
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Default HTTP timeout for the Calendar requests, in seconds.
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
	 * Event statuses Google accepts on a write.
	 *
	 * @var array<string>
	 */
	const ALLOWED_STATUSES = array( 'confirmed', 'tentative', 'cancelled' );

	/**
	 * Event members Google computes and rejects on a write.
	 *
	 * These are stripped from the fetched resource before it is replayed via
	 * `events.update`, otherwise the API answers with 400 for immutable fields.
	 *
	 * @var array<string>
	 */
	const READ_ONLY_KEYS = array(
		'etag',
		'kind',
		'htmlLink',
		'iCalUID',
		'created',
		'updated',
		'creator',
		'organizer',
		'hangoutLink',
		'conferenceData',
		'recurringEventId',
		'originalStartTime',
		'sequence',
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_google_calendar_event';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Google Calendar Event', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing Google Calendar event: title, description, location, start/end time, or status. Supply either a full timestamp for a timed event or YYYY-MM-DD for an all-day event; note that an all-day end date is exclusive, so a single-day event on 2026-06-01 ends on 2026-06-02. Set partial to true when you are not the event organiser.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Identifier of the event to update, as returned by list_google_calendar_events.', 'mcp-ai-wpoos-pro' ),
				),
				'summary'       => array(
					'type'        => 'string',
					'description' => __( 'New event title. Omit to leave the current title unchanged.', 'mcp-ai-wpoos-pro' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'New long-form description. Omit to leave the current description unchanged.', 'mcp-ai-wpoos-pro' ),
				),
				'location'      => array(
					'type'        => 'string',
					'description' => __( 'New location. Omit to leave the current location unchanged.', 'mcp-ai-wpoos-pro' ),
				),
				'start_time'    => array(
					'type'        => 'string',
					'description' => __( 'New start, either an RFC3339 timestamp such as "2026-06-01T09:00:00+02:00" or YYYY-MM-DD for an all-day event. When one bound is a date, the other must be a date too.', 'mcp-ai-wpoos-pro' ),
				),
				'end_time'      => array(
					'type'        => 'string',
					'description' => __( 'New end, either an RFC3339 timestamp or YYYY-MM-DD for an all-day event. All-day end dates are EXCLUSIVE: a single-day event starting 2026-06-01 must end 2026-06-02.', 'mcp-ai-wpoos-pro' ),
				),
				'timezone'      => array(
					'type'        => 'string',
					'description' => __( 'Optional IANA timezone identifier (e.g. "Europe/Zurich") applied to timed start/end values. UTC offsets are not accepted by Google.', 'mcp-ai-wpoos-pro' ),
				),
				'send_updates'  => array(
					'type'        => 'string',
					'description' => __( 'Controls whether attendees are emailed about the change: "all", "externalOnly", or "none".', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::ALLOWED_SEND_UPDATES,
				),
				'partial'       => array(
					'type'        => 'boolean',
					'description' => __( 'Use a partial patch instead of a full replace. Costs one extra quota unit but is required when you are not the event organiser. Defaults to false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'New event status: "confirmed", "tentative", or "cancelled". Setting "cancelled" hides the event without deleting it.', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::ALLOWED_STATUSES,
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
			'wp_mcp_ai_update_google_calendar_event_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_forbidden', __( 'You do not have permission to update Google Calendar events.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// Gate 1: sanitise every caller-supplied value before any logic runs.
		$connection_id     = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$calendar_override = isset( $arguments['calendar_id'] ) ? sanitize_text_field( $arguments['calendar_id'] ) : '';
		$event_id          = isset( $arguments['event_id'] ) ? sanitize_text_field( $arguments['event_id'] ) : '';
		$timezone          = isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : '';
		$send_updates      = isset( $arguments['send_updates'] ) ? sanitize_text_field( $arguments['send_updates'] ) : '';
		$partial           = isset( $arguments['partial'] ) ? rest_sanitize_boolean( $arguments['partial'] ) : false;
		$status            = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';

		if ( '' === $event_id ) {
			return new WP_Error( 'wp_mcp_ai_calendar_missing_event_id', __( 'An event ID is required to update a Google Calendar event.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
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

		if ( '' !== $status && ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_status',
				sprintf(
					/* translators: %s: comma-separated list of accepted event statuses. */
					__( 'The status value must be one of: %s.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', self::ALLOWED_STATUSES )
				),
				array( 'status' => 400 )
			);
		}

		$credentials = WP_MCP_AI_Google_Calendar_Credentials::resolve( $connection_id, $context, $arguments );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Writing events requires the events scope. Granular consent lets users
		// approve a read-only subset, so this must be checked, not assumed.
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

		// Timed events must carry an explicit IANA zone so DST transitions and
		// recurring expansion stay deterministic.
		if ( '' === $timezone && ! empty( $credentials['timezone'] ) ) {
			$timezone = (string) $credentials['timezone'];
		}

		if ( '' === $timezone ) {
			$timezone = WP_MCP_AI_Google_Calendar_Credentials::default_timezone();
		}

		$changes = array();

		if ( isset( $arguments['summary'] ) ) {
			$changes['summary'] = sanitize_text_field( $arguments['summary'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$changes['description'] = wp_kses_post( $arguments['description'] );
		}

		if ( isset( $arguments['location'] ) ) {
			$changes['location'] = sanitize_text_field( $arguments['location'] );
		}

		if ( '' !== $status ) {
			$changes['status'] = $status;
		}

		if ( isset( $arguments['start_time'] ) && '' !== trim( (string) $arguments['start_time'] ) ) {
			$start = $this->build_time_field( sanitize_text_field( $arguments['start_time'] ), $timezone );

			if ( is_wp_error( $start ) ) {
				return $start;
			}

			$changes['start'] = $start;
		}

		if ( isset( $arguments['end_time'] ) && '' !== trim( (string) $arguments['end_time'] ) ) {
			$end = $this->build_time_field( sanitize_text_field( $arguments['end_time'] ), $timezone );

			if ( is_wp_error( $end ) ) {
				return $end;
			}

			$changes['end'] = $end;
		}

		// Google refuses events that mix an all-day bound with a timed bound.
		if ( isset( $changes['start'], $changes['end'] ) && isset( $changes['start']['date'] ) !== isset( $changes['end']['date'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_mixed_time_types',
				__( 'Start and end must both be all-day dates (YYYY-MM-DD) or both be full timestamps. Remember that all-day end dates are exclusive.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $changes ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_nothing_to_update',
				__( 'Supply at least one field to change: summary, description, location, start_time, end_time, or status.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
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

		if ( $partial ) {
			$method  = 'patch';
			$decoded = $client->patch_event( $calendar_id, $event_id, $changes, $query );
		} else {
			$method = 'update';

			$existing = $client->get_event( $calendar_id, $event_id );

			if ( is_wp_error( $existing ) ) {
				return $existing;
			}

			$body = $this->strip_read_only_keys( $existing );

			// Caller-supplied fields win over the fetched resource.
			foreach ( $changes as $key => $value ) {
				$body[ $key ] = $value;
			}

			$decoded = $client->update_event( $calendar_id, $event_id, $body, $query );
		}

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return array(
			'success'           => true,
			'event_id'          => isset( $decoded['id'] ) ? sanitize_text_field( (string) $decoded['id'] ) : $event_id,
			'calendar_id'       => $calendar_id,
			'html_link'         => isset( $decoded['htmlLink'] ) ? esc_url_raw( (string) $decoded['htmlLink'] ) : '',
			'status'            => isset( $decoded['status'] ) ? sanitize_text_field( (string) $decoded['status'] ) : '',
			'summary'           => isset( $decoded['summary'] ) ? sanitize_text_field( (string) $decoded['summary'] ) : '',
			'start'             => isset( $decoded['start'] ) && is_array( $decoded['start'] ) ? $this->format_time_field( $decoded['start'] ) : array(),
			'end'               => isset( $decoded['end'] ) && is_array( $decoded['end'] ) ? $this->format_time_field( $decoded['end'] ) : array(),
			'method'            => $method,
			'credential_source' => isset( $credentials['source'] ) ? (string) $credentials['source'] : '',
		);
	}

	/**
	 * Build a Google Calendar `start`/`end` object from a caller value.
	 *
	 * A bare `YYYY-MM-DD` produces an all-day bound (`date`); anything else is
	 * parsed as a timestamp and produces a timed bound (`dateTime` plus
	 * `timeZone`). The two shapes are never mixed on a single bound, which is
	 * what the Calendar API requires.
	 *
	 * @param string $value    Raw date or timestamp.
	 * @param string $timezone Effective IANA timezone identifier.
	 * @return array|WP_Error Time object or error.
	 */
	protected function build_time_field( $value, $timezone ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_time',
				__( 'A date or timestamp value must be supplied.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return array( 'date' => $value );
		}

		try {
			$zone = new DateTimeZone( '' !== $timezone ? $timezone : 'UTC' );
		} catch ( Exception $exception ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_timezone',
				__( 'The provided timezone is not a valid IANA identifier.', 'mcp-ai-wpoos-pro' ),
				array(
					'status' => 400,
					'field'  => 'timezone',
				)
			);
		}

		try {
			$datetime = new DateTime( $value, $zone );
		} catch ( Exception $exception ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_time',
				sprintf(
					/* translators: %s: the unparseable date/time value supplied by the caller. */
					__( 'Unable to parse "%s" as a date or timestamp. Use YYYY-MM-DD for all-day events or an RFC3339 timestamp such as 2026-06-01T09:00:00+02:00.', 'mcp-ai-wpoos-pro' ),
					$value
				),
				array( 'status' => 400 )
			);
		}

		return array(
			'dateTime' => $datetime->format( DATE_RFC3339 ),
			'timeZone' => '' !== $timezone ? $timezone : 'UTC',
		);
	}

	/**
	 * Remove Google-computed members from a fetched event resource.
	 *
	 * @param array $event Raw event resource.
	 * @return array Writable subset of the resource.
	 */
	protected function strip_read_only_keys( array $event ) {
		$writable = array();

		foreach ( $event as $key => $value ) {
			if ( in_array( $key, self::READ_ONLY_KEYS, true ) ) {
				continue;
			}

			$writable[ $key ] = $value;
		}

		return $writable;
	}

	/**
	 * Normalise a Google `start`/`end` object, preserving its native shape.
	 *
	 * @param array $value Raw time object.
	 * @return array Normalised time object.
	 */
	protected function format_time_field( array $value ) {
		$formatted = array();

		if ( isset( $value['date'] ) ) {
			$formatted['date'] = sanitize_text_field( (string) $value['date'] );
		}

		if ( isset( $value['dateTime'] ) ) {
			$formatted['dateTime'] = sanitize_text_field( (string) $value['dateTime'] );
		}

		if ( isset( $value['timeZone'] ) ) {
			$formatted['timeZone'] = sanitize_text_field( (string) $value['timeZone'] );
		}

		return $formatted;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                     // Pro tier tool.
			'write',                   // Modifies calendar events.
			'state-changing',          // Mutates remote calendar state.
			'external-api',            // Calls Google Calendar API.
			'network-dependent',       // Requires internet connectivity.
			'external-communication',  // May email attendees via sendUpdates.
			'requires-capability',     // Requires user capabilities.
		);
	}
}
