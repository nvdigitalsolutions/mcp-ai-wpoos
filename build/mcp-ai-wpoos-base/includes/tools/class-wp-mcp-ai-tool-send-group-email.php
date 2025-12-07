<?php
/**
 * Tool that sends group emails using the WordPress mail API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';

/**
 * Provides a tool for sending a group email based on an uploaded file.
 */
class WP_MCP_AI_Tool_Send_Group_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Attachment_File_Resolver;
	const DEFAULT_MAX_RECIPIENTS = 100;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_group_email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Group Email', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends an email using the WordPress mailer to recipients. Email content (subject, message, recipients) can be provided directly as parameters or loaded from uploaded attachment files in JSON or plain text format.', 'wp-mcp-ai' );
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
					'description' => __( 'Email subject line. Can be provided here or in attachment file.', 'wp-mcp-ai' ),
				),
				'message'        => array(
					'type'        => 'string',
					'description' => __( 'Email message content. Can be provided here or in attachment file.', 'wp-mcp-ai' ),
				),
				'recipients'     => array(
					'type'        => 'array',
					'description' => __( 'List of email recipients. Can be provided here or in attachment file. Each entry may be a string email address or object containing an email field.', 'wp-mcp-ai' ),
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
				'attachment_id'  => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Optional WordPress attachment ID containing email definition (JSON or plain text format).', 'wp-mcp-ai' ),
				),
				'file_id'        => $this->get_file_id_parameter_schema(),
				'url'            => $this->get_url_parameter_schema( 'file', __( 'URL to email definition file (JSON or plain text format).', 'wp-mcp-ai' ) ),
				'attachment_ids' => array(
					'type'        => 'array',
					'description' => __( 'Optional list of WordPress attachment IDs to combine into one email.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => array( 'integer', 'string' ),
					),
				),
				'from_email'     => array(
					'type'        => 'string',
					'description' => __( 'Optional override for the from email address.', 'wp-mcp-ai' ),
				),
				'from_name'      => array(
					'type'        => 'string',
					'description' => __( 'Optional override for the from name.', 'wp-mcp-ai' ),
				),
				'headers'        => array(
					'type'        => 'array',
					'description' => __( 'Additional headers to merge into the outgoing message.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
			),
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

		$settings            = WP_MCP_AI_Admin_Settings::get_settings();
		$default_capability  = isset( $settings['group_email_capability'] ) ? $settings['group_email_capability'] : 'publish_posts';
		$required_capability = apply_filters( 'wp_mcp_ai_send_group_email_capability', $default_capability, $context, $arguments, $this );
		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send group emails.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$subject       = isset( $arguments['subject'] ) ? sanitize_text_field( $arguments['subject'] ) : '';
		$message_parts = array();

		if ( ! empty( $arguments['message'] ) ) {
			$message_parts[] = $this->normalise_message( $arguments['message'] );
		}

		$email_request = array(
			'recipients' => $this->normalise_recipient_list( isset( $arguments['recipients'] ) ? $arguments['recipients'] : array() ),
			'cc'         => array(),
			'bcc'        => array(),
		);

		$attachment_ids = $this->gather_attachment_ids( $arguments );

		foreach ( $attachment_ids as $attachment_id ) {
			$parsed = $this->parse_email_definition_attachment( $attachment_id );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			if ( empty( $subject ) && ! empty( $parsed['subject'] ) ) {
				$subject = $parsed['subject'];
			}

			if ( ! empty( $parsed['message'] ) ) {
				$message_parts[] = $parsed['message'];
			}

			if ( ! empty( $parsed['recipients'] ) ) {
				$email_request['recipients'] = array_merge( $email_request['recipients'], $parsed['recipients'] );
			}

			if ( ! empty( $parsed['cc'] ) ) {
				$email_request['cc'] = array_merge( $email_request['cc'], $parsed['cc'] );
			}

			if ( ! empty( $parsed['bcc'] ) ) {
				$email_request['bcc'] = array_merge( $email_request['bcc'], $parsed['bcc'] );
			}
		}

		$email_request['recipients'] = $this->filter_unique_emails( $email_request['recipients'] );
		$email_request['cc']         = $this->filter_unique_emails( $email_request['cc'], $email_request['recipients'] );
		$email_request['bcc']        = $this->filter_unique_emails( $email_request['bcc'], array_merge( $email_request['recipients'], $email_request['cc'] ) );

		if ( empty( $email_request['recipients'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_recipients', __( 'No recipients were provided for the group email.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$configured_max = isset( $settings['group_email_max_recipients'] ) ? absint( $settings['group_email_max_recipients'] ) : self::DEFAULT_MAX_RECIPIENTS;
		$max_recipients = apply_filters( 'wp_mcp_ai_send_group_email_max_recipients', $configured_max, $context, $arguments, $this );
		if ( is_numeric( $max_recipients ) && $max_recipients > 0 && count( $email_request['recipients'] ) > absint( $max_recipients ) ) {
			return new WP_Error( 'wp_mcp_ai_recipient_limit_exceeded', __( 'The group email includes more recipients than allowed.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$message_parts = array_filter( array_map( 'trim', $message_parts ) );
		$message       = trim( implode( "\n\n", array_unique( $message_parts ) ) );

		if ( '' === $subject ) {
			return new WP_Error( 'wp_mcp_ai_missing_subject', __( 'The email subject could not be determined.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		if ( '' === $message ) {
			return new WP_Error( 'wp_mcp_ai_missing_message', __( 'The email message could not be determined.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$headers = $this->prepare_headers( $arguments, $email_request );

		$mail_args = array(
			'to'          => $email_request['recipients'],
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $headers,
			'attachments' => array(),
		);

		$mail_args = apply_filters( 'wp_mcp_ai_send_group_email_mail_args', $mail_args, $arguments, $context, $email_request, $this );

		if ( is_wp_error( $mail_args ) ) {
			return $mail_args;
		}

		if ( ! is_array( $mail_args ) || empty( $mail_args['to'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_mail_args', __( 'The email could not be prepared for sending.', 'wp-mcp-ai' ) );
		}

		$pre_send = apply_filters( 'wp_mcp_ai_send_group_email_pre_send', null, $mail_args, $arguments, $context, $email_request, $this );
		if ( null !== $pre_send ) {
			if ( is_wp_error( $pre_send ) ) {
				return $pre_send;
			}

			$sent = (bool) $pre_send;
		} else {
			$sent = wp_mail( $mail_args['to'], $mail_args['subject'], $mail_args['message'], $mail_args['headers'], isset( $mail_args['attachments'] ) ? $mail_args['attachments'] : array() );
		}

		if ( ! $sent ) {
			return new WP_Error( 'wp_mcp_ai_mail_failed', __( 'The group email could not be sent.', 'wp-mcp-ai' ) );
		}

		do_action( 'wp_mcp_ai_send_group_email_after_send', $mail_args, $arguments, $context, $email_request, $this );

		return array(
			'sent'       => true,
			'recipients' => $mail_args['to'],
			'subject'    => $mail_args['subject'],
			'cc'         => isset( $email_request['cc'] ) ? $email_request['cc'] : array(),
			'bcc'        => isset( $email_request['bcc'] ) ? $email_request['bcc'] : array(),
		);
	}

	/**
	 * Gather attachment identifiers from the provided arguments.
	 *
	 * @param array $arguments Raw arguments.
	 * @return int[]
	 */
	protected function gather_attachment_ids( array $arguments ) {
		$ids = array();

		// Resolve attachment_id, file_id, or url.
		if ( ! empty( $arguments['attachment_id'] ) || ! empty( $arguments['file_id'] ) || ! empty( $arguments['url'] ) ) {
			$resolved = $this->resolve_attachment_id( $arguments );

			// Handle remote URL case - would need to download file first.
			if ( is_array( $resolved ) && isset( $resolved['url'] ) ) {
				// For email files from URLs, we'd need to fetch and parse them.
				// For now, return error as this is complex.
				return new WP_Error(
					'wp_mcp_ai_remote_url_not_supported',
					__( 'Remote URLs are not yet supported for email definition files. Please upload to Media Library first.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			} elseif ( is_wp_error( $resolved ) ) {
				return $resolved;
			} elseif ( $resolved > 0 ) {
				$ids[] = $resolved;
			}
		}

		if ( ! empty( $arguments['attachment_ids'] ) && is_array( $arguments['attachment_ids'] ) ) {
			foreach ( $arguments['attachment_ids'] as $id ) {
				$ids[] = absint( $id );
			}
		}

		$ids = array_filter( array_unique( $ids ) );

		return array_values( $ids );
	}

	/**
	 * Normalise a free-form message string.
	 *
	 * @param string $message Raw message value.
	 * @return string
	 */
	protected function normalise_message( $message ) {
		if ( is_array( $message ) ) {
			$message = wp_json_encode( $message );
		}

		$message = (string) $message;
		$message = wp_kses_post( $message );

		return trim( $message );
	}

	/**
	 * Normalise a list of recipients supplied directly in the request.
	 *
	 * @param array $recipients Raw recipients list.
	 * @return array
	 */
	protected function normalise_recipient_list( $recipients ) {
		$normalised = array();

		if ( ! is_array( $recipients ) ) {
			$recipients = array( $recipients );
		}

		foreach ( $recipients as $recipient ) {
			if ( is_array( $recipient ) ) {
				if ( empty( $recipient['email'] ) ) {
					continue;
				}

				$email = sanitize_email( $recipient['email'] );
			} else {
				$email = sanitize_email( $recipient );
			}

			if ( empty( $email ) ) {
				continue;
			}

			$normalised[] = $email;
		}

		return $normalised;
	}

	/**
	 * Parse an attachment into an email definition.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|WP_Error
	 */
	protected function parse_email_definition_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return new WP_Error( 'wp_mcp_ai_invalid_attachment', __( 'The provided attachment could not be resolved.', 'wp-mcp-ai' ) );
		}

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_attachment', __( 'The provided attachment is not accessible.', 'wp-mcp-ai' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
		}

		if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $attachment_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_forbidden',
				__( 'You do not have permission to use the requested attachment.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_file', __( 'The attachment file could not be found.', 'wp-mcp-ai' ) );
		}

		$max_bytes = apply_filters( 'wp_mcp_ai_email_definition_attachment_max_bytes', 1024 * 1024, $attachment_id, $this );
		$max_bytes = absint( $max_bytes );

		if ( $max_bytes < 1 ) {
			$max_bytes = 1024 * 1024;
		}
		$file_size = filesize( $file_path );

		if ( false !== $file_size && $file_size > $max_bytes ) {
			return new WP_Error(
				'wp_mcp_ai_attachment_too_large',
				sprintf(
					/* translators: %s: formatted file size limit. */
					__( 'The attachment file is too large. The maximum allowed size is %s.', 'wp-mcp-ai' ),
					size_format( $max_bytes )
				),
				array( 'status' => 413 )
			);
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return new WP_Error( 'wp_mcp_ai_unreadable_file', __( 'The attachment file could not be read.', 'wp-mcp-ai' ) );
		}

		$contents = trim( $contents );
		if ( '' === $contents ) {
			return array(
				'subject'    => '',
				'message'    => '',
				'recipients' => array(),
				'cc'         => array(),
				'bcc'        => array(),
			);
		}

		$decoded = json_decode( $contents, true );
		if ( is_array( $decoded ) ) {
			return $this->parse_json_email_definition( $decoded );
		}

		return $this->parse_plain_text_email_definition( $contents );
	}

	/**
	 * Parse a JSON email definition payload.
	 *
	 * @param array $payload Decoded payload.
	 * @return array
	 */
	protected function parse_json_email_definition( array $payload ) {
		$subject = '';
		$message = '';

		if ( ! empty( $payload['subject'] ) ) {
			$subject = sanitize_text_field( $payload['subject'] );
		}

		if ( ! empty( $payload['message'] ) ) {
			$message = $this->normalise_message( $payload['message'] );
		} elseif ( ! empty( $payload['body'] ) ) {
			$message = $this->normalise_message( $payload['body'] );
		} elseif ( ! empty( $payload['content'] ) ) {
			$message = $this->normalise_message( $payload['content'] );
		}

		$recipients = array();
		if ( isset( $payload['recipients'] ) ) {
			$recipients = $this->normalise_recipient_list( $payload['recipients'] );
		} elseif ( isset( $payload['to'] ) ) {
			$recipients = $this->normalise_recipient_list( $payload['to'] );
		}

		$cc  = array();
		$bcc = array();

		if ( isset( $payload['cc'] ) ) {
			$cc = $this->normalise_recipient_list( $payload['cc'] );
		}

		if ( isset( $payload['bcc'] ) ) {
			$bcc = $this->normalise_recipient_list( $payload['bcc'] );
		}

		return array(
			'subject'    => $subject,
			'message'    => $message,
			'recipients' => $recipients,
			'cc'         => $cc,
			'bcc'        => $bcc,
		);
	}

	/**
	 * Parse a plain text email definition payload.
	 *
	 * @param string $contents Raw file contents.
	 * @return array
	 */
	protected function parse_plain_text_email_definition( $contents ) {
		$lines         = preg_split( '/\r\n|\r|\n/', $contents );
		$subject       = '';
		$recipients    = array();
		$cc            = array();
		$bcc           = array();
		$message_lines = array();

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed && empty( $message_lines ) ) {
				continue;
			}

			if ( preg_match( '/^subject\s*:\s*(.+)$/i', $trimmed, $matches ) ) {
				if ( empty( $subject ) ) {
					$subject = sanitize_text_field( $matches[1] );
				}
				continue;
			}

			if ( preg_match( '/^to\s*:\s*(.+)$/i', $trimmed, $matches ) ) {
				$recipients = array_merge( $recipients, $this->split_recipient_line( $matches[1] ) );
				continue;
			}

			if ( preg_match( '/^cc\s*:\s*(.+)$/i', $trimmed, $matches ) ) {
				$cc = array_merge( $cc, $this->split_recipient_line( $matches[1] ) );
				continue;
			}

			if ( preg_match( '/^bcc\s*:\s*(.+)$/i', $trimmed, $matches ) ) {
				$bcc = array_merge( $bcc, $this->split_recipient_line( $matches[1] ) );
				continue;
			}

			$message_lines[] = $line;
		}

		$message_lines = array_map( array( $this, 'normalise_message' ), array( implode( "\n", $message_lines ) ) );
		$message       = trim( implode( "\n", $message_lines ) );

		if ( '' === $message ) {
			$message = $this->normalise_message( $contents );
		}

		if ( empty( $recipients ) ) {
			$recipients = $this->split_recipient_line( $contents );
		}

		return array(
			'subject'    => $subject,
			'message'    => $message,
			'recipients' => $recipients,
			'cc'         => $cc,
			'bcc'        => $bcc,
		);
	}

	/**
	 * Split a recipient list into email addresses.
	 *
	 * @param string $value Recipient definition.
	 * @return array
	 */
	protected function split_recipient_line( $value ) {
		if ( '' === $value ) {
			return array();
		}

		$fragments = preg_split( '/[,;\s]+/', $value );
		$emails    = array();

		foreach ( $fragments as $fragment ) {
			$email = sanitize_email( $fragment );
			if ( empty( $email ) ) {
				continue;
			}

			$emails[] = $email;
		}

		if ( empty( $emails ) ) {
			if ( preg_match_all( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value, $matches ) ) {
				foreach ( $matches[0] as $match ) {
					$email = sanitize_email( $match );
					if ( $email ) {
						$emails[] = $email;
					}
				}
			}
		}

		return $emails;
	}

	/**
	 * Filter and deduplicate email addresses.
	 *
	 * @param array $emails   Emails to filter.
	 * @param array $existing Optional addresses that should be excluded.
	 * @return array
	 */
	protected function filter_unique_emails( array $emails, array $existing = array() ) {
		$existing = array_map( 'strtolower', $existing );
		$filtered = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( $email );
			if ( empty( $email ) ) {
				continue;
			}

			$lower = strtolower( $email );

			if ( in_array( $lower, $existing, true ) || in_array( $lower, $filtered, true ) ) {
				continue;
			}

			$filtered[] = $lower;
		}

		return array_map( 'sanitize_email', $filtered );
	}

	/**
	 * Prepare mail headers.
	 *
	 * @param array $arguments    Original arguments.
	 * @param array $email_request Parsed email request details.
	 * @return array
	 */
	protected function prepare_headers( array $arguments, array $email_request ) {
		$headers = array();

		$from_email = isset( $arguments['from_email'] ) ? sanitize_email( $arguments['from_email'] ) : '';
		$from_name  = isset( $arguments['from_name'] ) ? sanitize_text_field( $arguments['from_name'] ) : '';

		if ( $from_email ) {
			if ( $from_name ) {
				$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
			} else {
				$headers[] = sprintf( 'From: %s', $from_email );
			}
		}

		if ( ! empty( $email_request['cc'] ) ) {
			$headers[] = 'Cc: ' . implode( ', ', $email_request['cc'] );
		}

		if ( ! empty( $email_request['bcc'] ) ) {
			$headers[] = 'Bcc: ' . implode( ', ', $email_request['bcc'] );
		}

		if ( ! empty( $arguments['headers'] ) && is_array( $arguments['headers'] ) ) {
			foreach ( $arguments['headers'] as $header ) {
				$header = wp_strip_all_tags( (string) $header, true );
				$header = trim( $header );

				if ( '' === $header ) {
					continue;
				}

				if ( preg_match( '/[\r\n\x00]/', $header ) ) {
					continue;
				}

				if ( false === strpos( $header, ':' ) ) {
					continue;
				}

				list( $header_name, $header_value ) = array_map( 'trim', explode( ':', $header, 2 ) );

				if ( '' === $header_name || '' === $header_value ) {
					continue;
				}

				if ( ! preg_match( '/^[A-Za-z0-9-]+$/', $header_name ) ) {
					continue;
				}

				if ( preg_match( '/[\r\n\x00]/', $header_value ) ) {
					continue;
				}

				$header_value = preg_replace( '/[\x00-\x1F\x7F]/', '', $header_value );
				$header_value = trim( $header_value );

				if ( '' === $header_value ) {
					continue;
				}

				$headers[] = sprintf( '%s: %s', $header_name, $header_value );
			}
		}

		return array_values( array_unique( $headers ) );
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
