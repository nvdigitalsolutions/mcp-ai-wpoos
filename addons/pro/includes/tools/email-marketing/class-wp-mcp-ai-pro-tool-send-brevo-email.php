<?php
/**
 * Tool that sends transactional emails through the Brevo (Sendinblue) API.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending transactional emails via the Brevo API.
 *
 * Brevo (formerly Sendinblue) API docs: https://developers.brevo.com/docs/getting-started
 */
class WP_MCP_AI_Pro_Tool_Send_Brevo_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const API_ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_brevo_email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Brevo Email', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a transactional email using the configured Brevo (formerly Sendinblue) API credentials.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject'        => array(
					'type'        => 'string',
					'description' => __( 'Subject line for the outgoing message.', 'mcp-ai-wpoos-pro' ),
				),
				'text'           => array(
					'type'        => 'string',
					'description' => __( 'Optional plain-text body for the message.', 'mcp-ai-wpoos-pro' ),
				),
				'html'           => array(
					'type'        => 'string',
					'description' => __( 'Optional HTML body for the message.', 'mcp-ai-wpoos-pro' ),
				),
				'to'             => array(
					'type'        => 'array',
					'description' => __( 'List of primary recipients. Each entry may be an email string or an object with email and name fields.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'anyOf' => array(
							array(
								'type' => 'string',
							),
							array(
								'type'                 => 'object',
								'properties'           => array(
									'email' => array(
										'type' => 'string',
									),
									'name'  => array(
										'type' => 'string',
									),
								),
								'required'             => array( 'email' ),
								'additionalProperties' => true,
							),
						),
					),
					'minItems'    => 1,
				),
				'cc'             => array(
					'type'        => 'array',
					'description' => __( 'Optional CC recipients formatted like the "to" field.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'anyOf' => array(
							array(
								'type' => 'string',
							),
							array(
								'type'                 => 'object',
								'properties'           => array(
									'email' => array(
										'type' => 'string',
									),
									'name'  => array(
										'type' => 'string',
									),
								),
								'required'             => array( 'email' ),
								'additionalProperties' => true,
							),
						),
					),
				),
				'bcc'            => array(
					'type'        => 'array',
					'description' => __( 'Optional BCC recipients formatted like the "to" field.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'anyOf' => array(
							array(
								'type' => 'string',
							),
							array(
								'type'                 => 'object',
								'properties'           => array(
									'email' => array(
										'type' => 'string',
									),
									'name'  => array(
										'type' => 'string',
									),
								),
								'required'             => array( 'email' ),
								'additionalProperties' => true,
							),
						),
					),
				),
				'from_email'     => array(
					'type'        => 'string',
					'description' => __( 'Optional sender email override. Defaults to the Brevo From Email in settings.', 'mcp-ai-wpoos-pro' ),
				),
				'from_name'      => array(
					'type'        => 'string',
					'description' => __( 'Optional sender name override.', 'mcp-ai-wpoos-pro' ),
				),
				'reply_to_email' => array(
					'type'        => 'string',
					'description' => __( 'Optional reply-to email address.', 'mcp-ai-wpoos-pro' ),
				),
				'reply_to_name'  => array(
					'type'        => 'string',
					'description' => __( 'Optional reply-to display name.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'           => array(
					'type'        => 'array',
					'description' => __( 'Optional tags for categorising the email in Brevo.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 10,
				),
			),
			'required'             => array( 'subject', 'to' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_brevo_email_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Brevo emails.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$api_key = isset( $settings['brevo_api_key'] ) ? trim( $settings['brevo_api_key'] ) : '';

		if ( '' === $api_key ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_missing_credentials',
				__( 'Brevo API key has not been configured.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_brevo_credentials' => __( 'Add a Brevo API key in the NV oOS settings.', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		$subject = isset( $arguments['subject'] ) ? sanitize_text_field( $arguments['subject'] ) : '';

		if ( '' === $subject ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_subject', __( 'An email subject must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$to = isset( $arguments['to'] ) ? $this->normalise_address_list( $arguments['to'] ) : array();

		if ( empty( $to ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_recipients', __( 'At least one valid recipient must be supplied.', 'mcp-ai-wpoos-pro' ) );
		}

		$cc  = isset( $arguments['cc'] ) ? $this->normalise_address_list( $arguments['cc'] ) : array();
		$bcc = isset( $arguments['bcc'] ) ? $this->normalise_address_list( $arguments['bcc'] ) : array();

		$text_part = isset( $arguments['text'] ) ? $this->sanitize_text_part( $arguments['text'] ) : '';
		$html_part = isset( $arguments['html'] ) ? $this->sanitize_html_part( $arguments['html'] ) : '';

		if ( '' === $text_part && '' === $html_part ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_body', __( 'Provide either a text or HTML message body.', 'mcp-ai-wpoos-pro' ) );
		}

		$from_email = '';
		if ( ! empty( $arguments['from_email'] ) ) {
			$from_email = sanitize_email( $arguments['from_email'] );
		} elseif ( ! empty( $settings['brevo_from_email'] ) ) {
			$from_email = sanitize_email( $settings['brevo_from_email'] );
		}

		if ( '' === $from_email ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_missing_sender',
				__( 'A from email address must be configured before sending Brevo messages.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_brevo_sender' => __( 'Set the Brevo From Email in the NV oOS settings.', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		$from_name = '';
		if ( ! empty( $arguments['from_name'] ) ) {
			$from_name = sanitize_text_field( $arguments['from_name'] );
		} elseif ( ! empty( $settings['brevo_from_name'] ) ) {
			$from_name = sanitize_text_field( $settings['brevo_from_name'] );
		}

		// Build Brevo API payload.
		$sender = array( 'email' => $from_email );
		if ( '' !== $from_name ) {
			$sender['name'] = $from_name;
		}

		$payload = array(
			'sender'  => $sender,
			'to'      => $to,
			'subject' => $subject,
		);

		if ( ! empty( $cc ) ) {
			$payload['cc'] = $cc;
		}

		if ( ! empty( $bcc ) ) {
			$payload['bcc'] = $bcc;
		}

		if ( '' !== $text_part ) {
			$payload['textContent'] = $text_part;
		}

		if ( '' !== $html_part ) {
			$payload['htmlContent'] = $html_part;
		}

		$reply_to_email = ! empty( $arguments['reply_to_email'] ) ? sanitize_email( $arguments['reply_to_email'] ) : '';
		$reply_to_name  = ! empty( $arguments['reply_to_name'] ) ? sanitize_text_field( $arguments['reply_to_name'] ) : '';

		if ( '' !== $reply_to_email ) {
			$payload['replyTo'] = array( 'email' => $reply_to_email );

			if ( '' !== $reply_to_name ) {
				$payload['replyTo']['name'] = $reply_to_name;
			}
		}

		if ( ! empty( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$payload['tags'] = array_map( 'sanitize_text_field', array_slice( $arguments['tags'], 0, 10 ) );
		}

		$payload = apply_filters( 'wp_mcp_ai_brevo_payload', $payload, $arguments, $context, $this );

		$encoded_body = wp_json_encode( $payload );

		if ( false === $encoded_body ) {
			return new WP_Error( 'wp_mcp_ai_brevo_encoding_error', __( 'Failed to encode the Brevo request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		$timeout = $this->resolve_timeout( $settings );

		$request_args = array(
			'headers' => array(
				'api-key'      => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'timeout' => $timeout,
			'body'    => $encoded_body,
		);

		$request_args = apply_filters( 'wp_mcp_ai_brevo_request_args', $request_args, $payload, $arguments, $context, $this );

		WP_MCP_AI_Logger::log_event(
			'brevo_email_request',
			'Sending email via Brevo.',
			array(
				'subject' => $subject,
				'to'      => $this->pluck_emails( $to ),
				'cc'      => $this->pluck_emails( $cc ),
				'bcc'     => $this->pluck_emails( $bcc ),
			)
		);

		$preempt = apply_filters( 'wp_mcp_ai_brevo_pre_send', null, $payload, $request_args, $arguments, $context, $this );

		if ( null !== $preempt ) {
			$response = $preempt;
		} else {
			$response = wp_remote_post( self::API_ENDPOINT, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Brevo email request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_brevo_http_error',
				__( 'The Brevo API request failed to complete.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 201 !== (int) $status_code ) {
			$message_text = __( 'The Brevo API returned an unexpected status code.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
				$message_text .= ' ' . $decoded['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_brevo_http_status',
				$message_text,
				array(
					'status_code' => $status_code,
					'response'    => $decoded,
				)
			);
		}

		return array(
			'sent'       => true,
			'message_id' => isset( $decoded['messageId'] ) ? $decoded['messageId'] : null,
		);
	}

	/**
	 * Resolve the HTTP timeout for Brevo requests.
	 *
	 * @param array $settings Plugin settings.
	 * @return int
	 */
	protected function resolve_timeout( $settings ) {
		$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

		if ( $timeout <= 0 ) {
			$timeout = 30;
		}

		return $timeout;
	}

	/**
	 * Convert a list of mixed recipient definitions into Brevo-formatted structures.
	 *
	 * @param mixed $value Raw recipient data.
	 * @return array
	 */
	protected function normalise_address_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		$normalised = array();

		foreach ( $value as $entry ) {
			$email = '';
			$name  = '';

			if ( is_string( $entry ) ) {
				$email = sanitize_email( $entry );
			} elseif ( is_object( $entry ) ) {
				$entry = (array) $entry;
			}

			if ( is_array( $entry ) ) {
				if ( isset( $entry['email'] ) ) {
					$email = sanitize_email( $entry['email'] );
				}

				if ( isset( $entry['name'] ) ) {
					$name = sanitize_text_field( $entry['name'] );
				}
			}

			if ( '' === $email ) {
				continue;
			}

			$key = strtolower( $email );

			if ( isset( $normalised[ $key ] ) ) {
				continue;
			}

			$formatted = array( 'email' => $email );

			if ( '' !== $name ) {
				$formatted['name'] = $name;
			}

			$normalised[ $key ] = $formatted;
		}

		return array_values( $normalised );
	}

	/**
	 * Sanitize a plain-text email body.
	 *
	 * @param string $text Raw text body.
	 * @return string
	 */
	protected function sanitize_text_part( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		return trim( sanitize_textarea_field( $text ) );
	}

	/**
	 * Sanitize an HTML email body.
	 *
	 * @param string $html Raw HTML body.
	 * @return string
	 */
	protected function sanitize_html_part( $html ) {
		if ( ! is_string( $html ) ) {
			return '';
		}

		return trim( wp_kses_post( $html ) );
	}

	/**
	 * Extract a list of email addresses from formatted recipient arrays.
	 *
	 * @param array $recipients Formatted recipient list.
	 * @return array
	 */
	protected function pluck_emails( $recipients ) {
		if ( empty( $recipients ) || ! is_array( $recipients ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $entry ) {
						if ( ! is_array( $entry ) ) {
							return '';
						}

						return isset( $entry['email'] ) ? $entry['email'] : '';
					},
					$recipients
				)
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends emails.
			'external-api',         // Calls Brevo API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
