<?php
/**
 * Tool that checks free/busy availability across Google Calendars.
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
 * Provides an assistant tool that answers "is this slot free?" without exposing
 * event details.
 *
 * `freeBusy` returns only busy intervals, which makes it the correct primitive
 * for scheduling: it needs a narrower scope than reading events and it works
 * across calendars the caller cannot otherwise read. Google caps a single
 * request at 50 calendars in `items`, so that limit is enforced locally with an
 * actionable error rather than surfaced as a raw 400.
 */
class WP_MCP_AI_Pro_Tool_Check_Google_Calendar_Availability implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
	 * Maximum number of calendars Google accepts in a single freeBusy request.
	 *
	 * @var int
	 */
	const MAX_CALENDARS = 50;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_google_calendar_availability';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Google Calendar Availability', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks free/busy availability across up to 50 Google Calendars for a given time window and returns the busy intervals per calendar, plus an all_free flag. Use this to find a meeting slot before creating an event; it returns availability only, never event titles or attendees.', 'mcp-ai-wpoos-pro' );
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
				'time_min'      => array(
					'type'        => 'string',
					'description' => __( 'Start of the window to check, as an RFC3339 timestamp such as "2026-06-01T09:00:00+02:00". A timezone offset is mandatory; values without one are interpreted in the effective timezone.', 'mcp-ai-wpoos-pro' ),
				),
				'time_max'      => array(
					'type'        => 'string',
					'description' => __( 'End of the window to check, as an RFC3339 timestamp such as "2026-06-01T18:00:00+02:00". Must be later than time_min.', 'mcp-ai-wpoos-pro' ),
				),
				'calendar_ids'  => array(
					'type'        => 'array',
					'description' => __( 'Calendar identifiers or attendee email addresses to check. Defaults to ["primary"]. Google accepts at most 50 entries per request.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
					'maxItems'    => self::MAX_CALENDARS,
				),
				'timezone'      => array(
					'type'        => 'string',
					'description' => __( 'Optional IANA timezone identifier (e.g. "Europe/Zurich") used to interpret the window and format the response. UTC offsets are not accepted by Google.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'time_min', 'time_max' ),
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
			'wp_mcp_ai_check_google_calendar_availability_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_forbidden', __( 'You do not have permission to check Google Calendar availability.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// Gate 1: sanitise every caller-supplied value before any logic runs.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$time_min_raw  = isset( $arguments['time_min'] ) ? sanitize_text_field( $arguments['time_min'] ) : '';
		$time_max_raw  = isset( $arguments['time_max'] ) ? sanitize_text_field( $arguments['time_max'] ) : '';
		$timezone      = isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : '';
		$calendar_ids  = $this->sanitise_calendar_ids( isset( $arguments['calendar_ids'] ) ? $arguments['calendar_ids'] : array() );

		if ( '' === $time_min_raw || '' === $time_max_raw ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_missing_window',
				__( 'Both time_min and time_max are required to check availability.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $calendar_ids ) > self::MAX_CALENDARS ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_too_many_calendars',
				sprintf(
					/* translators: 1: number of calendars requested, 2: maximum number Google accepts. */
					__( 'You requested %1$d calendars, but Google accepts at most %2$d per free/busy request. Split the request into batches.', 'mcp-ai-wpoos-pro' ),
					count( $calendar_ids ),
					self::MAX_CALENDARS
				),
				array( 'status' => 400 )
			);
		}

		$credentials = WP_MCP_AI_Google_Calendar_Credentials::resolve( $connection_id, $context, $arguments );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// freeBusy has its own narrow scope, which is deliberately not implied by
		// the events scopes.
		$scope_check = WP_MCP_AI_Google_Calendar_Credentials::require_scope(
			$credentials,
			WP_MCP_AI_Google_Calendar_Scopes::SCOPE_FREEBUSY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		if ( empty( $calendar_ids ) ) {
			$calendar_ids = array( WP_MCP_AI_Google_Calendar_Credentials::resolve_calendar_id( $credentials ) );
		}

		if ( '' === $timezone && ! empty( $credentials['timezone'] ) ) {
			$timezone = (string) $credentials['timezone'];
		}

		if ( '' === $timezone ) {
			$timezone = WP_MCP_AI_Google_Calendar_Credentials::default_timezone();
		}

		$time_min = $this->normalise_rfc3339( $time_min_raw, $timezone, 'time_min' );

		if ( is_wp_error( $time_min ) ) {
			return $time_min;
		}

		$time_max = $this->normalise_rfc3339( $time_max_raw, $timezone, 'time_max' );

		if ( is_wp_error( $time_max ) ) {
			return $time_max;
		}

		if ( strtotime( $time_max ) <= strtotime( $time_min ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_time_range',
				__( 'The time_max value must be later than time_min.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$items = array();

		foreach ( $calendar_ids as $calendar_id ) {
			$items[] = array( 'id' => $calendar_id );
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

		$decoded = $client->freebusy(
			array(
				'timeMin'  => $time_min,
				'timeMax'  => $time_max,
				'timeZone' => $timezone,
				'items'    => $items,
			)
		);

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$raw_calendars = isset( $decoded['calendars'] ) && is_array( $decoded['calendars'] ) ? $decoded['calendars'] : array();
		$calendars     = array();
		$all_free      = true;

		foreach ( $raw_calendars as $calendar_key => $calendar ) {
			$entry = $this->format_calendar_availability( is_array( $calendar ) ? $calendar : array() );

			// A calendar that could not be read must not be reported as free:
			// Google returns per-calendar `errors` rather than failing the call.
			if ( ! empty( $entry['busy'] ) || ! empty( $entry['errors'] ) ) {
				$all_free = false;
			}

			$calendars[ sanitize_text_field( (string) $calendar_key ) ] = $entry;
		}

		return array(
			'success'           => true,
			'time_min'          => $time_min,
			'time_max'          => $time_max,
			'time_zone'         => $timezone,
			'calendars'         => $calendars,
			'all_free'          => $all_free,
			'credential_source' => isset( $credentials['source'] ) ? (string) $credentials['source'] : '',
		);
	}

	/**
	 * Sanitise, trim, and de-duplicate the requested calendar identifiers.
	 *
	 * Duplicates are removed before the 50-calendar cap is evaluated so that a
	 * repeated identifier does not consume budget.
	 *
	 * @param mixed $value Raw `calendar_ids` argument.
	 * @return array<string> Unique calendar identifiers.
	 */
	protected function sanitise_calendar_ids( $value ) {
		if ( is_string( $value ) ) {
			$value = array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$identifiers = array();

		foreach ( $value as $candidate ) {
			if ( ! is_string( $candidate ) && ! is_numeric( $candidate ) ) {
				continue;
			}

			$identifier = trim( sanitize_text_field( (string) $candidate ) );

			if ( '' === $identifier ) {
				continue;
			}

			$identifiers[] = $identifier;
		}

		return array_values( array_unique( $identifiers ) );
	}

	/**
	 * Normalise a single calendar's free/busy payload.
	 *
	 * @param array $calendar Raw per-calendar freeBusy payload.
	 * @return array{busy:array<int,array<string,string>>,errors:array<int,array<string,string>>}
	 */
	protected function format_calendar_availability( array $calendar ) {
		$busy   = array();
		$errors = array();

		if ( isset( $calendar['busy'] ) && is_array( $calendar['busy'] ) ) {
			foreach ( $calendar['busy'] as $interval ) {
				if ( ! is_array( $interval ) ) {
					continue;
				}

				$busy[] = array(
					'start' => isset( $interval['start'] ) ? sanitize_text_field( (string) $interval['start'] ) : '',
					'end'   => isset( $interval['end'] ) ? sanitize_text_field( (string) $interval['end'] ) : '',
				);
			}
		}

		if ( isset( $calendar['errors'] ) && is_array( $calendar['errors'] ) ) {
			foreach ( $calendar['errors'] as $error ) {
				if ( ! is_array( $error ) ) {
					continue;
				}

				$errors[] = array(
					'domain' => isset( $error['domain'] ) ? sanitize_text_field( (string) $error['domain'] ) : '',
					'reason' => isset( $error['reason'] ) ? sanitize_text_field( (string) $error['reason'] ) : '',
				);
			}
		}

		return array(
			'busy'   => $busy,
			'errors' => $errors,
		);
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
					__( 'Unable to parse the %s value. Provide an RFC3339 timestamp such as 2026-06-01T09:00:00+02:00.', 'mcp-ai-wpoos-pro' ),
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
			'read-only',            // Only reads availability, does not modify state.
			'external-api',         // Calls Google Calendar API.
			'network-dependent',    // Requires internet connectivity.
			'cacheable',            // Results can be cached briefly.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
