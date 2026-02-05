<?php
/**
 * Tool that retrieves Microsoft Teams channels.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for listing Microsoft Teams channels via the Microsoft Graph API.
 */
class WP_MCP_AI_Pro_Tool_Get_Teams_Channels implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Microsoft Graph API requests.
	 */
	const DEFAULT_TIMEOUT = 20;

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
		return 'get_teams_channels';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Teams Channels', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a list of channels from a Microsoft Teams team using the Microsoft Graph API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'   => array(
					'type'        => 'string',
					'description' => __( 'Microsoft Graph API access token (Bearer token) used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'team_id' => array(
					'type'        => 'string',
					'description' => __( 'Microsoft Teams team ID to retrieve channels from.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'token', 'team_id' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_teams_channels_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve Teams channels.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_teams_token', __( 'A valid Microsoft Graph API access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$team_id = isset( $arguments['team_id'] ) ? sanitize_text_field( $arguments['team_id'] ) : '';

		if ( '' === $team_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_team_id', __( 'A team ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$endpoint = 'https://graph.microsoft.com/v1.0/teams/' . $team_id . '/channels';

		WP_MCP_AI_Logger::log_event(
			'teams_get_channels_request',
			'Retrieving Teams channels.',
			array(
				'endpoint' => $endpoint,
				'team_id'  => $team_id,
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_teams_channels_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams get channels request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_teams_http_error',
				__( 'The Microsoft Graph API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Microsoft Graph API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Teams get channels request was not successful.',
				array(
					'http_code' => $code,
					'team_id'   => $team_id,
					'error'     => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_teams_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'channels' => isset( $decoded['value'] ) ? $decoded['value'] : array(),
		);
	}

	/**
	 * Sanitize a Microsoft Graph API access token.
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
			'read-only',            // Retrieves Teams channels.
			'external-api',         // Calls Microsoft Graph API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
