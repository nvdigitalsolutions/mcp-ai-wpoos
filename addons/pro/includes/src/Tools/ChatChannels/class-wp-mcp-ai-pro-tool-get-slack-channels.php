<?php
/**
 * Tool that lists available Slack channels.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for listing Slack channels via the Web API.
 */
class WP_MCP_AI_Pro_Tool_Get_Slack_Channels implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Slack requests.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_slack_channels';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Slack Channels', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists available Slack channels using the Slack Web API. Supports filtering by type and pagination with cursors.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'  => array(
					'type'        => 'string',
					'description' => __( 'Slack bot token (xoxb-) or user token (xoxp-) used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'types'  => array(
					'type'        => 'string',
					'description' => __( 'Comma-separated list of channel types: public_channel, private_channel, mpim, im. Defaults to public_channel.', 'mcp-ai-wpoos-pro' ),
				),
				'cursor' => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor from a previous request to fetch the next page of results.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of channels to return (1-1000). Defaults to 100.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'token' ),
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

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_get_slack_channels_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list Slack channels.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_slack_token', __( 'A valid Slack token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$endpoint = 'https://slack.com/api/conversations.list';

		$query_params = array();

		if ( isset( $arguments['types'] ) && is_string( $arguments['types'] ) ) {
			$query_params['types'] = sanitize_text_field( $arguments['types'] );
		}

		if ( isset( $arguments['cursor'] ) && is_string( $arguments['cursor'] ) ) {
			$query_params['cursor'] = sanitize_text_field( $arguments['cursor'] );
		}

		if ( isset( $arguments['limit'] ) && is_numeric( $arguments['limit'] ) ) {
			$limit = absint( $arguments['limit'] );
			if ( $limit > 0 && $limit <= 1000 ) {
				$query_params['limit'] = $limit;
			}
		}

		if ( ! empty( $query_params ) ) {
			$endpoint .= '?' . http_build_query( $query_params );
		}

		WP_MCP_AI_Logger::log_event(
			'slack_list_channels_request',
			'Sending Slack conversations.list request.',
			array(
				'endpoint' => 'https://slack.com/api/conversations.list',
				'types'    => isset( $query_params['types'] ) ? $query_params['types'] : 'public_channel',
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_slack_channels_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack conversations.list request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_slack_http_error',
				__( 'The Slack API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['ok'] ) ) {
			$message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Slack API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Slack conversations.list request was not successful.',
				array(
					'http_code' => $code,
					'error'     => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_slack_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Sanitize a Slack token.
	 *
	 * @param string $token Raw token value.
	 * @return string
	 */
	protected function sanitize_token( $token ) {
		if ( ! is_string( $token ) && ! is_numeric( $token ) ) {
			return '';
		}

		$token = trim( (string) $token );

		if ( '' === $token ) {
			return '';
		}

		return $token;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read',                 // Reads Slack channels.
			'external-api',         // Calls Slack Web API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
