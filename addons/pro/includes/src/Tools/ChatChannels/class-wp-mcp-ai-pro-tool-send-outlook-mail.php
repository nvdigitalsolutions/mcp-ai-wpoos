<?php
/**
 * Tool that sends a Microsoft Outlook email message.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending email messages via Microsoft Outlook using the Microsoft Graph API.
 */
class WP_MCP_AI_Pro_Tool_Send_Outlook_Mail implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'send_outlook_mail';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Outlook Mail', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends an email message via Microsoft Outlook using the Microsoft Graph API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'        => array(
					'type'        => 'string',
					'description' => __( 'Microsoft Graph API access token (Bearer token) used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'to_email'     => array(
					'type'        => 'string',
					'description' => __( 'Recipient email address.', 'mcp-ai-wpoos-pro' ),
				),
				'subject'      => array(
					'type'        => 'string',
					'description' => __( 'Email subject line.', 'mcp-ai-wpoos-pro' ),
				),
				'body'         => array(
					'type'        => 'string',
					'description' => __( 'Email body text.', 'mcp-ai-wpoos-pro' ),
				),
				'cc_email'     => array(
					'type'        => 'string',
					'description' => __( 'CC recipient email address (optional).', 'mcp-ai-wpoos-pro' ),
				),
				'content_type' => array(
					'type'        => 'string',
					'description' => __( 'Email body content type: text or html (default: text).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'text',
				),
			),
			'required'             => array( 'token', 'to_email', 'subject', 'body' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_outlook_mail_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Outlook mail.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_outlook_token', __( 'A valid Microsoft Graph API access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$to_email = isset( $arguments['to_email'] ) ? sanitize_text_field( $arguments['to_email'] ) : '';

		if ( '' === $to_email || ! is_email( $to_email ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_to_email', __( 'A valid recipient email address is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$subject = isset( $arguments['subject'] ) ? sanitize_text_field( $arguments['subject'] ) : '';

		if ( '' === $subject ) {
			return new WP_Error( 'wp_mcp_ai_missing_subject', __( 'An email subject is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$body_text = isset( $arguments['body'] ) ? $this->sanitize_message_text( $arguments['body'] ) : '';

		if ( '' === $body_text ) {
			return new WP_Error( 'wp_mcp_ai_missing_body', __( 'Email body content must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$content_type = isset( $arguments['content_type'] ) ? sanitize_text_field( $arguments['content_type'] ) : 'text';
		$content_type = ( 'html' === strtolower( $content_type ) ) ? 'HTML' : 'Text';

		$message = array(
			'subject'      => $subject,
			'body'         => array(
				'contentType' => $content_type,
				'content'     => $body_text,
			),
			'toRecipients' => array(
				array(
					'emailAddress' => array(
						'address' => $to_email,
					),
				),
			),
		);

		$cc_email = isset( $arguments['cc_email'] ) ? sanitize_text_field( $arguments['cc_email'] ) : '';

		if ( '' !== $cc_email && is_email( $cc_email ) ) {
			$message['ccRecipients'] = array(
				array(
					'emailAddress' => array(
						'address' => $cc_email,
					),
				),
			);
		}

		$payload = array(
			'message' => $message,
		);

		$encoded_body = wp_json_encode( $payload );

		if ( false === $encoded_body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Outlook mail request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		$endpoint = 'https://graph.microsoft.com/v1.0/me/sendMail';

		WP_MCP_AI_Logger::log_event(
			'outlook_send_mail_request',
			'Sending Outlook mail request.',
			array(
				'endpoint' => $endpoint,
				'to_email' => $to_email,
				'subject'  => $subject,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_outlook_mail_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $encoded_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Outlook mail request failed.', array( 'error' => $response->get_error_message() ) );

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

		if ( 202 !== $code ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Microsoft Graph API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Outlook mail request was not successful.',
				array(
					'http_code' => $code,
					'to_email'  => $to_email,
					'subject'   => $subject,
					'error'     => $message,
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
			'success' => true,
			'message' => __( 'Email sent successfully.', 'mcp-ai-wpoos-pro' ),
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
	 * Sanitize email body text.
	 *
	 * @param string $text Raw text input.
	 * @return string
	 */
	protected function sanitize_message_text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		return $text;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends Outlook mail.
			'external-api',         // Calls Microsoft Graph API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
