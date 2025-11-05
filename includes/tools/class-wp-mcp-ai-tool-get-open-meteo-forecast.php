<?php
/**
 * Tool that fetches weather forecasts from the Open-Meteo API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves hourly weather forecast data from Open-Meteo.
 */
class WP_MCP_AI_Tool_Get_Open_Meteo_Forecast implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_open_meteo_forecast';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Weather Forecast (Open-Meteo)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves hourly weather forecast data for a location using the Open-Meteo API.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'latitude'      => array(
					'type'        => 'number',
					'description' => __( 'Geographic latitude in decimal degrees (required).', 'wp-mcp-ai' ),
				),
				'longitude'     => array(
					'type'        => 'number',
					'description' => __( 'Geographic longitude in decimal degrees (required).', 'wp-mcp-ai' ),
				),
				'hourly'        => array(
					'description' => __( 'Comma separated list or array of hourly variables. Valid hourly variables include: temperature_2m, relative_humidity_2m, precipitation, rain, snowfall, cloud_cover, wind_speed_10m, wind_direction_10m, etc. Note: Do NOT use daily variables like precipitation_sum, rain_sum, snowfall_sum, temperature_2m_max, or temperature_2m_min - these are only valid for daily forecasts.', 'wp-mcp-ai' ),
					'oneOf'       => array(
						array(
							'type' => 'string',
						),
						array(
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
							),
							'minItems' => 1,
						),
					),
				),
				'forecast_days' => array(
					'type'        => 'integer',
					'description' => __( 'Number of forecast days to request (1-16).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 16,
					'default'     => 7,
				),
				'timezone'      => array(
					'type'        => 'string',
					'description' => __( 'IANA timezone identifier to use for the response (e.g. Europe/Berlin).', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'latitude', 'longitude', 'hourly' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to request weather forecasts.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$latitude  = $this->parse_float_argument( $arguments, 'latitude' );
		$longitude = $this->parse_float_argument( $arguments, 'longitude' );
		$hourly    = $this->prepare_hourly_argument( $arguments );

		if ( null === $latitude || null === $longitude ) {
			return new WP_Error( 'wp_mcp_ai_missing_coordinates', __( 'Valid latitude and longitude values are required.', 'wp-mcp-ai' ) );
		}

		// Check if hourly validation returned an error.
		if ( is_wp_error( $hourly ) ) {
			return $hourly;
		}

		if ( empty( $hourly ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_hourly', __( 'At least one hourly variable must be specified.', 'wp-mcp-ai' ) );
		}

		$query_args = array(
			'latitude'  => $latitude,
			'longitude' => $longitude,
			'hourly'    => $hourly,
		);

		if ( isset( $arguments['forecast_days'] ) ) {
			$forecast_days = absint( $arguments['forecast_days'] );
			if ( $forecast_days > 0 ) {
				$query_args['forecast_days'] = (int) min( 16, max( 1, $forecast_days ) );
			}
		}

		if ( ! empty( $arguments['timezone'] ) ) {
			$query_args['timezone'] = sanitize_text_field( $arguments['timezone'] );
		}

		$request_url = add_query_arg( $query_args, 'https://api.open-meteo.com/v1/forecast' );

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_forecast_request_failed',
				__( 'The weather forecast request failed.', 'wp-mcp-ai' ),
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			$body         = wp_remote_retrieve_body( $response );
			$error_data   = json_decode( $body, true );
			$error_detail = '';

			// Try to extract error details from the API response.
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $error_data ) ) {
				if ( ! empty( $error_data['reason'] ) ) {
					$error_detail = sanitize_text_field( $error_data['reason'] );
				} elseif ( ! empty( $error_data['message'] ) ) {
					$error_detail = sanitize_text_field( $error_data['message'] );
				} elseif ( ! empty( $error_data['error'] ) ) {
					$error_detail = sanitize_text_field( $error_data['error'] );
				}
			}

			$error_message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'The weather service returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
				(int) $status_code
			);

			if ( ! empty( $error_detail ) ) {
				$error_message .= ' ' . sprintf(
					/* translators: %s: Error detail from API */
					__( 'Error: %s', 'wp-mcp-ai' ),
					$error_detail
				);
			}

			return new WP_Error(
				'wp_mcp_ai_forecast_http_error',
				$error_message
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error( 'wp_mcp_ai_forecast_bad_json', __( 'The weather service response could not be decoded.', 'wp-mcp-ai' ) );
		}

		return $this->prepare_response_payload( $data );
	}

	/**
	 * Attempt to parse a float argument from the request.
	 *
	 * @param array  $arguments Arguments passed to the tool.
	 * @param string $key       Argument key to parse.
	 *
	 * @return float|null
	 */
	protected function parse_float_argument( array $arguments, $key ) {
		if ( ! isset( $arguments[ $key ] ) || '' === $arguments[ $key ] ) {
			return null;
		}

		if ( is_numeric( $arguments[ $key ] ) ) {
			return (float) $arguments[ $key ];
		}

		return null;
	}

	/**
	 * Prepare the hourly parameter, accepting either a string or an array.
	 *
	 * @param array $arguments Arguments passed to the tool.
	 *
	 * @return string|WP_Error String of comma-separated variables, or WP_Error if validation fails.
	 */
	protected function prepare_hourly_argument( array $arguments ) {
		if ( empty( $arguments['hourly'] ) ) {
			return '';
		}

		$hourly = $arguments['hourly'];

		if ( is_array( $hourly ) ) {
			$hourly = array_filter( array_map( 'sanitize_key', $hourly ) );
			$hourly_string = implode( ',', $hourly );
		} else {
			$hourly_string = sanitize_text_field( $hourly );
			$hourly_string = str_replace( ' ', '', $hourly_string );
		}

		// Validate that daily-only variables are not being used as hourly variables.
		$validation_error = $this->validate_hourly_variables( $hourly_string );
		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		return $hourly_string;
	}

	/**
	 * Validate that daily-only variables are not used as hourly variables.
	 *
	 * @param string $hourly_string Comma-separated list of hourly variables.
	 *
	 * @return true|WP_Error True if valid, WP_Error if invalid daily variables detected.
	 */
	protected function validate_hourly_variables( $hourly_string ) {
		// List of common daily-only variables that cannot be used as hourly variables.
		$daily_only_variables = array(
			'precipitation_sum',
			'rain_sum',
			'snowfall_sum',
			'temperature_2m_max',
			'temperature_2m_min',
			'apparent_temperature_max',
			'apparent_temperature_min',
			'sunrise',
			'sunset',
		);

		$variables      = explode( ',', $hourly_string );
		$invalid_vars   = array();

		foreach ( $variables as $var ) {
			$var = trim( $var );
			if ( in_array( $var, $daily_only_variables, true ) ) {
				$invalid_vars[] = $var;
			}
		}

		if ( ! empty( $invalid_vars ) ) {
			$invalid_list = implode( ', ', $invalid_vars );

			return new WP_Error(
				'wp_mcp_ai_invalid_hourly_variable',
				sprintf(
					/* translators: %s: comma-separated list of invalid variables */
					__( 'The following variables are only available for daily forecasts, not hourly: %s. For hourly precipitation data, use "precipitation" instead of "precipitation_sum". For hourly rain, use "rain" instead of "rain_sum". For hourly snowfall, use "snowfall" instead of "snowfall_sum".', 'wp-mcp-ai' ),
					$invalid_list
				)
			);
		}

		return true;
	}

	/**
	 * Sanitise and shape the API response for downstream consumers.
	 *
	 * @param array $data Decoded API response.
	 *
	 * @return array
	 */
	protected function prepare_response_payload( array $data ) {
		$payload = array(
			'latitude'              => isset( $data['latitude'] ) ? (float) $data['latitude'] : null,
			'longitude'             => isset( $data['longitude'] ) ? (float) $data['longitude'] : null,
			'generation_time_ms'    => isset( $data['generationtime_ms'] ) ? (float) $data['generationtime_ms'] : null,
			'utc_offset_seconds'    => isset( $data['utc_offset_seconds'] ) ? (int) $data['utc_offset_seconds'] : null,
			'timezone'              => isset( $data['timezone'] ) ? sanitize_text_field( $data['timezone'] ) : null,
			'timezone_abbreviation' => isset( $data['timezone_abbreviation'] ) ? sanitize_text_field( $data['timezone_abbreviation'] ) : null,
			'elevation'             => isset( $data['elevation'] ) ? (float) $data['elevation'] : null,
			'hourly_units'          => array(),
			'hourly'                => array(),
		);

		if ( isset( $data['hourly_units'] ) && is_array( $data['hourly_units'] ) ) {
			foreach ( $data['hourly_units'] as $key => $value ) {
				$sanitized_key                             = sanitize_key( $key );
				$payload['hourly_units'][ $sanitized_key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
			}
		}

		if ( isset( $data['hourly'] ) && is_array( $data['hourly'] ) ) {
			foreach ( $data['hourly'] as $key => $values ) {
				if ( ! is_array( $values ) ) {
					continue;
				}

				$sanitized_key                       = sanitize_key( $key );
				$payload['hourly'][ $sanitized_key ] = array();

				foreach ( $values as $value ) {
					if ( is_numeric( $value ) ) {
						$payload['hourly'][ $sanitized_key ][] = (float) $value;
					} else {
						$payload['hourly'][ $sanitized_key ][] = sanitize_text_field( (string) $value );
					}
				}
			}
		}

		return $payload;
	}
}
