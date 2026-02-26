<?php
/**
 * Tool that retrieves Twitter/X Direct Message conversations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving Twitter/X DM conversations via API v2.
 *
 * Uses GET /2/dm_events which requires OAuth 1.0a user context (read DMs
 * requires dm.read scope with OAuth 2.0 PKCE or OAuth 1.0a).
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/direct-messages/lookup/api-reference/get-dm_events
 */
class WP_MCP_AI_Pro_Tool_Get_Twitter_DMs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for Twitter API requests.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true — no external plugin dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_twitter_dms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Twitter/X DMs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves recent Direct Message events from Twitter/X API v2 for the authenticated user.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'bearer_token'        => array(
					'type'        => 'string',
					'description' => __( 'OAuth 2.0 Bearer Token for authentication. Provides read access to DMs.', 'mcp-ai-wpoos-pro' ),
				),
				'max_results'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of DM events to return (1-100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 25,
				),
				'dm_conversation_id'  => array(
					'type'        => 'string',
					'description' => __( 'Optional DM conversation ID to filter results to a specific thread.', 'mcp-ai-wpoos-pro' ),
				),
				'pagination_token'    => array(
					'type'        => 'string',
					'description' => __( 'Pagination token returned by a previous request to retrieve the next page of results.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'bearer_token' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_twitter_dms_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve Twitter DMs.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$bearer_token = isset( $arguments['bearer_token'] ) ? trim( (string) $arguments['bearer_token'] ) : '';

		if ( '' === $bearer_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_bearer_token', __( 'A valid Bearer Token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$max_results        = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 25;
		$max_results        = max( 1, min( 100, $max_results ) );
		$dm_conversation_id = isset( $arguments['dm_conversation_id'] ) ? sanitize_text_field( $arguments['dm_conversation_id'] ) : '';
		$pagination_token   = isset( $arguments['pagination_token'] ) ? sanitize_text_field( $arguments['pagination_token'] ) : '';

		// Build query parameters.
		$query_args = array(
			'max_results' => $max_results,
			'event_types' => 'MessageCreate',
		);

		if ( '' !== $pagination_token ) {
			$query_args['pagination_token'] = $pagination_token;
		}

		// Choose endpoint: conversation-scoped or global DM events.
		if ( '' !== $dm_conversation_id ) {
			$endpoint = 'https://api.twitter.com/2/dm_conversations/' . rawurlencode( $dm_conversation_id ) . '/dm_events';
		} else {
			$endpoint = 'https://api.twitter.com/2/dm_events';
		}

		$url = add_query_arg( $query_args, $endpoint );

		WP_MCP_AI_Logger::log_event(
			'twitter_get_dms_request',
			'Retrieving Twitter DM events.',
			array(
				'endpoint'    => $endpoint,
				'max_results' => $max_results,
			)
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $bearer_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_twitter_dms_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Twitter get DMs request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_twitter_http_error',
				__( 'The Twitter API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded      = json_decode( $response_body, true );

		if ( $http_code < 200 || $http_code >= 300 ) {
			$error_detail = is_array( $decoded ) && isset( $decoded['detail'] ) ? $decoded['detail'] : $response_body;

			WP_MCP_AI_Logger::log_error(
				'Twitter get DMs request returned an error.',
				array(
					'http_code'    => $http_code,
					'error_detail' => $error_detail,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_twitter_api_error',
				esc_html(
					sprintf(
						/* translators: 1: HTTP status code, 2: API error detail */
						__( 'Twitter API returned HTTP %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
						$http_code,
						$error_detail
					)
				),
				array( 'http_code' => $http_code, 'response' => $decoded )
			);
		}

		return is_array( $decoded ) ? $decoded : array( 'raw' => $response_body );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                 // Pro tier tool.
			'read-only',           // Only reads DM data.
			'external-api',        // Calls Twitter API v2.
			'network-dependent',   // Requires internet connectivity.
			'requires-capability', // Requires user capabilities.
		);
	}
}
