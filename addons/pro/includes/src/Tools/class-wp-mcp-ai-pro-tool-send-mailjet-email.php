<?php
/**
 * Tool that sends emails through the Mailjet API.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for triggering Mailjet email deliveries.
 */
class WP_MCP_AI_Pro_Tool_Send_Mailjet_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const API_ENDPOINT = 'https://api.mailjet.com/v3.1/send';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_mailjet_email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Mailjet Email', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends an email using the configured Mailjet credentials.', 'wp-mcp-ai' );
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
					'description' => __( 'Subject line for the outgoing message.', 'wp-mcp-ai' ),
				),
				'text'           => array(
					'type'        => 'string',
					'description' => __( 'Optional plain-text body for the message.', 'wp-mcp-ai' ),
				),
				'html'           => array(
					'type'        => 'string',
					'description' => __( 'Optional HTML body for the message.', 'wp-mcp-ai' ),
				),
				'to'             => array(
					'type'        => 'array',
					'description' => __( 'List of primary recipients. Each entry may be an email string or an object with email and name fields.', 'wp-mcp-ai' ),
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
					'description' => __( 'Optional CC recipients formatted like the "to" field.', 'wp-mcp-ai' ),
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
					'description' => __( 'Optional BCC recipients formatted like the "to" field.', 'wp-mcp-ai' ),
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
					'description' => __( 'Optional sender email override. Defaults to the Mailjet From Email in settings.', 'wp-mcp-ai' ),
				),
				'from_name'      => array(
					'type'        => 'string',
					'description' => __( 'Optional sender name override.', 'wp-mcp-ai' ),
				),
				'reply_to_email' => array(
					'type'        => 'string',
					'description' => __( 'Optional reply-to email address.', 'wp-mcp-ai' ),
				),
				'reply_to_name'  => array(
					'type'        => 'string',
					'description' => __( 'Optional reply-to display name.', 'wp-mcp-ai' ),
				),
				'custom_id'      => array(
					'type'        => 'string',
					'description' => __( 'Optional custom identifier to attach to the Mailjet message.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'subject', 'to' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_mailjet_email_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Mailjet emails.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$api_key    = isset( $settings['mailjet_api_key'] ) ? trim( $settings['mailjet_api_key'] ) : '';
		$api_secret = isset( $settings['mailjet_api_secret'] ) ? trim( $settings['mailjet_api_secret'] ) : '';

		if ( '' === $api_key || '' === $api_secret ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_missing_credentials',
				__( 'Mailjet API credentials have not been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_mailjet_credentials' => __( 'Add a Mailjet API key and secret in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$subject = isset( $arguments['subject'] ) ? sanitize_text_field( $arguments['subject'] ) : '';

		if ( '' === $subject ) {
			return new WP_Error( 'wp_mcp_ai_mailjet_missing_subject', __( 'An email subject must be provided.', 'wp-mcp-ai' ) );
		}

		$to = isset( $arguments['to'] ) ? $this->normalise_address_list( $arguments['to'] ) : array();

		if ( empty( $to ) ) {
			return new WP_Error( 'wp_mcp_ai_mailjet_missing_recipients', __( 'At least one valid recipient must be supplied.', 'wp-mcp-ai' ) );
		}

		$cc  = isset( $arguments['cc'] ) ? $this->normalise_address_list( $arguments['cc'] ) : array();
		$bcc = isset( $arguments['bcc'] ) ? $this->normalise_address_list( $arguments['bcc'] ) : array();

		$text_part = isset( $arguments['text'] ) ? $this->sanitize_text_part( $arguments['text'] ) : '';
		$html_part = isset( $arguments['html'] ) ? $this->sanitize_html_part( $arguments['html'] ) : '';

		if ( '' === $text_part && '' === $html_part ) {
			return new WP_Error( 'wp_mcp_ai_mailjet_missing_body', __( 'Provide either a text or HTML message body.', 'wp-mcp-ai' ) );
		}

		$from_email = '';
		if ( ! empty( $arguments['from_email'] ) ) {
			$from_email = sanitize_email( $arguments['from_email'] );
		} elseif ( ! empty( $settings['mailjet_from_email'] ) ) {
			$from_email = sanitize_email( $settings['mailjet_from_email'] );
		}

		if ( '' === $from_email ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_missing_sender',
				__( 'A from email address must be configured before sending Mailjet messages.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_mailjet_sender' => __( 'Set the Mailjet From Email in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$from_name = '';
		if ( ! empty( $arguments['from_name'] ) ) {
			$from_name = sanitize_text_field( $arguments['from_name'] );
		} elseif ( ! empty( $settings['mailjet_from_name'] ) ) {
			$from_name = sanitize_text_field( $settings['mailjet_from_name'] );
		}

		$message = array(
			'From'    => array(
				'Email' => $from_email,
			),
			'To'      => $to,
			'Subject' => $subject,
		);

		if ( '' !== $from_name ) {
			$message['From']['Name'] = $from_name;
		}

		if ( ! empty( $cc ) ) {
			$message['Cc'] = $cc;
		}

		if ( ! empty( $bcc ) ) {
			$message['Bcc'] = $bcc;
		}

		if ( '' !== $text_part ) {
			$message['TextPart'] = $text_part;
		}

		if ( '' !== $html_part ) {
			$message['HTMLPart'] = $html_part;
		}

		$reply_to_email = ! empty( $arguments['reply_to_email'] ) ? sanitize_email( $arguments['reply_to_email'] ) : '';
		$reply_to_name  = ! empty( $arguments['reply_to_name'] ) ? sanitize_text_field( $arguments['reply_to_name'] ) : '';

		if ( '' !== $reply_to_email ) {
			$message['ReplyTo'] = array( 'Email' => $reply_to_email );

			if ( '' !== $reply_to_name ) {
				$message['ReplyTo']['Name'] = $reply_to_name;
			}
		}

		if ( ! empty( $arguments['custom_id'] ) ) {
			$message['CustomID'] = substr( sanitize_text_field( $arguments['custom_id'] ), 0, 255 );
		}

		$payload = array(
			'Messages' => array( $message ),
		);

		$payload = apply_filters( 'wp_mcp_ai_mailjet_payload', $payload, $arguments, $context, $this );

		$encoded_body = wp_json_encode( $payload );

		if ( false === $encoded_body ) {
			return new WP_Error( 'wp_mcp_ai_mailjet_encoding_error', __( 'Failed to encode the Mailjet request payload.', 'wp-mcp-ai' ) );
		}

		$timeout = $this->resolve_timeout( $settings );

		$request_args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => $timeout,
			'body'    => $encoded_body,
		);

		$request_args = apply_filters( 'wp_mcp_ai_mailjet_request_args', $request_args, $payload, $arguments, $context, $this );

		WP_MCP_AI_Logger::log_event(
			'mailjet_email_request',
			'Sending email via Mailjet.',
			array(
				'subject' => $subject,
				'to'      => $this->pluck_emails( $to ),
				'cc'      => $this->pluck_emails( $cc ),
				'bcc'     => $this->pluck_emails( $bcc ),
			)
		);

		$preempt = apply_filters( 'wp_mcp_ai_mailjet_pre_send', null, $payload, $request_args, $arguments, $context, $this );

		if ( null !== $preempt ) {
			$response = $preempt;
		} else {
			$response = wp_remote_post( self::API_ENDPOINT, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Mailjet email request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_mailjet_http_error',
				__( 'The Mailjet API request failed to complete.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 200 !== (int) $status_code ) {
			$message_text = __( 'The Mailjet API returned an unexpected status code.', 'wp-mcp-ai' );

			if ( is_array( $decoded ) && isset( $decoded['ErrorMessage'] ) ) {
				$message_text .= ' ' . $decoded['ErrorMessage'];
			}

			return new WP_Error(
				'wp_mcp_ai_mailjet_http_status',
				$message_text,
				array(
					'status_code' => $status_code,
					'response'    => $decoded,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_invalid_response',
				__( 'Mailjet returned an invalid response payload.', 'wp-mcp-ai' ),
				array( 'body' => $body )
			);
		}

		if ( empty( $decoded['Messages'] ) || ! is_array( $decoded['Messages'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_missing_messages',
				__( 'Mailjet did not return any message status information.', 'wp-mcp-ai' ),
				array( 'response' => $decoded )
			);
		}

		$first_status = isset( $decoded['Messages'][0]['Status'] ) ? strtolower( (string) $decoded['Messages'][0]['Status'] ) : '';

		if ( 'success' !== $first_status ) {
			$error_details = '';

			if ( isset( $decoded['Messages'][0]['Errors'] ) && is_array( $decoded['Messages'][0]['Errors'] ) ) {
				$error_details = wp_json_encode( $decoded['Messages'][0]['Errors'] );
			}

			return new WP_Error(
				'wp_mcp_ai_mailjet_failed',
				__( 'Mailjet reported a failure when sending the email.', 'wp-mcp-ai' ) . ( $error_details ? ' ' . $error_details : '' ),
				array( 'response' => $decoded )
			);
		}

		return array(
			'sent'     => true,
			'messages' => $decoded['Messages'],
		);
	}

	/**
	 * Resolve the HTTP timeout for Mailjet requests.
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
	 * Convert a list of mixed recipient definitions into Mailjet formatted structures.
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

			$formatted = array( 'Email' => $email );

			if ( '' !== $name ) {
				$formatted['Name'] = $name;
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

						return isset( $entry['Email'] ) ? $entry['Email'] : '';
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
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
