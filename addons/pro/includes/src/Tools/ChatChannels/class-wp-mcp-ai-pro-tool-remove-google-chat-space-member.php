<?php
/**
 * Tool that removes a member from a Google Chat space.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once __DIR__ . '/class-wp-mcp-ai-pro-google-service-account.php';

/**
 * Provides a tool for removing a member from a Google Chat space via the Google Chat API.
 */
class WP_MCP_AI_Pro_Tool_Remove_Google_Chat_Space_Member implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Google Chat requests.
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
		return 'remove_google_chat_space_member';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Remove Google Chat Space Member', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Google Chat API scope for bot operations.
	 */
	const CHAT_BOT_SCOPE = 'https://www.googleapis.com/auth/chat.bot';

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Removes a member from a Google Chat space using the Google Chat API v1.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'service_account_key' => array(
					'type'        => 'string',
					'description' => __( 'Google Service Account JSON key (contents of the downloaded .json key file). Used to generate an OAuth 2.0 access token automatically.', 'mcp-ai-wpoos-pro' ),
				),
				'access_token'  => array(
					'type'        => 'string',
					'description' => __( 'OAuth 2.0 access token for authentication. Use service_account_key instead for automatic token management.', 'mcp-ai-wpoos-pro' ),
				),
				'membership'    => array(
					'type'        => 'string',
					'description' => __( 'Membership resource name to remove (e.g., spaces/SPACE_ID/members/MEMBER_ID).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'membership' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_remove_google_chat_space_member_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to remove Google Chat space members.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$access_token = $this->resolve_access_token( $arguments, $context );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_access_token', __( 'A valid OAuth 2.0 access token or Service Account JSON key is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$membership = isset( $arguments['membership'] ) ? sanitize_text_field( $arguments['membership'] ) : '';

		if ( '' === $membership ) {
			return new WP_Error( 'wp_mcp_ai_missing_membership', __( 'A membership resource name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate membership resource name format.
		if ( ! preg_match( '/^spaces\/[a-zA-Z0-9_-]+\/members\/[a-zA-Z0-9_-]+$/', $membership ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_membership', __( 'Invalid membership format. Expected format: spaces/SPACE_ID/members/MEMBER_ID', 'mcp-ai-wpoos-pro' ) );
		}

		$endpoint = 'https://chat.googleapis.com/v1/' . $membership;

		WP_MCP_AI_Logger::log_event(
			'google_chat_remove_space_member_request',
			'Removing member from Google Chat space.',
			array(
				'endpoint'   => $endpoint,
				'membership' => $membership,
			)
		);

		$response = wp_remote_request(
			$endpoint,
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_remove_google_chat_space_member_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat remove member request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_google_chat_http_error',
				__( 'The Google Chat API request failed to send.', 'mcp-ai-wpoos-pro' ),
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
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Google Chat API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Google Chat remove member request was not successful.',
				array(
					'http_code'  => $code,
					'membership' => $membership,
					'error'      => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_google_chat_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'google_chat_remove_space_member_success',
			'Google Chat space member removed successfully.',
			array( 'membership' => $membership )
		);

		return $decoded;
	}

	/**
	 * Resolve an OAuth 2.0 access token from arguments.
	 *
	 * Prefers service_account_key (automatic token exchange) over a raw access_token.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return string|WP_Error Access token string or error.
	 */
	protected function resolve_access_token( array $arguments, array $context ) {
		$service_account_key = isset( $arguments['service_account_key'] ) ? trim( (string) $arguments['service_account_key'] ) : '';

		if ( '' !== $service_account_key ) {
			$timeout = (int) apply_filters( 'wp_mcp_ai_remove_google_chat_space_member_token_timeout', 15, $context, $arguments );
			return WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key( $service_account_key, self::CHAT_BOT_SCOPE, $timeout );
		}

		return isset( $arguments['access_token'] ) ? $this->sanitize_token( $arguments['access_token'] ) : '';
	}

	/**
	 * Sanitize an OAuth access token.
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
			'write',                // Removes a member from a Google Chat space.
			'external-api',         // Calls Google Chat API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
