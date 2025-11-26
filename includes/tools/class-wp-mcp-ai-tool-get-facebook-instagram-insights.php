<?php
/**
 * Tool that retrieves insights for Facebook Pages and Instagram business accounts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for querying Meta Graph API insights endpoints.
 */
class WP_MCP_AI_Tool_Get_Facebook_Instagram_Insights implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Graph API version used for requests.
	 */
	const GRAPH_VERSION = 'v18.0';

	/**
	 * Default timeout for Graph API calls.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_facebook_instagram_insights';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Retrieve Meta Social Insights', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Fetches insights for Facebook Pages or Instagram business accounts using the Meta Graph API.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'platform'     => array(
					'type'        => 'string',
					'enum'        => array( 'facebook', 'instagram' ),
					'description' => __( 'Target platform for the insights request.', 'wp-mcp-ai' ),
				),
				'access_token' => array(
					'type'        => 'string',
					'description' => __( 'Meta Graph API access token with insights permissions.', 'wp-mcp-ai' ),
				),
				'target_id'    => array(
					'type'        => 'string',
					'description' => __( 'Facebook Page ID or Instagram business account ID.', 'wp-mcp-ai' ),
				),
				'metrics'      => array(
					'type'        => array( 'array', 'string' ),
					'description' => __( 'One or more insight metric names. Comma separated strings are accepted.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'period'       => array(
					'type'        => 'string',
					'description' => __( 'Optional aggregation period such as day, week or month.', 'wp-mcp-ai' ),
				),
				'since'        => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO 8601 or Unix timestamp start boundary.', 'wp-mcp-ai' ),
				),
				'until'        => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO 8601 or Unix timestamp end boundary.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'platform', 'access_token', 'target_id', 'metrics' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_get_meta_insights_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_meta_insights_forbidden', __( 'You do not have permission to request social insights.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_meta_insights_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$platform = isset( $arguments['platform'] ) ? sanitize_key( $arguments['platform'] ) : '';

		if ( ! in_array( $platform, array( 'facebook', 'instagram' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_meta_insights_invalid_platform', __( 'A valid target platform must be provided.', 'wp-mcp-ai' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_meta_insights_missing_token', __( 'An access token is required to request insights.', 'wp-mcp-ai' ) );
		}

		$target_id = isset( $arguments['target_id'] ) ? $this->sanitize_target_id( $arguments['target_id'] ) : '';

		if ( '' === $target_id ) {
			return new WP_Error( 'wp_mcp_ai_meta_insights_missing_target', __( 'A valid target identifier is required.', 'wp-mcp-ai' ) );
		}

		$metrics = $this->normalise_metrics( isset( $arguments['metrics'] ) ? $arguments['metrics'] : array() );

		if ( empty( $metrics ) ) {
			return new WP_Error( 'wp_mcp_ai_meta_insights_missing_metrics', __( 'At least one insight metric must be requested.', 'wp-mcp-ai' ) );
		}

		$period = isset( $arguments['period'] ) ? $this->sanitize_period( $arguments['period'] ) : '';
		$since  = isset( $arguments['since'] ) ? $this->sanitize_time_boundary( $arguments['since'] ) : '';
		$until  = isset( $arguments['until'] ) ? $this->sanitize_time_boundary( $arguments['until'] ) : '';

		$endpoint = sprintf( 'https://graph.facebook.com/%1$s/%2$s/insights', self::GRAPH_VERSION, rawurlencode( $target_id ) );

		$query_args = array(
			'access_token' => $access_token,
			'metric'       => implode( ',', $metrics ),
		);

		if ( '' !== $period ) {
			$query_args['period'] = $period;
		}

		if ( '' !== $since ) {
			$query_args['since'] = $since;
		}

		if ( '' !== $until ) {
			$query_args['until'] = $until;
		}

		$request_url = add_query_arg( $query_args, $endpoint );

		WP_MCP_AI_Logger::log_event(
			'meta_social_insights_request',
			'Sending Meta insights request.',
			array(
				'platform'  => $platform,
				'target_id' => $target_id,
				'metrics'   => $metrics,
			)
		);

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_get_meta_insights_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Meta insights request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_meta_insights_http_error',
				__( 'The Meta insights request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded || ! is_array( $decoded ) ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			$message = __( 'Meta insights API returned an error.', 'wp-mcp-ai' );

			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'Meta insights request returned an error.',
				array(
					'http_code' => $code,
					'target_id' => $target_id,
					'api_error' => isset( $decoded['error'] ) ? $decoded['error'] : array(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_meta_insights_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'platform'  => $platform,
			'target_id' => $target_id,
			'metrics'   => $decoded['data'],
			'paging'    => isset( $decoded['paging'] ) ? $decoded['paging'] : array(),
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
	 * Sanitize the Graph API access token.
	 *
	 * @param string $token Raw access token.
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
	 * Sanitize a Facebook Page or Instagram business account identifier.
	 *
	 * @param string $target_id Raw identifier value.
	 * @return string
	 */
	protected function sanitize_target_id( $target_id ) {
		if ( ! is_string( $target_id ) ) {
			return '';
		}

		$target_id = trim( $target_id );

		if ( '' === $target_id ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9._-]/', '', $target_id );
	}

	/**
	 * Sanitize the insights aggregation period.
	 *
	 * @param string $period Raw period value.
	 * @return string
	 */
	protected function sanitize_period( $period ) {
		if ( ! is_string( $period ) ) {
			return '';
		}

		$period = sanitize_key( $period );

		return $period;
	}

	/**
	 * Sanitize a time boundary value (ISO 8601 or timestamp).
	 *
	 * @param string $value Raw time boundary.
	 * @return string
	 */
	protected function sanitize_time_boundary( $value ) {
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
