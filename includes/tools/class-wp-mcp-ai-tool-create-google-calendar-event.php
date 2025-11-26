<?php
/**
 * Tool for creating Google Calendar events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-interface.php';

/**
 * Provides an assistant tool that creates events in Google Calendar.
 */
class WP_MCP_AI_Tool_Create_Google_Calendar_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';
	const TOKEN_GRANT_TYPE            = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
	const DEFAULT_SCOPE               = 'https://www.googleapis.com/auth/calendar.events';
	const DEFAULT_TOKEN_URI           = 'https://oauth2.googleapis.com/token';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_google_calendar_event';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Google Calendar Event', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates an event in a connected Google Calendar using either a provided access token or a service account.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'summary'          => array(
					'type'        => 'string',
					'description' => __( 'Event title that will appear on the calendar.', 'wp-mcp-ai' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Optional long-form description for the event.', 'wp-mcp-ai' ),
				),
				'location'         => array(
					'type'        => 'string',
					'description' => __( 'Optional location for the event.', 'wp-mcp-ai' ),
				),
				'start_time'       => array(
					'type'        => 'string',
					'description' => __( 'Event start time in ISO 8601 format or YYYY-MM-DD for all-day events.', 'wp-mcp-ai' ),
				),
				'end_time'         => array(
					'type'        => 'string',
					'description' => __( 'Event end time in ISO 8601 format or YYYY-MM-DD for all-day events.', 'wp-mcp-ai' ),
				),
				'duration_minutes' => array(
					'type'        => array( 'number', 'integer' ),
					'minimum'     => 1,
					'description' => __( 'Duration in minutes to use when end_time is omitted.', 'wp-mcp-ai' ),
				),
				'timezone'         => array(
					'type'        => 'string',
					'description' => __( 'Optional IANA timezone identifier applied to the start and end time.', 'wp-mcp-ai' ),
				),
				'calendar_id'      => array(
					'type'        => 'string',
					'description' => __( 'Override the calendar identifier. Falls back to the configured default.', 'wp-mcp-ai' ),
				),
				'attendees'        => array(
					'type'        => 'array',
					'description' => __( 'Optional attendee list. Provide email strings or objects with email, name, and optional status.', 'wp-mcp-ai' ),
					'items'       => array(
						'oneOf' => array(
							array(
								'type' => 'string',
							),
							array(
								'type'                 => 'object',
								'properties'           => array(
									'email'    => array( 'type' => 'string' ),
									'name'     => array( 'type' => 'string' ),
									'optional' => array( 'type' => 'boolean' ),
								),
								'required'             => array( 'email' ),
								'additionalProperties' => true,
							),
						),
					),
				),
				'send_updates'     => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'externalOnly', 'none' ),
					'description' => __( 'Controls whether attendees receive update emails (all, externalOnly, or none).', 'wp-mcp-ai' ),
				),
				'reminders'        => array(
					'type'                 => 'object',
					'description'          => __( 'Optional reminder overrides. Supports use_default and overrides array with method and minutes.', 'wp-mcp-ai' ),
					'properties'           => array(
						'use_default' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to use the calendar\'s default reminder settings.', 'wp-mcp-ai' ),
						),
						'overrides'   => array(
							'type'        => 'array',
							'description' => __( 'Custom reminder definitions.', 'wp-mcp-ai' ),
							'items'       => array(
								'type'                 => 'object',
								'properties'           => array(
									'method'  => array(
										'type' => 'string',
										'enum' => array( 'email', 'popup' ),
									),
									'minutes' => array(
										'type'    => array( 'number', 'integer' ),
										'minimum' => 0,
									),
								),
								'required'             => array( 'method', 'minutes' ),
								'additionalProperties' => false,
							),
						),
					),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'summary', 'start_time' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters(
			'wp_mcp_ai_google_calendar_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create calendar events.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
		}

		$summary = isset( $arguments['summary'] ) ? sanitize_text_field( $arguments['summary'] ) : '';
		if ( '' === $summary ) {
			return new WP_Error( 'wp_mcp_ai_missing_summary', __( 'An event summary is required.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$description = isset( $arguments['description'] ) ? trim( wp_kses_post( $arguments['description'] ) ) : '';
		$location    = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$timezone    = isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : '';

		$calendar_id = isset( $arguments['calendar_id'] ) ? sanitize_text_field( $arguments['calendar_id'] ) : '';
		if ( '' === $calendar_id ) {
			$calendar_id = apply_filters( 'wp_mcp_ai_google_calendar_default_calendar_id', '', $context, $arguments, $this );
			$calendar_id = sanitize_text_field( $calendar_id );
		}

		if ( '' === $calendar_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_calendar', __( 'A target Google Calendar identifier is required.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		if ( empty( $arguments['start_time'] ) || ! is_string( $arguments['start_time'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_start_time', __( 'A valid start time must be provided.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$start = $this->parse_event_time( $arguments['start_time'], $timezone, 'start_time' );
		if ( is_wp_error( $start ) ) {
			return $start;
		}

		$end = null;
		if ( ! empty( $arguments['end_time'] ) ) {
			if ( ! is_string( $arguments['end_time'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_end_time', __( 'The event end time must be a string value.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$end = $this->parse_event_time( $arguments['end_time'], $timezone, 'end_time' );
			if ( is_wp_error( $end ) ) {
				return $end;
			}
		}

		$duration_minutes = 0;
		if ( isset( $arguments['duration_minutes'] ) && '' !== $arguments['duration_minutes'] ) {
			if ( ! is_numeric( $arguments['duration_minutes'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_duration', __( 'The duration must be a numeric value.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$duration_minutes = (float) $arguments['duration_minutes'];
			if ( $duration_minutes <= 0 ) {
				return new WP_Error( 'wp_mcp_ai_invalid_duration', __( 'The duration must be greater than zero.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}
		}

		if ( null === $end ) {
			if ( $duration_minutes <= 0 ) {
				return new WP_Error( 'wp_mcp_ai_missing_end_time', __( 'Provide an end time or duration for the event.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			if ( 'dateTime' !== $start['type'] || ! isset( $start['datetime'] ) ) {
				return new WP_Error( 'wp_mcp_ai_duration_requires_time', __( 'Durations may only be used with date/time events.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$end_datetime = clone $start['datetime'];
			$end_datetime->modify( '+' . $duration_minutes . ' minutes' );

			$end_value = array( 'dateTime' => $end_datetime->format( DATE_RFC3339 ) );
			if ( $timezone ) {
				$end_value['timeZone'] = $timezone;
			}

			$end = array(
				'type'     => 'dateTime',
				'value'    => $end_value,
				'datetime' => $end_datetime,
			);
		}

		$validation = $this->validate_time_range( $start, $end );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$attendees = array();
		if ( ! empty( $arguments['attendees'] ) ) {
			$attendees = $this->normalise_attendees( $arguments['attendees'] );
		}

		$reminders = array();
		if ( ! empty( $arguments['reminders'] ) ) {
			$reminders = $this->normalise_reminders( $arguments['reminders'] );
		}

		$send_updates = '';
		if ( ! empty( $arguments['send_updates'] ) ) {
			$candidate = sanitize_text_field( $arguments['send_updates'] );
			if ( in_array( $candidate, array( 'all', 'externalOnly', 'none' ), true ) ) {
				$send_updates = $candidate;
			}
		}

		$payload = array(
			'summary' => $summary,
			'start'   => $start['value'],
			'end'     => $end['value'],
		);

		if ( '' !== $description ) {
			$payload['description'] = $description;
		}

		if ( '' !== $location ) {
			$payload['location'] = $location;
		}

		if ( ! empty( $attendees ) ) {
			$payload['attendees'] = $attendees;
		}

		if ( ! empty( $reminders ) ) {
			$payload['reminders'] = $reminders;
		}

		$token = $this->resolve_access_token( $arguments, $context );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$timeout = (int) apply_filters( 'wp_mcp_ai_google_calendar_request_timeout', 15, $context, $arguments, $this );
		if ( $timeout <= 0 ) {
			$timeout = 15;
		}

		$endpoint = sprintf(
			'https://www.googleapis.com/calendar/v3/calendars/%s/events',
			rawurlencode( $calendar_id )
		);

		if ( '' !== $send_updates ) {
			$endpoint = add_query_arg( 'sendUpdates', $send_updates, $endpoint );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'timeout' => $timeout,
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_request_failed',
				__( 'Unable to communicate with the Google Calendar API.', 'wp-mcp-ai' ),
				array(
					'status' => 500,
					'error'  => $response,
				)
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		$decoded = json_decode( $body, true );
		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = __( 'The Google Calendar API rejected the event request.', 'wp-mcp-ai' );
			if ( isset( $decoded['error']['message'] ) ) {
				$message = sprintf( '%s %s', $message, $decoded['error']['message'] );
			}

			return new WP_Error(
				'wp_mcp_ai_calendar_error',
				$message,
				array(
					'status'   => $status,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Resolve a Google API access token either from filters or a service account exchange.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Request context.
	 * @return string|WP_Error
	 */
	protected function resolve_access_token( array $arguments, array $context ) {
		$token = apply_filters( 'wp_mcp_ai_google_calendar_access_token', '', $context, $arguments, $this );
		if ( is_string( $token ) ) {
			$token = trim( $token );
		}

		if ( ! empty( $token ) ) {
			return $token;
		}

		$credentials = apply_filters( 'wp_mcp_ai_google_calendar_service_account_credentials', array(), $context, $arguments, $this );
		if ( empty( $credentials ) || ! is_array( $credentials ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_missing_credentials', __( 'Google Calendar credentials were not provided.', 'wp-mcp-ai' ), array( 'status' => 500 ) );
		}

		return $this->exchange_service_account_token( $credentials, $arguments, $context );
	}

	/**
	 * Exchange a service account credential for an access token.
	 *
	 * @param array $credentials Service account configuration.
	 * @param array $arguments   Tool arguments.
	 * @param array $context     Request context.
	 * @return string|WP_Error
	 */
	protected function exchange_service_account_token( array $credentials, array $arguments, array $context ) {
		$client_email = isset( $credentials['client_email'] ) ? sanitize_email( $credentials['client_email'] ) : '';
		$private_key  = isset( $credentials['private_key'] ) ? trim( (string) $credentials['private_key'] ) : '';
		$token_uri    = isset( $credentials['token_uri'] ) ? esc_url_raw( $credentials['token_uri'] ) : '';
		$delegated    = isset( $credentials['delegated_email'] ) ? sanitize_email( $credentials['delegated_email'] ) : '';

		if ( '' === $client_email || '' === $private_key ) {
			return new WP_Error( 'wp_mcp_ai_calendar_invalid_credentials', __( 'Incomplete Google service account credentials.', 'wp-mcp-ai' ), array( 'status' => 500 ) );
		}

		if ( '' === $token_uri ) {
			$token_uri = self::DEFAULT_TOKEN_URI;
		}

		$scopes = isset( $credentials['scopes'] ) ? $credentials['scopes'] : self::DEFAULT_SCOPE;
		if ( is_array( $scopes ) ) {
			$scopes = implode( ' ', array_filter( array_map( 'trim', $scopes ) ) );
		} else {
			$scopes = trim( (string) $scopes );
		}

		if ( '' === $scopes ) {
			$scopes = self::DEFAULT_SCOPE;
		}

		$now    = time();
		$claims = array(
			'iss'   => $client_email,
			'scope' => $scopes,
			'aud'   => $token_uri,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		if ( '' !== $delegated ) {
			$claims['sub'] = $delegated;
		}

		$assertion = $this->build_jwt_assertion( $claims, $private_key );
		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$timeout = (int) apply_filters( 'wp_mcp_ai_google_calendar_request_timeout', 15, $context, $arguments, $this );
		if ( $timeout <= 0 ) {
			$timeout = 15;
		}

		$response = wp_remote_post(
			$token_uri,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'timeout' => $timeout,
				'body'    => array(
					'grant_type' => self::TOKEN_GRANT_TYPE,
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_token_error',
				__( 'Unable to obtain a Google access token.', 'wp-mcp-ai' ),
				array(
					'status' => 500,
					'error'  => $response,
				)
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 || ! isset( $data['access_token'] ) ) {
			$message = __( 'Google rejected the service account token request.', 'wp-mcp-ai' );
			if ( isset( $data['error_description'] ) ) {
				$message = sprintf( '%s %s', $message, $data['error_description'] );
			}

			return new WP_Error(
				'wp_mcp_ai_calendar_token_error',
				$message,
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		return (string) $data['access_token'];
	}

	/**
	 * Build a signed JWT assertion using the provided claims and private key.
	 *
	 * @param array  $claims      Assertion claims.
	 * @param string $private_key RSA private key.
	 * @return string|WP_Error
	 */
	protected function build_jwt_assertion( array $claims, $private_key ) {
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$segments = array(
			$this->base64url_encode( wp_json_encode( $header ) ),
			$this->base64url_encode( wp_json_encode( $claims ) ),
		);

		$input     = implode( '.', $segments );
		$signature = '';

		$success = openssl_sign( $input, $signature, $private_key, 'sha256' );
		if ( ! $success ) {
			return new WP_Error( 'wp_mcp_ai_calendar_signing_failed', __( 'Unable to sign the service account assertion.', 'wp-mcp-ai' ), array( 'status' => 500 ) );
		}

		$segments[] = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Normalise a list of attendees into the Google Calendar payload format.
	 *
	 * @param array $attendees Raw attendee definitions.
	 * @return array
	 */
	protected function normalise_attendees( $attendees ) {
		if ( ! is_array( $attendees ) ) {
			return array();
		}

		$normalised = array();

		foreach ( $attendees as $attendee ) {
			$email = '';
			$entry = array();

			if ( is_string( $attendee ) ) {
				$email = sanitize_email( $attendee );
				if ( '' === $email ) {
					continue;
				}

				$entry = array( 'email' => $email );
			} elseif ( is_array( $attendee ) ) {
				$email = isset( $attendee['email'] ) ? sanitize_email( $attendee['email'] ) : '';
				if ( '' === $email ) {
					continue;
				}

				$entry = array( 'email' => $email );

				if ( isset( $attendee['name'] ) ) {
					$display = sanitize_text_field( $attendee['name'] );
					if ( '' !== $display ) {
						$entry['displayName'] = $display;
					}
				}

				if ( isset( $attendee['optional'] ) ) {
					$entry['optional'] = (bool) $attendee['optional'];
				}
			}

			if ( '' === $email ) {
				continue;
			}

			$normalised[ $email ] = $entry;
		}

		return array_values( $normalised );
	}

	/**
	 * Normalise reminder overrides into the Google Calendar payload format.
	 *
	 * @param array $reminders Raw reminder definition.
	 * @return array
	 */
	protected function normalise_reminders( $reminders ) {
		if ( ! is_array( $reminders ) ) {
			return array();
		}

		$payload = array();

		if ( array_key_exists( 'use_default', $reminders ) ) {
			$payload['useDefault'] = (bool) $reminders['use_default'];
		}

		if ( ! empty( $reminders['overrides'] ) && is_array( $reminders['overrides'] ) ) {
			$overrides = array();

			foreach ( $reminders['overrides'] as $override ) {
				if ( ! is_array( $override ) ) {
					continue;
				}

				$method = isset( $override['method'] ) ? sanitize_text_field( $override['method'] ) : '';
				if ( ! in_array( $method, array( 'email', 'popup' ), true ) ) {
					continue;
				}

				if ( ! isset( $override['minutes'] ) || ! is_numeric( $override['minutes'] ) ) {
					continue;
				}

				$minutes = (int) $override['minutes'];
				if ( $minutes < 0 ) {
					continue;
				}

				$overrides[] = array(
					'method'  => $method,
					'minutes' => $minutes,
				);
			}

			if ( ! empty( $overrides ) ) {
				$payload['overrides'] = $overrides;
			}
		}

		return $payload;
	}

	/**
	 * Validate that the event start and end times form a sane range.
	 *
	 * @param array $start Parsed start definition.
	 * @param array $end   Parsed end definition.
	 * @return true|WP_Error
	 */
	protected function validate_time_range( array $start, array $end ) {
		if ( $start['type'] === 'date' && $end['type'] === 'date' ) {
			$start_date = $start['value']['date'];
			$end_date   = $end['value']['date'];

			if ( strcmp( $end_date, $start_date ) <= 0 ) {
				return new WP_Error( 'wp_mcp_ai_invalid_time_range', __( 'The event end date must be after the start date.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			return true;
		}

		if ( $start['type'] !== 'dateTime' || $end['type'] !== 'dateTime' ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time_range', __( 'Start and end times must both be full date/time values or all-day dates.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		if ( ! isset( $start['datetime'], $end['datetime'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time_range', __( 'Invalid start or end time provided.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		if ( $end['datetime'] <= $start['datetime'] ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time_range', __( 'The event end time must be after the start time.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Parse a provided date/time string into the Google Calendar payload format.
	 *
	 * @param string $value    Raw date/time string.
	 * @param string $timezone Optional timezone override.
	 * @param string $label    Field label for error messages.
	 * @return array|WP_Error
	 */
	protected function parse_event_time( $value, $timezone, $label ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_time',
				__( 'A valid date or time value must be supplied.', 'wp-mcp-ai' ),
				array(
					'status' => 400,
					'field'  => $label,
				)
			);
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return array(
				'type'  => 'date',
				'value' => array( 'date' => $value ),
			);
		}

		try {
			$datetime = new DateTime( $value );
		} catch ( Exception $exception ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_time',
				__( 'Unable to parse the supplied date/time value.', 'wp-mcp-ai' ),
				array(
					'status' => 400,
					'field'  => $label,
				)
			);
		}

		if ( $timezone ) {
			try {
				$datetime->setTimezone( new DateTimeZone( $timezone ) );
			} catch ( Exception $exception ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_timezone',
					__( 'The provided timezone is not valid.', 'wp-mcp-ai' ),
					array(
						'status' => 400,
						'field'  => $label,
					)
				);
			}
		}

		$value = array( 'dateTime' => $datetime->format( DATE_RFC3339 ) );
		if ( $timezone ) {
			$value['timeZone'] = $timezone;
		}

		return array(
			'type'     => 'dateTime',
			'value'    => $value,
			'datetime' => $datetime,
		);
	}

	/**
	 * Base64 URL-safe encoding helper.
	 *
	 * @param string $data Raw data.
	 * @return string
	 */
	protected function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
