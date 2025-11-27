<?php
/**
 * Tool that retrieves Google Business Profile insights.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for querying Google Business Profile insights.
 */
class WP_MCP_AI_Tool_Get_Google_Business_Insights implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Google Business API calls.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Base endpoint for Google Business insights requests.
	 */
	const INSIGHTS_ENDPOINT = 'https://mybusiness.googleapis.com/v4/';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_google_business_insights';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Retrieve Google Business Insights', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Fetches performance metrics for a Google Business Profile location.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token' => array(
					'type'        => 'string',
					'description' => __( 'OAuth access token with Business Profile insights scope.', 'wp-mcp-ai' ),
				),
				'location'     => array(
					'type'        => 'string',
					'description' => __( 'The location resource name, e.g. accounts/123/locations/456.', 'wp-mcp-ai' ),
				),
				'metrics'      => array(
					'type'        => array( 'array', 'string' ),
					'description' => __( 'One or more metric identifiers such as BUSINESS_IMPRESSIONS_SEARCH.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'start_time'   => array(
					'type'        => 'string',
					'description' => __( 'Optional RFC3339 start time for the requested range.', 'wp-mcp-ai' ),
				),
				'end_time'     => array(
					'type'        => 'string',
					'description' => __( 'Optional RFC3339 end time for the requested range.', 'wp-mcp-ai' ),
				),
				'time_zone'    => array(
					'type'        => 'string',
					'description' => __( 'Optional IANA time zone used for the request.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'access_token', 'location', 'metrics' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_get_google_business_insights_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_google_business_insights_forbidden', __( 'You do not have permission to request Google Business insights.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_google_business_insights_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_google_business_insights_missing_token', __( 'An OAuth access token is required.', 'wp-mcp-ai' ) );
		}

		$location = isset( $arguments['location'] ) ? $this->sanitize_location_name( $arguments['location'] ) : '';

		if ( '' === $location ) {
			return new WP_Error( 'wp_mcp_ai_google_business_insights_missing_location', __( 'A valid location resource name is required.', 'wp-mcp-ai' ) );
		}

		$metrics = $this->normalise_metrics( isset( $arguments['metrics'] ) ? $arguments['metrics'] : array() );

		if ( empty( $metrics ) ) {
			return new WP_Error( 'wp_mcp_ai_google_business_insights_missing_metrics', __( 'At least one insight metric must be requested.', 'wp-mcp-ai' ) );
		}

		$start_time = isset( $arguments['start_time'] ) ? $this->sanitize_time( $arguments['start_time'] ) : '';
		$end_time   = isset( $arguments['end_time'] ) ? $this->sanitize_time( $arguments['end_time'] ) : '';
		$time_zone  = isset( $arguments['time_zone'] ) ? $this->sanitize_time_zone( $arguments['time_zone'] ) : '';

		$endpoint = self::INSIGHTS_ENDPOINT . $location . '/insights:fetch';

		$payload = array(
			'locationNames' => array( $location ),
			'basicRequest'  => array(
				'metricRequests' => array(),
			),
		);

		foreach ( $metrics as $metric ) {
			$payload['basicRequest']['metricRequests'][] = array(
				'metric' => $metric,
			);
		}

		$time_range = array();

		if ( '' !== $start_time ) {
			$time_range['startTime'] = $start_time;
		}

		if ( '' !== $end_time ) {
			$time_range['endTime'] = $end_time;
		}

		if ( ! empty( $time_range ) ) {
			$payload['basicRequest']['timeRange'] = $time_range;
		}

		if ( '' !== $time_zone ) {
			$payload['basicRequest']['timeZone'] = $time_zone;
		}

		WP_MCP_AI_Logger::log_event(
			'google_business_insights_request',
			'Sending Google Business insights request.',
			array(
				'location' => $location,
				'metrics'  => $metrics,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_get_google_business_insights_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Business insights request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_google_business_insights_http_error',
				__( 'The Google Business insights request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded || ! is_array( $decoded ) ) {
			$decoded = array();
		}

		if ( 200 !== $code ) {
			$message = __( 'Google Business insights API returned an error.', 'wp-mcp-ai' );

			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'Google Business insights request returned an error.',
				array(
					'http_code' => $code,
					'location'  => $location,
					'api_error' => isset( $decoded['error'] ) ? $decoded['error'] : array(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_google_business_insights_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'location'        => $location,
			'locationMetrics' => isset( $decoded['locationMetrics'] ) ? $decoded['locationMetrics'] : array(),
		);
	}

	/**
	 * Normalise the requested metric list.
	 *
	 * @param string|array $metrics Raw metrics input.
	 * @return array
	 */
	protected function normalise_metrics( $metrics ) {
		if ( is_string( $metrics ) ) {
			$metrics = explode( ',', $metrics );
		}

		if ( ! is_array( $metrics ) ) {
			return array();
		}

		$sanitised = array();

		foreach ( $metrics as $metric ) {
			if ( ! is_string( $metric ) ) {
				continue;
			}

			$metric = strtoupper( trim( $metric ) );
			$metric = preg_replace( '/[^A-Z0-9_]/', '', $metric );

			if ( '' === $metric ) {
				continue;
			}

			$sanitised[] = $metric;
		}

		return array_values( array_unique( $sanitised ) );
	}

	/**
	 * Sanitize an OAuth access token.
	 *
	 * @param string $token Raw token value.
	 * @return string
	 */
	protected function sanitize_access_token( $token ) {
		if ( ! is_string( $token ) ) {
			return '';
		}

		$token = trim( $token );

		if ( '' === $token ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9:_\-|\.]/', '', $token );
	}

	/**
	 * Sanitize the Google Business location resource name.
	 *
	 * @param string $location Raw location value.
	 * @return string
	 */
	protected function sanitize_location_name( $location ) {
		if ( ! is_string( $location ) ) {
			return '';
		}

		$location = trim( $location );

		if ( '' === $location ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9_\/-]/', '', $location );
	}

	/**
	 * Sanitize an RFC3339 timestamp value.
	 *
	 * @param string $value Raw timestamp.
	 * @return string
	 */
	protected function sanitize_time( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$value = preg_replace( '/[^0-9Tt:\-+Zz\.]/', '', $value );

		return $value;
	}

	/**
	 * Sanitize an IANA time zone identifier.
	 *
	 * @param string $time_zone Raw time zone value.
	 * @return string
	 */
	protected function sanitize_time_zone( $time_zone ) {
		if ( ! is_string( $time_zone ) ) {
			return '';
		}

		$time_zone = trim( $time_zone );

		if ( '' === $time_zone ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9_\/]/', '', $time_zone );
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
