<?php
/**
 * Tool that lists events from a connected Google Calendar.
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
 * Provides an assistant tool that reads events from Google Calendar.
 *
 * Two Calendar API constraints are enforced here rather than left to Google to
 * reject:
 *
 * 1. `orderBy=startTime` is only valid alongside `singleEvents=true`, because
 *    an unexpanded recurring event has no single start time to sort on.
 * 2. `timeMin` / `timeMax` must be RFC3339 values that carry a timezone
 *    offset. A bare `2026-06-01T09:00:00` is rejected by the API, so incoming
 *    values are re-formatted with an explicit offset.
 */
class WP_MCP_AI_Pro_Tool_List_Google_Calendar_Events implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Capability required before the tool talks to the Calendar API.
	 *
	 * @var string
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Page size used when the caller omits `max_results`.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_RESULTS = 50;

	/**
	 * Default HTTP timeout for the Calendar request, in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Sort orders Google accepts on `events.list`.
	 *
	 * @var array<string>
	 */
	const ALLOWED_ORDER_BY = array( 'startTime', 'updated' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_google_calendar_events';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Google Calendar Events', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists events from a Google Calendar within an optional time window, with free-text search, pagination, and recurring-event expansion. Returns each event\'s ID, title, start/end, all-day flag, organiser, attendee count, and Google Meet link. Use list_google_calendars first to discover calendar IDs.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Calendar identifier to read from. Defaults to the configured calendar, or "primary".', 'mcp-ai-wpoos-pro' ),
				),
				'time_min'      => array(
					'type'        => 'string',
					'description' => __( 'Lower bound (inclusive) for an event\'s end time, as an RFC3339 timestamp such as "2026-06-01T00:00:00+02:00". A timezone offset is mandatory; values without one are interpreted in the site timezone.', 'mcp-ai-wpoos-pro' ),
				),
				'time_max'      => array(
					'type'        => 'string',
					'description' => __( 'Upper bound (exclusive) for an event\'s start time, as an RFC3339 timestamp such as "2026-06-30T23:59:59+02:00". A timezone offset is mandatory; values without one are interpreted in the site timezone.', 'mcp-ai-wpoos-pro' ),
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Optional free-text search across event summary, description, location, attendee names, and organiser.', 'mcp-ai-wpoos-pro' ),
				),
				'max_results'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of events to return per page (1-2500). Defaults to 50.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => WP_MCP_AI_Google_Calendar_Client::MAX_RESULTS_CAP,
					'default'     => self::DEFAULT_MAX_RESULTS,
				),
				'single_events' => array(
					'type'        => 'boolean',
					'description' => __( 'Expand recurring events into individual instances. Defaults to true, and is forced to true when order_by is "startTime".', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'order_by'      => array(
					'type'        => 'string',
					'description' => __( 'Sort order: "startTime" (requires expanded instances) or "updated". Google returns an unspecified, stable order when omitted.', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::ALLOWED_ORDER_BY,
				),
				'show_deleted'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include cancelled events in the results. Defaults to false.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'timezone'      => array(
					'type'        => 'string',
					'description' => __( 'Optional IANA timezone identifier (e.g. "Europe/Zurich") used for the response. UTC offsets are not accepted by Google.', 'mcp-ai-wpoos-pro' ),
				),
				'page_token'    => array(
					'type'        => 'string',
					'description' => __( 'Page token returned by a previous call as next_page_token, used to fetch the following page.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array(),
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
			'wp_mcp_ai_list_google_calendar_events_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_forbidden', __( 'You do not have permission to read Google Calendar events.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// Gate 1: sanitise every caller-supplied value before any logic runs.
		$connection_id     = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$calendar_override = isset( $arguments['calendar_id'] ) ? sanitize_text_field( $arguments['calendar_id'] ) : '';
		$time_min_raw      = isset( $arguments['time_min'] ) ? sanitize_text_field( $arguments['time_min'] ) : '';
		$time_max_raw      = isset( $arguments['time_max'] ) ? sanitize_text_field( $arguments['time_max'] ) : '';
		$query             = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$max_results       = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : self::DEFAULT_MAX_RESULTS;
		$single_events     = isset( $arguments['single_events'] ) ? rest_sanitize_boolean( $arguments['single_events'] ) : true;
		$order_by          = isset( $arguments['order_by'] ) ? sanitize_text_field( $arguments['order_by'] ) : '';
		$show_deleted      = isset( $arguments['show_deleted'] ) ? rest_sanitize_boolean( $arguments['show_deleted'] ) : false;
		$timezone          = isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : '';
		$page_token        = isset( $arguments['page_token'] ) ? sanitize_text_field( $arguments['page_token'] ) : '';

		if ( '' !== $order_by && ! in_array( $order_by, self::ALLOWED_ORDER_BY, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_order_by',
				sprintf(
					/* translators: %s: comma-separated list of accepted sort orders. */
					__( 'The order_by value must be one of: %s.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', self::ALLOWED_ORDER_BY )
				),
				array( 'status' => 400 )
			);
		}

		// Google rejects `orderBy=startTime` unless recurring events have been
		// expanded, because an unexpanded series has no single start time.
		if ( 'startTime' === $order_by ) {
			$single_events = true;
		}

		$max_results = WP_MCP_AI_Google_Calendar_Client::clamp_max_results( $max_results );

		$credentials = WP_MCP_AI_Google_Calendar_Credentials::resolve( $connection_id, $context, $arguments );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Reading events requires the read-only events scope at minimum.
		$scope_check = WP_MCP_AI_Google_Calendar_Credentials::require_scope(
			$credentials,
			WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$calendar_id = WP_MCP_AI_Google_Calendar_Credentials::resolve_calendar_id( $credentials, $calendar_override );

		if ( '' === $calendar_id ) {
			return new WP_Error( 'wp_mcp_ai_calendar_missing_calendar', __( 'A target Google Calendar identifier is required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		// Fall back to the connection/site timezone so the response is stable
		// regardless of the calendar's own implicit default.
		if ( '' === $timezone && ! empty( $credentials['timezone'] ) ) {
			$timezone = (string) $credentials['timezone'];
		}

		if ( '' === $timezone ) {
			$timezone = WP_MCP_AI_Google_Calendar_Credentials::default_timezone();
		}

		$params = array(
			'maxResults'   => $max_results,
			'singleEvents' => $single_events,
			'showDeleted'  => $show_deleted,
			'timeZone'     => $timezone,
		);

		if ( '' !== $order_by ) {
			$params['orderBy'] = $order_by;
		}

		if ( '' !== $query ) {
			$params['q'] = $query;
		}

		if ( '' !== $page_token ) {
			$params['pageToken'] = $page_token;
		}

		if ( '' !== $time_min_raw ) {
			$time_min = $this->normalise_rfc3339( $time_min_raw, $timezone, 'time_min' );

			if ( is_wp_error( $time_min ) ) {
				return $time_min;
			}

			$params['timeMin'] = $time_min;
		}

		if ( '' !== $time_max_raw ) {
			$time_max = $this->normalise_rfc3339( $time_max_raw, $timezone, 'time_max' );

			if ( is_wp_error( $time_max ) ) {
				return $time_max;
			}

			$params['timeMax'] = $time_max;
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

		$decoded = $client->list_events( $calendar_id, $params );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$items  = isset( $decoded['items'] ) && is_array( $decoded['items'] ) ? $decoded['items'] : array();
		$events = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$events[] = $this->format_event( $item );
		}

		return array(
			'success'           => true,
			'calendar_id'       => $calendar_id,
			'count'             => count( $events ),
			'next_page_token'   => isset( $decoded['nextPageToken'] ) ? sanitize_text_field( (string) $decoded['nextPageToken'] ) : '',
			'next_sync_token'   => isset( $decoded['nextSyncToken'] ) ? sanitize_text_field( (string) $decoded['nextSyncToken'] ) : '',
			'time_zone'         => isset( $decoded['timeZone'] ) ? sanitize_text_field( (string) $decoded['timeZone'] ) : $timezone,
			'events'            => $events,
			'credential_source' => isset( $credentials['source'] ) ? (string) $credentials['source'] : '',
		);
	}

	/**
	 * Reduce a Google event resource to the fields assistants need.
	 *
	 * `all_day` is derived from the presence of `start.date`: Google uses
	 * `start.date` for all-day events and `start.dateTime` for timed ones, and
	 * never both. Instances of a recurring series additionally carry
	 * `recurringEventId` plus `originalStartTime`, both surfaced so callers can
	 * distinguish an exception from a plain instance.
	 *
	 * @param array $item Raw event resource.
	 * @return array Normalised event summary.
	 */
	protected function format_event( array $item ) {
		$start = isset( $item['start'] ) && is_array( $item['start'] ) ? $item['start'] : array();
		$end   = isset( $item['end'] ) && is_array( $item['end'] ) ? $item['end'] : array();

		return array(
			'id'                  => isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '',
			'summary'             => isset( $item['summary'] ) ? sanitize_text_field( (string) $item['summary'] ) : '',
			'description'         => isset( $item['description'] ) ? sanitize_textarea_field( (string) $item['description'] ) : '',
			'location'            => isset( $item['location'] ) ? sanitize_text_field( (string) $item['location'] ) : '',
			'status'              => isset( $item['status'] ) ? sanitize_text_field( (string) $item['status'] ) : '',
			'html_link'           => isset( $item['htmlLink'] ) ? esc_url_raw( (string) $item['htmlLink'] ) : '',
			'start'               => $this->format_time_field( $start ),
			'end'                 => $this->format_time_field( $end ),
			'all_day'             => isset( $start['date'] ),
			'recurring_event_id'  => isset( $item['recurringEventId'] ) ? sanitize_text_field( (string) $item['recurringEventId'] ) : '',
			'original_start_time' => isset( $item['originalStartTime'] ) && is_array( $item['originalStartTime'] )
				? $this->format_time_field( $item['originalStartTime'] )
				: array(),
			'attendee_count'      => isset( $item['attendees'] ) && is_array( $item['attendees'] ) ? count( $item['attendees'] ) : 0,
			'organizer_email'     => isset( $item['organizer']['email'] ) ? sanitize_email( (string) $item['organizer']['email'] ) : '',
			'meet_link'           => $this->extract_meet_link( $item ),
		);
	}

	/**
	 * Normalise a Google `start`/`end` object, preserving its native shape.
	 *
	 * The Calendar key names are kept verbatim so callers can keep relying on
	 * the documented `date` vs `dateTime` distinction.
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
	 * Extract the Google Meet video link from an event resource.
	 *
	 * `hangoutLink` is the legacy convenience field; `conferenceData` is
	 * authoritative and is checked first.
	 *
	 * @param array $item Raw event resource.
	 * @return string Meet URL, or an empty string when the event has none.
	 */
	protected function extract_meet_link( array $item ) {
		if ( ! empty( $item['conferenceData']['entryPoints'] ) && is_array( $item['conferenceData']['entryPoints'] ) ) {
			foreach ( $item['conferenceData']['entryPoints'] as $entry_point ) {
				if ( ! is_array( $entry_point ) ) {
					continue;
				}

				if ( isset( $entry_point['entryPointType'], $entry_point['uri'] ) && 'video' === $entry_point['entryPointType'] ) {
					return esc_url_raw( (string) $entry_point['uri'] );
				}
			}
		}

		if ( ! empty( $item['hangoutLink'] ) ) {
			return esc_url_raw( (string) $item['hangoutLink'] );
		}

		return '';
	}

	/**
	 * Convert a caller-supplied timestamp into RFC3339 with an explicit offset.
	 *
	 * The Calendar API rejects `timeMin`/`timeMax` values that omit a timezone
	 * offset, so a bare local timestamp is anchored to the effective timezone
	 * and re-serialised rather than forwarded as-is.
	 *
	 * @param string $value    Raw timestamp.
	 * @param string $timezone Effective IANA timezone identifier.
	 * @param string $label    Argument name, echoed in error data.
	 * @return string|WP_Error RFC3339 timestamp or error.
	 */
	protected function normalise_rfc3339( $value, $timezone, $label ) {
		$value = trim( (string) $value );

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
					/* translators: %s: argument name, e.g. time_min. */
					__( 'Unable to parse the %s value. Provide an RFC3339 timestamp such as 2026-06-01T00:00:00+02:00.', 'mcp-ai-wpoos-pro' ),
					$label
				),
				array(
					'status' => 400,
					'field'  => $label,
				)
			);
		}

		return $datetime->format( DATE_RFC3339 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Calls Google Calendar API.
			'network-dependent',    // Requires internet connectivity.
			'paginated',            // Supports page tokens.
			'cacheable',            // Results can be cached briefly.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
