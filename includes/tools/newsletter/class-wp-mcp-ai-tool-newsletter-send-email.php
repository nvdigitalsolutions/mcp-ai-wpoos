<?php
/**
 * Tool for sending emails via the Newsletter plugin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a custom email to specified recipients using The Newsletter Plugin.
 */
class WP_MCP_AI_Tool_Newsletter_Send_Email implements WP_MCP_AI_Tool_Interface {
	/**
	 * Determine whether the Newsletter plugin is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'Newsletter' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Newsletter Send Email tool is disabled because The Newsletter Plugin is not installed or active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'newsletter_send_email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Newsletter Send Email', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a custom email to specified recipients using The Newsletter Plugin.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'to'            => array(
					'type'        => 'array',
					'description' => __( 'Array of recipient email addresses.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'email',
					),
					'minItems'    => 1,
				),
				'subject'       => array(
					'type'        => 'string',
					'description' => __( 'Email subject line (required).', 'wp-mcp-ai' ),
				),
				'message'       => array(
					'type'        => 'string',
					'description' => __( 'Email message body in HTML format (required).', 'wp-mcp-ai' ),
				),
				'from_name'     => array(
					'type'        => 'string',
					'description' => __( 'Sender name (optional, uses plugin default if not provided).', 'wp-mcp-ai' ),
				),
				'from_email'    => array(
					'type'        => 'string',
					'description' => __( 'Sender email address (optional, uses plugin default if not provided).', 'wp-mcp-ai' ),
					'format'      => 'email',
				),
				'subscriber_id' => array(
					'type'        => 'integer',
					'description' => __( 'Send to a specific subscriber by ID instead of email addresses.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'subject', 'message' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_newsletter_unavailable', __( 'The Newsletter Plugin is not available.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send newsletter emails.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( empty( $arguments['subject'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_subject', __( 'Email subject is required.', 'wp-mcp-ai' ) );
		}

		if ( empty( $arguments['message'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_message', __( 'Email message is required.', 'wp-mcp-ai' ) );
		}

		$subject = sanitize_text_field( $arguments['subject'] );
		$message = wp_kses_post( $arguments['message'] );

		// Determine recipients.
		$recipients = array();

		if ( ! empty( $arguments['subscriber_id'] ) ) {
			// Send to a specific subscriber.
			$subscriber_id = absint( $arguments['subscriber_id'] );
			$subscriber    = $this->get_subscriber_by_id( $subscriber_id );

			if ( ! $subscriber || empty( $subscriber->email ) ) {
				return new WP_Error( 'wp_mcp_ai_subscriber_not_found', __( 'Subscriber not found.', 'wp-mcp-ai' ) );
			}

			$recipients[] = sanitize_email( $subscriber->email );
		} elseif ( ! empty( $arguments['to'] ) && is_array( $arguments['to'] ) ) {
			// Send to provided email addresses.
			foreach ( $arguments['to'] as $email ) {
				$email = sanitize_email( $email );
				if ( is_email( $email ) ) {
					$recipients[] = $email;
				}
			}
		}

		if ( empty( $recipients ) ) {
			return new WP_Error( 'wp_mcp_ai_no_recipients', __( 'At least one valid recipient email address is required.', 'wp-mcp-ai' ) );
		}

		// Prepare email options.
		$from_name  = ! empty( $arguments['from_name'] ) ? sanitize_text_field( $arguments['from_name'] ) : '';
		$from_email = ! empty( $arguments['from_email'] ) ? sanitize_email( $arguments['from_email'] ) : '';

		$sent_count   = 0;
		$failed_count = 0;
		$errors       = array();

		foreach ( $recipients as $recipient ) {
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );

			if ( $from_name && $from_email ) {
				$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
			} elseif ( $from_email ) {
				$headers[] = 'From: ' . $from_email;
			}

			// Try to use Newsletter's mail function if available, otherwise fall back to wp_mail.
			$mail_sent = false;

			if ( class_exists( 'Newsletter' ) && method_exists( 'Newsletter', 'instance' ) ) {
				$newsletter = Newsletter::instance();
				if ( method_exists( $newsletter, 'mail' ) ) {
					$mail_sent = $newsletter->mail( $recipient, $subject, array( 'html' => $message ) );
				}
			}

			if ( ! $mail_sent ) {
				$mail_sent = wp_mail( $recipient, $subject, $message, $headers );
			}

			if ( $mail_sent ) {
				++$sent_count;
			} else {
				++$failed_count;
				$errors[] = $recipient;
			}
		}

		if ( $sent_count > 0 ) {
			return array(
				'success'      => true,
				'sent_count'   => $sent_count,
				'failed_count' => $failed_count,
				'message'      => sprintf(
					/* translators: %d: number of emails sent */
					_n( '%d email sent successfully.', '%d emails sent successfully.', $sent_count, 'wp-mcp-ai' ),
					$sent_count
				),
				'errors'       => $errors,
			);
		}

		return new WP_Error( 'wp_mcp_ai_email_send_failed', __( 'Failed to send emails.', 'wp-mcp-ai' ), array( 'failed_recipients' => $errors ) );
	}

	/**
	 * Retrieve a subscriber by ID.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return object|null
	 */
	protected function get_subscriber_by_id( $subscriber_id ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'newsletter';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $subscriber_id ) );
	}
}
