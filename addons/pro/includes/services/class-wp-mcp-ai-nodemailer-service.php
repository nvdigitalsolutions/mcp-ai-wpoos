<?php
/**
 * Nodemailer Service - Enhanced email sending via Node.js nodemailer package.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service class for sending emails using Nodemailer.
 *
 * This service provides advanced email sending capabilities including:
 * - SMTP authentication (plain, OAuth2)
 * - HTML and text email support
 * - Attachments
 * - Bulk sending with connection pooling
 * - Template support
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Nodemailer_Service {

	/**
	 * Check if nodemailer is available.
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available() {
		$node_modules = WP_MCP_AI_PRO_PATH . 'node_modules/nodemailer';
		return file_exists( $node_modules );
	}

	/**
	 * Send an email using nodemailer.
	 *
	 * @param array $email_data Email data including to, subject, html, text, attachments.
	 * @return array|WP_Error Result with success status or error.
	 */
	public function send_email( $email_data ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'nodemailer_unavailable',
				__( 'Nodemailer is not available. Please ensure Node.js and nodemailer package are installed.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required fields.
		if ( empty( $email_data['to'] ) ) {
			return new WP_Error(
				'invalid_recipient',
				__( 'Email recipient is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $email_data['subject'] ) ) {
			return new WP_Error(
				'invalid_subject',
				__( 'Email subject is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $email_data['html'] ) && empty( $email_data['text'] ) ) {
			return new WP_Error(
				'invalid_content',
				__( 'Email content (html or text) is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare email parameters.
		$params = array(
			'from'    => isset( $email_data['from'] ) ? sanitize_email( $email_data['from'] ) : get_option( 'admin_email' ),
			'to'      => sanitize_email( $email_data['to'] ),
			'subject' => sanitize_text_field( $email_data['subject'] ),
		);

		if ( isset( $email_data['html'] ) ) {
			$params['html'] = $email_data['html'];
		}

		if ( isset( $email_data['text'] ) ) {
			$params['text'] = $email_data['text'];
		}

		if ( isset( $email_data['attachments'] ) && is_array( $email_data['attachments'] ) ) {
			$params['attachments'] = $email_data['attachments'];
		}

		// Allow custom Node.js implementation via filter.
		$result = apply_filters( 'wp_mcp_ai_nodemailer_send_email', false, $params );

		if ( false === $result ) {
			return new WP_Error(
				'nodemailer_not_implemented',
				__( 'Nodemailer email sending requires Node.js integration. Please implement the wp_mcp_ai_nodemailer_send_email filter.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $result;
	}

	/**
	 * Send bulk emails using nodemailer.
	 *
	 * @param array $recipients Array of email addresses.
	 * @param array $email_data Email data (subject, html, text, attachments).
	 * @return array Results for each recipient.
	 */
	public function send_bulk( $recipients, $email_data ) {
		if ( ! is_array( $recipients ) || empty( $recipients ) ) {
			return new WP_Error(
				'invalid_recipients',
				__( 'Recipients must be a non-empty array.', 'mcp-ai-wpoos-pro' )
			);
		}

		$results = array();

		foreach ( $recipients as $recipient ) {
			$email_data['to'] = $recipient;
			$result           = $this->send_email( $email_data );

			$results[] = array(
				'recipient' => $recipient,
				'success'   => ! is_wp_error( $result ),
				'result'    => $result,
			);
		}

		return $results;
	}

	/**
	 * Verify SMTP connection configuration.
	 *
	 * @param array $smtp_config SMTP configuration (host, port, auth, etc.).
	 * @return array|WP_Error Verification result or error.
	 */
	public function verify_connection( $smtp_config ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'nodemailer_unavailable',
				__( 'Nodemailer is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Allow custom Node.js implementation via filter.
		$result = apply_filters( 'wp_mcp_ai_nodemailer_verify_connection', false, $smtp_config );

		if ( false === $result ) {
			return new WP_Error(
				'nodemailer_not_implemented',
				__( 'Nodemailer connection verification requires Node.js integration. Please implement the wp_mcp_ai_nodemailer_verify_connection filter.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $result;
	}

	/**
	 * Get SMTP configuration from WordPress settings.
	 *
	 * @return array SMTP configuration.
	 */
	public function get_smtp_config() {
		$config = array(
			'host' => get_option( 'wp_mcp_ai_smtp_host', 'localhost' ),
			'port' => get_option( 'wp_mcp_ai_smtp_port', 587 ),
			'auth' => array(
				'user' => get_option( 'wp_mcp_ai_smtp_user', '' ),
				'pass' => get_option( 'wp_mcp_ai_smtp_pass', '' ),
			),
		);

		// Allow filtering SMTP config.
		return apply_filters( 'wp_mcp_ai_nodemailer_smtp_config', $config );
	}

	/**
	 * Render email template with variables.
	 *
	 * @param string $template Template content with {{variables}}.
	 * @param array  $variables Variables to replace.
	 * @return string Rendered template.
	 */
	public function render_template( $template, $variables ) {
		if ( ! is_array( $variables ) ) {
			return $template;
		}

		foreach ( $variables as $key => $value ) {
			$template = str_replace( '{{' . $key . '}}', $value, $template );
		}

		return $template;
	}
}
