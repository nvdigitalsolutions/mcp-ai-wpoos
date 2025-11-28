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
 * Supports JSON output or Chart.js visualization.
 */
class WP_MCP_AI_Tool_Get_Open_Meteo_Forecast implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Chart.js CDN URL for chart rendering.
	 *
	 * @var string
	 */
	const CHARTJS_CDN_URL = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js';
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
		return __( 'Retrieves hourly weather forecast data for a location using the Open-Meteo API. Supports both JSON output and interactive Chart.js visualizations.', 'wp-mcp-ai' );
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
				'output_format' => array(
					'type'        => 'string',
					'description' => __( 'Output format: "json" returns raw data (default), "chart" returns an interactive Chart.js visualization.', 'wp-mcp-ai' ),
					'enum'        => array( 'json', 'chart' ),
					'default'     => 'json',
				),
				'chart_type'    => array(
					'type'        => 'string',
					'description' => __( 'Chart type when output_format is "chart": "line" (default) for time series, "bar" for comparison.', 'wp-mcp-ai' ),
					'enum'        => array( 'line', 'bar' ),
					'default'     => 'line',
				),
				'chart_title'   => array(
					'type'        => 'string',
					'description' => __( 'Optional chart title. If not provided, a title will be generated from the location and variables.', 'wp-mcp-ai' ),
				),
				'chart_width'   => array(
					'type'        => 'integer',
					'description' => __( 'Chart canvas width in pixels (default: 900).', 'wp-mcp-ai' ),
					'minimum'     => 300,
					'maximum'     => 2000,
					'default'     => 900,
				),
				'chart_height'  => array(
					'type'        => 'integer',
					'description' => __( 'Chart canvas height in pixels (default: 500).', 'wp-mcp-ai' ),
					'minimum'     => 200,
					'maximum'     => 1500,
					'default'     => 500,
				),
			),
			'required'             => array( 'latitude', 'longitude', 'hourly' ),
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

		$payload = $this->prepare_response_payload( $data );

		// Check if chart output is requested.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'json';

		if ( 'chart' === $output_format ) {
			return $this->generate_chart_output( $payload, $arguments );
		}

		return $payload;
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
			$hourly        = array_filter( array_map( 'sanitize_key', $hourly ) );
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

		$variables    = explode( ',', $hourly_string );
		$invalid_vars = array();

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

	/**
	 * Generate chart output from weather data.
	 *
	 * @param array $payload   Prepared weather data payload.
	 * @param array $arguments Tool arguments including chart options.
	 *
	 * @return array Chart output with HTML and configuration.
	 */
	protected function generate_chart_output( array $payload, array $arguments ) {
		$chart_type   = isset( $arguments['chart_type'] ) ? sanitize_text_field( $arguments['chart_type'] ) : 'line';
		$chart_title  = isset( $arguments['chart_title'] ) ? sanitize_text_field( $arguments['chart_title'] ) : '';
		$chart_width  = isset( $arguments['chart_width'] ) ? absint( $arguments['chart_width'] ) : 900;
		$chart_height = isset( $arguments['chart_height'] ) ? absint( $arguments['chart_height'] ) : 500;

		// Clamp dimensions.
		$chart_width  = max( 300, min( 2000, $chart_width ) );
		$chart_height = max( 200, min( 1500, $chart_height ) );

		// Validate chart type.
		if ( ! in_array( $chart_type, array( 'line', 'bar' ), true ) ) {
			$chart_type = 'line';
		}

		// Build datasets from hourly data.
		$datasets = $this->build_chart_datasets( $payload );

		if ( empty( $datasets ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_chart_data',
				__( 'No numeric data available to create a chart.', 'wp-mcp-ai' )
			);
		}

		// Get time labels.
		$labels = isset( $payload['hourly']['time'] ) ? $payload['hourly']['time'] : array();

		// Format time labels for better readability.
		$labels = $this->format_time_labels( $labels );

		// Generate chart title if not provided.
		if ( empty( $chart_title ) ) {
			$chart_title = $this->generate_chart_title( $payload, $arguments );
		}

		// Build Chart.js configuration.
		$chart_config = $this->build_weather_chart_config( $chart_type, $labels, $datasets, $chart_title );

		// Generate HTML.
		$html = $this->generate_chart_html( $chart_config, $chart_width, $chart_height );

		return array(
			'output_format' => 'chart',
			'chart_type'    => $chart_type,
			'chart_title'   => $chart_title,
			'html'          => $html,
			'chart_config'  => $chart_config,
			'width'         => $chart_width,
			'height'        => $chart_height,
			'data'          => $payload,
		);
	}

	/**
	 * Build Chart.js datasets from weather data.
	 *
	 * @param array $payload Weather data payload.
	 *
	 * @return array Array of dataset configurations.
	 */
	protected function build_chart_datasets( array $payload ) {
		$datasets = array();
		$colors   = $this->get_chart_colors();
		$index    = 0;

		if ( ! isset( $payload['hourly'] ) || ! is_array( $payload['hourly'] ) ) {
			return $datasets;
		}

		foreach ( $payload['hourly'] as $key => $values ) {
			// Skip time array and non-numeric data.
			if ( 'time' === $key || ! is_array( $values ) || empty( $values ) ) {
				continue;
			}

			// Check if data is numeric.
			$first_value = reset( $values );
			if ( ! is_numeric( $first_value ) ) {
				continue;
			}

			$color      = $colors[ $index % count( $colors ) ];
			$unit       = isset( $payload['hourly_units'][ $key ] ) ? $payload['hourly_units'][ $key ] : '';
			$label      = $this->format_variable_label( $key );
			$full_label = $unit ? sprintf( '%s (%s)', $label, $unit ) : $label;

			$dataset = array(
				'label'            => $full_label,
				'data'             => array_map( 'floatval', $values ),
				'borderColor'      => $color,
				'backgroundColor'  => $this->hex_to_rgba( $color, 0.2 ),
				'borderWidth'      => 2,
				'tension'          => 0.3,
				'fill'             => false,
				'pointRadius'      => 0,
				'pointHoverRadius' => 5,
			);

			$datasets[] = $dataset;
			++$index;
		}

		return $datasets;
	}

	/**
	 * Get predefined chart colors for weather variables.
	 *
	 * @return array Array of hex color codes.
	 */
	protected function get_chart_colors() {
		return array(
			'#FF6384', // Red/Pink - Temperature.
			'#36A2EB', // Blue - Precipitation/Humidity.
			'#FFCE56', // Yellow - UV/Solar.
			'#4BC0C0', // Teal - Wind.
			'#9966FF', // Purple - Cloud cover.
			'#FF9F40', // Orange.
			'#C9CBCF', // Gray.
			'#7CB342', // Green.
		);
	}

	/**
	 * Convert hex color to rgba.
	 *
	 * @param string $hex   Hex color code.
	 * @param float  $alpha Alpha transparency (0-1).
	 *
	 * @return string RGBA color string.
	 */
	protected function hex_to_rgba( $hex, $alpha = 1.0 ) {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha );
	}

	/**
	 * Format variable name for display as a label.
	 *
	 * @param string $variable Variable key from Open-Meteo.
	 *
	 * @return string Human-readable label.
	 */
	protected function format_variable_label( $variable ) {
		$labels = array(
			'temperature_2m'       => __( 'Temperature', 'wp-mcp-ai' ),
			'relative_humidity_2m' => __( 'Humidity', 'wp-mcp-ai' ),
			'precipitation'        => __( 'Precipitation', 'wp-mcp-ai' ),
			'rain'                 => __( 'Rain', 'wp-mcp-ai' ),
			'snowfall'             => __( 'Snowfall', 'wp-mcp-ai' ),
			'cloud_cover'          => __( 'Cloud Cover', 'wp-mcp-ai' ),
			'wind_speed_10m'       => __( 'Wind Speed', 'wp-mcp-ai' ),
			'wind_direction_10m'   => __( 'Wind Direction', 'wp-mcp-ai' ),
			'apparent_temperature' => __( 'Feels Like', 'wp-mcp-ai' ),
			'pressure_msl'         => __( 'Pressure', 'wp-mcp-ai' ),
			'surface_pressure'     => __( 'Surface Pressure', 'wp-mcp-ai' ),
			'visibility'           => __( 'Visibility', 'wp-mcp-ai' ),
			'uv_index'             => __( 'UV Index', 'wp-mcp-ai' ),
			'dewpoint_2m'          => __( 'Dew Point', 'wp-mcp-ai' ),
		);

		if ( isset( $labels[ $variable ] ) ) {
			return $labels[ $variable ];
		}

		// Convert snake_case to Title Case.
		$formatted = str_replace( '_', ' ', $variable );
		$formatted = ucwords( $formatted );
		// Remove dimension suffixes.
		$formatted = preg_replace( '/\s*\d+m$/i', '', $formatted );

		return $formatted;
	}

	/**
	 * Format time labels for better chart readability.
	 *
	 * @param array $labels Array of ISO 8601 time strings.
	 *
	 * @return array Formatted time labels.
	 */
	protected function format_time_labels( array $labels ) {
		$formatted = array();

		foreach ( $labels as $time ) {
			if ( ! is_string( $time ) ) {
				$formatted[] = (string) $time;
				continue;
			}

			// Parse ISO 8601 format (2023-11-05T00:00).
			$timestamp = strtotime( $time );
			if ( false !== $timestamp ) {
				// Format as "Mon 05 00:00".
				$formatted[] = gmdate( 'D d H:i', $timestamp );
			} else {
				$formatted[] = $time;
			}
		}

		return $formatted;
	}

	/**
	 * Generate a chart title from the payload and arguments.
	 *
	 * @param array $payload   Weather data payload.
	 * @param array $arguments Tool arguments.
	 *
	 * @return string Generated chart title.
	 */
	protected function generate_chart_title( array $payload, array $arguments ) {
		$lat = isset( $payload['latitude'] ) ? round( (float) $payload['latitude'], 2 ) : '';
		$lng = isset( $payload['longitude'] ) ? round( (float) $payload['longitude'], 2 ) : '';

		$location = '';
		if ( '' !== $lat && '' !== $lng ) {
			$location = sprintf( '%s, %s', $lat, $lng );
		}

		$timezone = isset( $payload['timezone'] ) ? $payload['timezone'] : '';

		if ( $location && $timezone ) {
			/* translators: 1: latitude/longitude coordinates, 2: timezone */
			return sprintf( __( 'Weather Forecast for %1$s (%2$s)', 'wp-mcp-ai' ), $location, $timezone );
		} elseif ( $location ) {
			/* translators: %s: latitude/longitude coordinates */
			return sprintf( __( 'Weather Forecast for %s', 'wp-mcp-ai' ), $location );
		}

		return __( 'Weather Forecast', 'wp-mcp-ai' );
	}

	/**
	 * Build Chart.js configuration for weather data.
	 *
	 * @param string $type     Chart type (line or bar).
	 * @param array  $labels   Time labels for X-axis.
	 * @param array  $datasets Dataset configurations.
	 * @param string $title    Chart title.
	 *
	 * @return array Chart.js configuration object.
	 */
	protected function build_weather_chart_config( $type, array $labels, array $datasets, $title ) {
		$config = array(
			'type'    => $type,
			'data'    => array(
				'labels'   => $labels,
				'datasets' => $datasets,
			),
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => true,
				'interaction'         => array(
					'intersect' => false,
					'mode'      => 'index',
				),
				'plugins'             => array(
					'title'   => array(
						'display' => ! empty( $title ),
						'text'    => $title,
						'font'    => array(
							'size' => 16,
						),
					),
					'legend'  => array(
						'display'  => true,
						'position' => 'top',
					),
					'tooltip' => array(
						'enabled' => true,
					),
				),
				'scales'              => array(
					'x' => array(
						'display' => true,
						'title'   => array(
							'display' => true,
							'text'    => __( 'Time', 'wp-mcp-ai' ),
						),
						'ticks'   => array(
							'maxRotation'   => 45,
							'autoSkip'      => true,
							'maxTicksLimit' => 24,
						),
					),
					'y' => array(
						'display'     => true,
						'beginAtZero' => false,
					),
				),
			),
		);

		return $config;
	}

	/**
	 * Generate HTML with embedded Chart.js code.
	 *
	 * @param array $config Chart.js configuration.
	 * @param int   $width  Canvas width.
	 * @param int   $height Canvas height.
	 *
	 * @return string Complete HTML document.
	 */
	protected function generate_chart_html( array $config, $width, $height ) {
		$chart_id    = 'weather-chart-' . wp_generate_password( 8, false );
		$config_json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$chartjs_url = esc_url( self::CHARTJS_CDN_URL );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML file for chart export.
		$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weather Forecast Chart</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .chart-container {
            max-width: 100%;
            margin: 0 auto;
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .chart-header {
            text-align: center;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        .chart-header h2 {
            color: #333;
            font-size: 1.25rem;
            font-weight: 600;
        }
        .chart-header .subtitle {
            color: #666;
            font-size: 0.875rem;
            margin-top: 4px;
        }
        canvas {
            max-width: 100%;
            height: auto !important;
        }
        .chart-footer {
            text-align: center;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e0e0e0;
            color: #888;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="chart-container">
        <div class="chart-header">
            <h2>📊 Weather Data Visualization</h2>
            <p class="subtitle">Powered by Open-Meteo API</p>
        </div>
        <canvas id="{$chart_id}" width="{$width}" height="{$height}"></canvas>
        <div class="chart-footer">
            Generated by WP oOS Weather Forecast Tool
        </div>
    </div>
    <script src="{$chartjs_url}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('{$chart_id}').getContext('2d');
            const chartConfig = {$config_json};
            new Chart(ctx, chartConfig);
        });
    </script>
</body>
</html>
HTML;
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript

		return $html;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls to Open-Meteo.
			'requires-capability',  // Requires user capabilities.
			'network-dependent',    // Requires internet for API and Chart.js CDN.
		);
	}
}
