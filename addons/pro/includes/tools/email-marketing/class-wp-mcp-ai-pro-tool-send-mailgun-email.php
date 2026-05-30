<?php
/**
 * Tool that sends emails through the Mailgun API.
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
 * Provides a tool for sending emails via the Mailgun Messages API.
 *
 * Mailgun API docs: https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun
 *
 * Authentication: HTTP Basic Auth with username "api" and the Mailgun API key as the password.
 * Send endpoint: https://api.mailgun.net/v3/{domain}/messages  (US region)
 *                https://api.eu.mailgun.net/v3/{domain}/messages (EU region)
 */
class WP_MCP_AI_Pro_Tool_Send_Mailgun_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const API_BASE_US = 'https://api.mailgun.net/v3';
	const API_BASE_EU = 'https://api.eu.mailgun.net/v3';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_mailgun_email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Mailgun Email', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends an email using the configured Mailgun API credentials. Supports transactional and marketing email delivery with tracking and scheduling options.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'subject'    => array(
					'type'        => 'string',
					'description' => __( 'Subject line for the outgoing message.', 'mcp-ai-wpoos-pro' ),
				),
				'text'       => array(
					'type'        => 'string',
					'description' => __( 'Optional plain-text body for the message.', 'mcp-ai-wpoos-pro' ),
				),
				'html'       => array(
					'type'        => 'string',
					'description' => __( 'Optional HTML body for the message.', 'mcp-ai-wpoos-pro' ),
				),
				'to'         => array(
					'type'        => 'array',
					'description' => __( 'List of primary recipients. Each entry may be an email string or an object with email and name fields.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'string' ),
							array(
								'type'                 => 'object',
								'properties'           => array(
									'email' => array( 'type' => 'string' ),
									'name'  => array( 'type' => 'string' ),
								),
								'required'             => array( 'email' ),
								'additionalProperties' => true,
							),
						),
					),
					'minItems'    => 1,
				),
				'cc'         => array(
					'type'        => 'array',
					'description' => __( 'Optional CC recipients formatted like the "to" field.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'string' ),
							array(
								'type'                 => 'object',
								'properties'           => array(
									'email' => array( 'type' => 'string' ),
									'name'  => array( 'type' => 'string' ),
								),
								'required'             => array( 'email' ),
								'additionalProperties' => true,
							),
						),
					),
				),
				'bcc'        => array(
					'type'        => 'array',
					'description' => __( 'Optional BCC recipients formatted like the "to" field.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'anyOf' => array(
							array( 'type' => 'string' ),
							array(
								'type'                 => 'object',
								'properties'           => array(
									'email' => array( 'type' => 'string' ),
									'name'  => array( 'type' => 'string' ),
								),
								'required'             => array( 'email' ),
								'additionalProperties' => true,
							),
						),
					),
				),
				'from_email' => array(
					'type'        => 'string',
					'description' => __( 'Optional sender email override. Defaults to the Mailgun From Email in settings.', 'mcp-ai-wpoos-pro' ),
				),
				'from_name'  => array(
					'type'        => 'string',
					'description' => __( 'Optional sender name override.', 'mcp-ai-wpoos-pro' ),
				),
				'reply_to'   => array(
					'type'        => 'string',
					'description' => __( 'Optional reply-to email address.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'       => array(
					'type'        => 'array',
					'description' => __( 'Optional tags for organising messages in Mailgun (max 3). Each tag must be 128 characters or fewer.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 3,
				),
				'tracking'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to enable click and open tracking. Defaults to true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'domain'     => array(
					'type'        => 'string',
					'description' => __( 'Optional Mailgun sending domain override. Uses the domain configured in settings when omitted.', 'mcp-ai-wpoos-pro' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_mailgun_email_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Mailgun emails.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$api_key = isset( $settings['mailgun_api_key'] ) ? trim( $settings['mailgun_api_key'] ) : '';

		if ( '' === $api_key ) {
			return new WP_Error(
				'wp_mcp_ai_mailgun_missing_credentials',
				__( 'Mailgun API key has not been configured.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_mailgun_credentials' => __( 'Add a Mailgun API key in the NV oOS settings.', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		// Resolve the sending domain.
		$domain = '';
		if ( ! empty( $arguments['domain'] ) ) {
			$domain = sanitize_text_field( $arguments['domain'] );
		} elseif ( ! empty( $settings['mailgun_domain'] ) ) {
			$domain = sanitize_text_field( $settings['mailgun_domain'] );
		}

		if ( '' === $domain ) {
			return new WP_Error(
				'wp_mcp_ai_mailgun_missing_domain',
				__( 'A Mailgun sending domain must be configured.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_mailgun_domain' => __( 'Set the Mailgun Domain in the NV oOS settings.', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		$subject = isset( $arguments['subject'] ) ? sanitize_text_field( $arguments['subject'] ) : '';

		if ( '' === $subject ) {
			return new WP_Error( 'wp_mcp_ai_mailgun_missing_subject', __( 'An email subject must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$to = isset( $arguments['to'] ) ? $this->build_address_header( $arguments['to'] ) : '';

		if ( '' === $to ) {
			return new WP_Error( 'wp_mcp_ai_mailgun_missing_recipients', __( 'At least one valid recipient must be supplied.', 'mcp-ai-wpoos-pro' ) );
		}

		$text_part = isset( $arguments['text'] ) ? $this->sanitize_text_part( $arguments['text'] ) : '';
		$html_part = isset( $arguments['html'] ) ? $this->sanitize_html_part( $arguments['html'] ) : '';

		if ( '' === $text_part && '' === $html_part ) {
			return new WP_Error( 'wp_mcp_ai_mailgun_missing_body', __( 'Provide either a text or HTML message body.', 'mcp-ai-wpoos-pro' ) );
		}

		// Resolve the from address.
		$from_email = '';
		if ( ! empty( $arguments['from_email'] ) ) {
			$from_email = sanitize_email( $arguments['from_email'] );
		} elseif ( ! empty( $settings['mailgun_from_email'] ) ) {
			$from_email = sanitize_email( $settings['mailgun_from_email'] );
		}

		if ( '' === $from_email ) {
			return new WP_Error(
				'wp_mcp_ai_mailgun_missing_sender',
				__( 'A from email address must be configured before sending Mailgun messages.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_mailgun_sender' => __( 'Set the Mailgun From Email in the NV oOS settings.', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		$from_name = '';
		if ( ! empty( $arguments['from_name'] ) ) {
			$from_name = sanitize_text_field( $arguments['from_name'] );
		} elseif ( ! empty( $settings['mailgun_from_name'] ) ) {
			$from_name = sanitize_text_field( $settings['mailgun_from_name'] );
		}

		$from = '' !== $from_name ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email;

		// Build multipart form body expected by Mailgun.
		$body = array(
			'from'    => $from,
			'to'      => $to,
			'subject' => $subject,
		);

		if ( '' !== $text_part ) {
			$body['text'] = $text_part;
		}

		if ( '' !== $html_part ) {
			$body['html'] = $html_part;
		}

		if ( ! empty( $arguments['cc'] ) ) {
			$cc_header = $this->build_address_header( $arguments['cc'] );
			if ( '' !== $cc_header ) {
				$body['cc'] = $cc_header;
			}
		}

		if ( ! empty( $arguments['bcc'] ) ) {
			$bcc_header = $this->build_address_header( $arguments['bcc'] );
			if ( '' !== $bcc_header ) {
				$body['bcc'] = $bcc_header;
			}
		}

		if ( ! empty( $arguments['reply_to'] ) ) {
			$reply_to = sanitize_email( $arguments['reply_to'] );
			if ( '' !== $reply_to ) {
				$body['h:Reply-To'] = $reply_to;
			}
		}

		// Tracking.
		$tracking           = isset( $arguments['tracking'] ) ? (bool) $arguments['tracking'] : true;
		$body['o:tracking'] = $tracking ? 'yes' : 'no';

		// Tags (Mailgun supports up to 3 per message, each sent as a separate o:tag field).
		if ( ! empty( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$tag_list = array();
			foreach ( array_slice( $arguments['tags'], 0, 3 ) as $tag ) {
				$clean_tag = substr( sanitize_text_field( $tag ), 0, 128 );
				if ( '' !== $clean_tag ) {
					$tag_list[] = $clean_tag;
				}
			}

			if ( ! empty( $tag_list ) ) {
				// wp_remote_post encodes arrays as repeated fields (o:tag[0]=a&o:tag[1]=b),
				// which Mailgun accepts. Use a plain indexed array.
				$body['o:tag'] = $tag_list;
			}
		}

		$body = apply_filters( 'wp_mcp_ai_mailgun_body', $body, $arguments, $context, $this );

		// Resolve the API base URL based on the region setting.
		$region   = isset( $settings['mailgun_region'] ) ? sanitize_key( $settings['mailgun_region'] ) : 'us';
		$api_base = ( 'eu' === $region ) ? self::API_BASE_EU : self::API_BASE_US;
		$url      = trailingslashit( $api_base ) . rawurlencode( $domain ) . '/messages';

		$timeout = $this->resolve_timeout( $settings );

		$request_args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
			'timeout' => $timeout,
			'body'    => $body,
		);

		$request_args = apply_filters( 'wp_mcp_ai_mailgun_request_args', $request_args, $body, $arguments, $context, $this );

		WP_MCP_AI_Logger::log_event(
			'mailgun_email_request',
			'Sending email via Mailgun.',
			array(
				'subject' => $subject,
				'to'      => $to,
				'domain'  => $domain,
			)
		);

		$preempt = apply_filters( 'wp_mcp_ai_mailgun_pre_send', null, $body, $request_args, $arguments, $context, $this );

		if ( null !== $preempt ) {
			$response = $preempt;
		} else {
			$response = wp_remote_post( $url, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Mailgun email request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_mailgun_http_error',
				__( 'The Mailgun API request failed to complete.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_raw    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body_raw, true );

		if ( 200 !== (int) $status_code ) {
			$message_text = __( 'The Mailgun API returned an unexpected status code.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
				$message_text .= ' ' . $decoded['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_mailgun_http_status',
				$message_text,
				array(
					'status_code' => $status_code,
					'response'    => $decoded,
				)
			);
		}

		return array(
			'sent'    => true,
			'id'      => isset( $decoded['id'] ) ? $decoded['id'] : null,
			'message' => isset( $decoded['message'] ) ? $decoded['message'] : null,
		);
	}

	/**
	 * Build a comma-separated RFC 5321 address header value from a list of recipients.
	 *
	 * @param mixed $value Raw recipient data (array of strings or objects with email/name).
	 * @return string Comma-separated list of formatted email addresses.
	 */
	protected function build_address_header( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}

		$parts = array();
		$seen  = array();

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

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$parts[]      = '' !== $name ? sprintf( '%s <%s>', $name, $email ) : $email;
		}

		return implode( ', ', $parts );
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
	 * Resolve the HTTP timeout for Mailgun requests.
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends emails.
			'external-api',         // Calls Mailgun API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
