<?php
/**
 * Tool that retrieves Microsoft Outlook email messages.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving email messages from a Microsoft Outlook mailbox via the Microsoft Graph API.
 */
class WP_MCP_AI_Pro_Tool_Get_Outlook_Messages implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'get_outlook_messages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Outlook Messages', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves email messages from a Microsoft Outlook mailbox using the Microsoft Graph API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'     => array(
					'type'        => 'string',
					'description' => __( 'Microsoft Graph API access token (Bearer token) used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'folder_id' => array(
					'type'        => 'string',
					'description' => __( 'Mail folder ID or well-known name such as inbox, sentitems, or drafts (default: inbox).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'inbox',
				),
				'top'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of messages to retrieve (1-50, default: 25).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 25,
				),
				'filter'    => array(
					'type'        => 'string',
					'description' => __( 'OData filter expression to narrow results (optional).', 'mcp-ai-wpoos-pro' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_outlook_messages_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve Outlook messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_outlook_token', __( 'A valid Microsoft Graph API access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$folder_id = isset( $arguments['folder_id'] ) ? sanitize_text_field( $arguments['folder_id'] ) : 'inbox';

		if ( '' === $folder_id ) {
			$folder_id = 'inbox';
		}

		$top = isset( $arguments['top'] ) ? absint( $arguments['top'] ) : 25;

		if ( $top < 1 ) {
			$top = 1;
		}

		if ( $top > 50 ) {
			$top = 50;
		}

		$endpoint = 'https://graph.microsoft.com/v1.0/me/mailFolders/' . rawurlencode( $folder_id ) . '/messages?$top=' . $top;

		$filter = isset( $arguments['filter'] ) ? sanitize_text_field( $arguments['filter'] ) : '';

		if ( '' !== $filter ) {
			$endpoint .= '&$filter=' . rawurlencode( $filter );
		}

		WP_MCP_AI_Logger::log_event(
			'outlook_get_messages_request',
			'Retrieving Outlook messages.',
			array(
				'endpoint'  => $endpoint,
				'folder_id' => $folder_id,
				'top'       => $top,
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_outlook_messages_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Outlook get messages request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_outlook_http_error',
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
				'Outlook get messages request was not successful.',
				array(
					'http_code'  => $code,
					'folder_id'  => $folder_id,
					'error'      => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_outlook_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'messages' => isset( $decoded['value'] ) ? $decoded['value'] : array(),
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
			'read-only',            // Retrieves Outlook messages.
			'external-api',         // Calls Microsoft Graph API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
