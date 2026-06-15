<?php
/**
 * CRM IMAP Polling Listener
 *
 * Periodically polls an IMAP inbox and routes new messages to the CRM
 * inbound evaluation pipeline. Uses the Schedule Manager for cron scheduling.
 *
 * Primary driver: WP_MCP_AI_CRM_IMAP_Client (pure PHP, no extension needed).
 * Fallback: PHP ext-imap (imap_open / imap_* functions) when available.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 * @since  2.4.0 Added pure PHP IMAP client as primary driver; ext-imap is now fallback.
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
 * @since 2.3.0
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
	 * Uses the pure PHP IMAP client (WP_MCP_AI_CRM_IMAP_Client) as the
	 * primary driver. Falls back to ext-imap when available and the pure
	 * client is not loaded.
	 *
	 * @since 2.3.0
	 * @since 2.4.0 Pure PHP primary driver with ext-imap fallback.
	 */
	public static function poll() {
		$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();

		// Connection parameters supplied via filters.
		// Format example: {imap.gmail.com:993/imap/ssl}INBOX.
		$conn_string = apply_filters( 'wp_mcp_ai_crm_imap_connection_string', '', $settings );
		$imap_user   = apply_filters( 'wp_mcp_ai_crm_imap_username', '', $settings );
		$imap_pass   = apply_filters( 'wp_mcp_ai_crm_imap_password', '', $settings );

		if ( empty( $conn_string ) || empty( $imap_user ) ) {
			return;
		}

		// Load the evaluate_inbound_message tool early.
		$_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-evaluate-inbound-message.php';
		if ( ! file_exists( $_tool_file ) ) {
			return;
		}
		require_once $_tool_file;
		if ( ! class_exists( 'WP_MCP_AI_Tool_Evaluate_Inbound_Message' ) ) {
			return;
		}

		// ---- Primary driver: Pure PHP IMAP client ----
		$_client_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-crm-imap-client.php';
		if ( file_exists( $_client_file ) ) {
			require_once $_client_file;
			if ( class_exists( 'WP_MCP_AI_CRM_IMAP_Client' ) ) {
				self::poll_with_pure_php( $conn_string, $imap_user, $imap_pass );
				return;
			}
		}

		// ---- Fallback: PHP ext-imap functions ----
		if ( function_exists( 'imap_open' ) ) {
			self::poll_with_ext_imap( $conn_string, $imap_user, $imap_pass );
			return;
		}

		// Neither driver is available — log and bail.
		if ( function_exists( 'WP_MCP_AI_Logger' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error(
				'CRM IMAP polling skipped: no IMAP driver available.',
				array( 'conn_string' => self::mask_conn_string( $conn_string ) )
			);
		}
	}

	/**
	 * Poll using the pure PHP IMAP client.
	 *
	 * @since 2.4.0
	 *
	 * @param string $conn_string IMAP connection string.
	 * @param string $imap_user   IMAP username.
	 * @param string $imap_pass   IMAP password.
	 */
	private static function poll_with_pure_php( $conn_string, $imap_user, $imap_pass ) {
		$client = new WP_MCP_AI_CRM_IMAP_Client( $conn_string, $imap_user, $imap_pass );

		if ( ! $client->open() ) {
			return;
		}

		// Select the mailbox specified in the connection string.
		$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$mailbox  = apply_filters( 'wp_mcp_ai_crm_imap_mailbox', 'INBOX', $settings );
		if ( ! $client->select( $mailbox ) ) {
			$client->close();
			return;
		}

		// Search for unseen messages.
		$message_nos = $client->search( 'UNSEEN' );
		if ( empty( $message_nos ) ) {
			$client->close();
			return;
		}

		foreach ( $message_nos as $msg_no ) {
			$header = $client->fetch_header( $msg_no );
			if ( ! $header ) {
				continue;
			}

			// Fetch plain-text body (section 1). Falls back to full body.
			$body = $client->fetch_body( $msg_no, '1' );
			if ( empty( trim( $body ) ) ) {
				$body = $client->fetch_body( $msg_no, '' );
			}

			$tool      = new WP_MCP_AI_Tool_Evaluate_Inbound_Message();
			$arguments = array(
				'channel'         => 'email',
				'message_body'    => $body,
				'message_subject' => $header['subject'],
				'sender_email'    => $header['from_email'],
				'sender_name'     => $header['from_name'],
				'source'          => 'imap_poll',
			);
			$tool->execute( $arguments, array( 'user_id' => 0 ) );

			// Mark as seen so it isn't re-processed on next poll.
			$client->mark_seen( $msg_no );
		}

		$client->close();
	}

	/**
	 * Poll using the PHP ext-imap extension (legacy fallback).
	 *
	 * @since 2.3.0
	 *
	 * @param string $conn_string IMAP connection string.
	 * @param string $imap_user   IMAP username.
	 * @param string $imap_pass   IMAP password.
	 */
	private static function poll_with_ext_imap( $conn_string, $imap_user, $imap_pass ) {
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

		foreach ( $message_nos as $msg_no ) {
			$header = imap_headerinfo( $mailbox, (int) $msg_no );

			// Extract sender.
			$sender_email = '';
			$sender_name  = '';
			if ( ! empty( $header->from ) ) {
				$sender_email = isset( $header->from[0]->mailbox, $header->from[0]->host )
					? strtolower( $header->from[0]->mailbox . '@' . $header->from[0]->host )
					: '';
				$sender_name  = isset( $header->from[0]->personal )
					? self::decode_mime_header_ext( $header->from[0]->personal )
					: '';
			}

			$subject = isset( $header->subject )
				? self::decode_mime_header_ext( $header->subject )
				: '';

			// Fetch plain-text body (section 1). Falls back to full body.
			$body = imap_fetchbody( $mailbox, (int) $msg_no, '1' );
			if ( empty( trim( $body ) ) ) {
				$body = imap_body( $mailbox, (int) $msg_no );
			}
			$body = quoted_printable_decode( $body );

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
	 * Decode an RFC 2047 MIME-encoded header value (ext-imap version).
	 *
	 * @param string $value Raw header value.
	 * @return string Decoded value.
	 */
	private static function decode_mime_header_ext( $value ) {
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
	 * Mask the connection string for safe logging (hide credentials).
	 *
	 * @param string $conn_string Raw connection string.
	 * @return string Masked string.
	 */
	private static function mask_conn_string( $conn_string ) {
		if ( preg_match( '/\{([^}]+)\}/', $conn_string, $m ) ) {
			return '{' . $m[1] . '}***';
		}
		return '***';
	}
}

// Register the handler with the Schedule Manager.
add_action( WP_MCP_AI_CRM_IMAP_Listener::JOB_HOOK, array( 'WP_MCP_AI_CRM_IMAP_Listener', 'poll' ) );
