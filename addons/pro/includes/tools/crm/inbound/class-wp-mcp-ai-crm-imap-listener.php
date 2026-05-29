<?php
/**
 * CRM IMAP Polling Listener
 *
 * Periodically polls an IMAP inbox and routes new messages to the CRM
 * inbound evaluation pipeline. Uses the Schedule Manager for cron scheduling.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IMAP polling listener for the CRM toolkit.
 *
 * Registers a recurring Schedule Manager job that checks an IMAP inbox
 * for new messages and feeds them into evaluate_inbound_message.
 *
 * @todo Wire up to real IMAP credentials from the CRM settings integrations
 *       section (gmail_oauth_handle / outlook_oauth_handle).
 * @todo Add IMAP connection via PHP imap_* functions or a library.
 * @todo Parse inbound email into the message format expected by evaluate_inbound_message.
 */
class WP_MCP_AI_CRM_IMAP_Listener {

	/**
	 * Schedule Manager hook name for the IMAP polling job.
	 */
	const JOB_HOOK = 'wp_mcp_ai_crm_imap_poll';

	/**
	 * Default polling interval in seconds (5 minutes).
	 */
	const DEFAULT_INTERVAL = 300;

	/**
	 * Register the IMAP polling job if an IMAP integration handle is configured.
	 */
	public static function maybe_schedule() {
		$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();

		$has_imap = ! empty( $settings['integrations']['gmail_oauth_handle'] )
			|| ! empty( $settings['integrations']['outlook_oauth_handle'] );

		if ( ! $has_imap ) {
			return;
		}

		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( false === as_has_scheduled_action( self::JOB_HOOK ) ) {
			as_schedule_recurring_action(
				time() + self::DEFAULT_INTERVAL,
				self::DEFAULT_INTERVAL,
				self::JOB_HOOK,
				array(),
				'crm-imap'
			);
		}
	}

	/**
	 * Clear the IMAP polling job (called on deactivation).
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::JOB_HOOK );
		}
	}

	/**
	 * Poll the IMAP inbox and route new messages.
	 *
	 * Hooked into the Schedule Manager action. Uses PHP's native imap_*
	 * functions. Connection parameters are supplied via filters so no
	 * settings schema changes are needed.
	 *
	 * @since 2.3.0
	 */
	public static function poll() {
		// Bail if IMAP extension is not loaded.
		if ( ! function_exists( 'imap_open' ) ) {
			return;
		}

		$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();

		// Connection parameters supplied via filters.
		// Format example: {imap.gmail.com:993/imap/ssl}INBOX
		$conn_string = apply_filters( 'wp_mcp_ai_crm_imap_connection_string', '', $settings );
		$imap_user   = apply_filters( 'wp_mcp_ai_crm_imap_username', '', $settings );
		$imap_pass   = apply_filters( 'wp_mcp_ai_crm_imap_password', '', $settings );

		if ( empty( $conn_string ) || empty( $imap_user ) ) {
			return;
		}

		// Suppress PHP warnings; we handle failure via return value.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mailbox = @imap_open( $conn_string, $imap_user, $imap_pass, 0, 1 );
		if ( ! $mailbox ) {
			return;
		}

		// Search for unseen messages.
		$message_nos = imap_search( $mailbox, 'UNSEEN' );
		if ( empty( $message_nos ) ) {
			imap_close( $mailbox );
			return;
		}

		// Load the evaluate_inbound_message tool.
		$_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-evaluate-inbound-message.php';
		if ( ! file_exists( $_tool_file ) ) {
			imap_close( $mailbox );
			return;
		}
		require_once $_tool_file;
		if ( ! class_exists( 'WP_MCP_AI_Tool_Evaluate_Inbound_Message' ) ) {
			imap_close( $mailbox );
			return;
		}

		foreach ( $message_nos as $msg_no ) {
			$header = imap_headerinfo( $mailbox, (int) $msg_no );

			// Extract sender.
			$sender_email = '';
			$sender_name  = '';
			if ( ! empty( $header->from ) ) {
				$sender_email = isset( $header->from[0]->mailbox, $header->from[0]->host )
					? strtolower( $header->from[0]->mailbox . '@' . $header->from[0]->host )
					: '';
				$sender_name = isset( $header->from[0]->personal )
					? self::decode_mime_header( $header->from[0]->personal )
					: '';
			}

			$subject = isset( $header->subject )
				? self::decode_mime_header( $header->subject )
				: '';

			// Fetch plain-text body (section 1). Falls back to full body.
			$body = imap_fetchbody( $mailbox, (int) $msg_no, '1' );
			if ( empty( trim( $body ) ) ) {
				$body = imap_body( $mailbox, (int) $msg_no );
			}
			$body = self::decode_quoted_printable( $body );

			$tool      = new WP_MCP_AI_Tool_Evaluate_Inbound_Message();
			$arguments = array(
				'channel'         => 'email',
				'message_body'    => $body,
				'message_subject' => $subject,
				'sender_email'    => $sender_email,
				'sender_name'     => $sender_name,
				'source'          => 'imap_poll',
			);
			$tool->execute( $arguments, array( 'user_id' => 0 ) );

			// Mark as seen so it isn't re-processed on next poll.
			imap_setflag_full( $mailbox, (string) $msg_no, '\\Seen' );
		}

		imap_close( $mailbox );
	}

	/**
	 * Decode an RFC 2047 MIME-encoded header value.
	 *
	 * @param string $value Raw header value.
	 * @return string Decoded value.
	 */
	private static function decode_mime_header( $value ) {
		$decoded = imap_mime_header_decode( $value );
		if ( ! is_array( $decoded ) ) {
			return $value;
		}
		$out = '';
		foreach ( $decoded as $part ) {
			$out .= $part->text;
		}
		return $out;
	}

	/**
	 * Decode quoted-printable content.
	 *
	 * @param string $text Quoted-printable encoded text.
	 * @return string Decoded text.
	 */
	private static function decode_quoted_printable( $text ) {
		return quoted_printable_decode( $text );
	}
}

// Register the handler with the Schedule Manager.
add_action( self::JOB_HOOK, array( 'WP_MCP_AI_CRM_IMAP_Listener', 'poll' ) );
