<?php
/**
 * Tool that retrieves TikTok account insights.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for querying TikTok Open API insight endpoints.
 */
class WP_MCP_AI_Tool_Get_Tiktok_Insights implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for TikTok API calls.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Base endpoint for TikTok user insights.
	 */
	const INSIGHTS_ENDPOINT = 'https://open-api.tiktok.com/insights/user/info/';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_tiktok_insights';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Retrieve TikTok Insights', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Fetches TikTok account performance metrics using the TikTok Open API.', 'wp-mcp-ai' );
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
					'description' => __( 'TikTok Open API access token with insights permissions.', 'wp-mcp-ai' ),
				),
				'open_id'      => array(
					'type'        => 'string',
					'description' => __( 'The TikTok Open ID representing the target account.', 'wp-mcp-ai' ),
				),
				'metrics'      => array(
					'type'        => array( 'array', 'string' ),
					'description' => __( 'One or more metric names to request. Comma separated strings are accepted.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'start_time'   => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO 8601 start boundary for the report.', 'wp-mcp-ai' ),
				),
				'end_time'     => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO 8601 end boundary for the report.', 'wp-mcp-ai' ),
				),
				'granularity'  => array(
					'type'        => 'string',
					'description' => __( 'Optional aggregation granularity (for example, day or hour).', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'access_token', 'open_id', 'metrics' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_get_tiktok_insights_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_tiktok_insights_forbidden', __( 'You do not have permission to request TikTok insights.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_tiktok_insights_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_tiktok_insights_missing_token', __( 'A TikTok access token is required.', 'wp-mcp-ai' ) );
		}

		$open_id = isset( $arguments['open_id'] ) ? $this->sanitize_open_id( $arguments['open_id'] ) : '';

		if ( '' === $open_id ) {
			return new WP_Error( 'wp_mcp_ai_tiktok_insights_missing_open_id', __( 'A valid Open ID is required.', 'wp-mcp-ai' ) );
		}

		$metrics = $this->normalise_metrics( isset( $arguments['metrics'] ) ? $arguments['metrics'] : array() );

		if ( empty( $metrics ) ) {
			return new WP_Error( 'wp_mcp_ai_tiktok_insights_missing_metrics', __( 'At least one insight metric must be requested.', 'wp-mcp-ai' ) );
		}

		$start_time  = isset( $arguments['start_time'] ) ? $this->sanitize_time( $arguments['start_time'] ) : '';
		$end_time    = isset( $arguments['end_time'] ) ? $this->sanitize_time( $arguments['end_time'] ) : '';
		$granularity = isset( $arguments['granularity'] ) ? $this->sanitize_granularity( $arguments['granularity'] ) : '';

		$payload = array(
			'access_token' => $access_token,
			'open_id'      => $open_id,
			'metrics'      => $metrics,
		);

		if ( '' !== $start_time ) {
			$payload['start_time'] = $start_time;
		}

		if ( '' !== $end_time ) {
			$payload['end_time'] = $end_time;
		}

		if ( '' !== $granularity ) {
			$payload['granularity'] = $granularity;
		}

		WP_MCP_AI_Logger::log_event(
			'tiktok_insights_request',
			'Sending TikTok insights request.',
			array(
				'open_id' => $open_id,
				'metrics' => $metrics,
			)
		);

		$response = wp_remote_post(
			self::INSIGHTS_ENDPOINT,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_get_tiktok_insights_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'TikTok insights request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_tiktok_insights_http_error',
				__( 'The TikTok insights request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded || ! is_array( $decoded ) ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['data'] ) ) {
			$message = __( 'TikTok insights API returned an error.', 'wp-mcp-ai' );

			if ( ! empty( $decoded['message'] ) ) {
				$message = $decoded['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'TikTok insights request returned an error.',
				array(
					'http_code' => $code,
					'open_id'   => $open_id,
					'api_error' => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_tiktok_insights_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'open_id' => $open_id,
			'metrics' => $decoded['data'],
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

			$metric = sanitize_key( $metric );

			if ( '' === $metric ) {
				continue;
			}

			$sanitised[] = $metric;
		}

		return array_values( array_unique( $sanitised ) );
	}

	/**
	 * Sanitize a TikTok access token.
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
	 * Sanitize the TikTok Open ID.
	 *
	 * @param string $open_id Raw Open ID value.
	 * @return string
	 */
	protected function sanitize_open_id( $open_id ) {
		if ( ! is_string( $open_id ) ) {
			return '';
		}

		$open_id = trim( $open_id );

		if ( '' === $open_id ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9._-]/', '', $open_id );
	}

	/**
	 * Sanitize a time boundary value.
	 *
	 * @param string $value Raw value.
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

		$value = preg_replace( '/[^0-9Tt:\-+Zz]/', '', $value );

		return $value;
	}

	/**
	 * Sanitize the requested granularity.
	 *
	 * @param string $granularity Raw granularity value.
	 * @return string
	 */
	protected function sanitize_granularity( $granularity ) {
		if ( ! is_string( $granularity ) ) {
			return '';
		}

		$granularity = strtoupper( trim( $granularity ) );
		$granularity = preg_replace( '/[^A-Z_]/', '', $granularity );

		return $granularity;
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
