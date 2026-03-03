<?php
/**
 * Slack Events API Controller
 *
 * Handles incoming Slack Events API webhook payloads with industry-standard
 * security validation. Implements Slack platform best practices:
 * - HMAC-SHA256 signature verification (X-Slack-Signature header)
 * - URL verification challenge response
 * - Per-user conversation history respecting max_history_messages
 * - AI auto-reply via WordPress cron (async, within 3-second acknowledgement window)
 * - Message deduplication via transient cache
 *
 * @see https://api.slack.com/events-api
 * @see https://api.slack.com/authentication/verifying-requests-from-slack
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

// Load channel CCT helpers when available.
$_cc_messages_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-channel-messages-cct.php';
$_cc_contacts_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-channel-contacts-cct.php';
if ( file_exists( $_cc_messages_file ) && ! class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
	require_once $_cc_messages_file;
}
if ( file_exists( $_cc_contacts_file ) && ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
	require_once $_cc_contacts_file;
}
unset( $_cc_messages_file, $_cc_contacts_file );

/**
 * Slack Events API REST controller.
 */
class WP_MCP_AI_Slack_Event_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai/v1';

	/**
	 * REST API endpoint base.
	 *
	 * @var string
	 */
	protected $rest_base = 'webhooks/slack';

	/**
	 * Cron hook for dispatching AI replies to incoming Slack messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_slack_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Maximum age in seconds for an incoming Slack request timestamp (5 minutes).
	 * Requests older than this are rejected to prevent replay attacks.
	 */
	const MAX_REQUEST_AGE = 300;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_slack_reply_job' ) );
	}

	/**
	 * Register REST routes for Slack webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Single endpoint handles both URL-verification challenge and event payloads.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_event' ),
				'permission_callback' => array( $this, 'validate_slack_signature' ),
			)
		);
	}

	/**
	 * Validate the Slack request signature using HMAC-SHA256.
	 *
	 * Slack signs every request with the signing secret from the App dashboard.
	 * The signature is computed as:
	 *   "v0=" . HMAC-SHA256( "v0:{timestamp}:{raw_body}", signing_secret )
	 * and sent in the X-Slack-Signature header.
	 *
	 * When the signing secret is not configured the webhook is allowed through
	 * with a security warning so that the URL-verification step can still be
	 * completed.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if signature is valid or signing secret is not configured.
	 */
	public function validate_slack_signature( $request ) {
		$signing_secret = $this->get_signing_secret();

		if ( empty( $signing_secret ) ) {
			WP_MCP_AI_Logger::log_event(
				'slack_webhook_no_signing_secret',
				'Slack webhook received without signing secret configured. Signature validation skipped. Configure signing_secret in the connection settings for enhanced security.',
				array()
			);
			return true;
		}

		$timestamp = $request->get_header( 'x-slack-request-timestamp' );
		$signature = $request->get_header( 'x-slack-signature' );

		if ( empty( $timestamp ) || empty( $signature ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook rejected: missing timestamp or signature header.' );
			return false;
		}

		// Reject requests older than MAX_REQUEST_AGE seconds (replay-attack prevention).
		if ( abs( time() - (int) $timestamp ) > self::MAX_REQUEST_AGE ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook rejected: request timestamp is stale.' );
			return false;
		}

		$raw_body = $request->get_body();
		$sig_base = 'v0:' . $timestamp . ':' . $raw_body;
		$computed = 'v0=' . hash_hmac( 'sha256', $sig_base, $signing_secret );

		if ( ! hash_equals( $computed, $signature ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook rejected: invalid signature.' );
			return false;
		}

		return true;
	}

	/**
	 * Handle incoming Slack event or URL-verification challenge.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_event( $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook: empty or invalid JSON payload.' );
			return rest_ensure_response( array() );
		}

		$type = isset( $payload['type'] ) ? sanitize_text_field( $payload['type'] ) : '';

		// Respond to Slack URL-verification challenge immediately.
		if ( 'url_verification' === $type ) {
			$challenge = isset( $payload['challenge'] ) ? sanitize_text_field( $payload['challenge'] ) : '';
			WP_MCP_AI_Logger::log_event( 'slack_webhook_url_verification', 'Slack URL verification challenge answered.' );
			return rest_ensure_response( array( 'challenge' => $challenge ) );
		}

		if ( 'event_callback' !== $type ) {
			return rest_ensure_response( array() );
		}

		$event_id = isset( $payload['event_id'] ) ? sanitize_text_field( $payload['event_id'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'slack_webhook_event_received',
			'Slack event callback received.',
			array( 'event_id' => $event_id )
		);

		// Deduplicate Slack event delivery.
		if ( $event_id && $this->is_duplicate_event( $event_id ) ) {
			return rest_ensure_response( array() );
		}

		if ( $event_id ) {
			set_transient( 'wp_mcp_ai_sl_dedup_' . md5( $event_id ), 1, self::DEDUP_TRANSIENT_TTL );
		}

		$event = isset( $payload['event'] ) && is_array( $payload['event'] ) ? $payload['event'] : array();
		$this->process_event( $event );

		// Acknowledge within 3 seconds; actual reply is sent asynchronously.
		return rest_ensure_response( array() );
	}

	/**
	 * Process a Slack event object.
	 *
	 * Only handles non-bot text messages (`message` type without a subtype).
	 *
	 * @since 1.0.0
	 *
	 * @param array $event Slack event object.
	 */
	protected function process_event( array $event ) {
		$event_type = isset( $event['type'] ) ? $event['type'] : '';

		// Only handle plain user messages, not bot messages or message edits.
		if ( 'message' !== $event_type ) {
			return;
		}

		// Skip bot messages and subtypes (edits, deletions, etc.).
		if ( isset( $event['bot_id'] ) || isset( $event['subtype'] ) ) {
			return;
		}

		$text       = isset( $event['text'] ) ? (string) $event['text'] : '';
		$user_id    = isset( $event['user'] ) ? (string) $event['user'] : '';
		$channel_id = isset( $event['channel'] ) ? (string) $event['channel'] : '';

		if ( '' === $text || '' === $user_id || '' === $channel_id ) {
			return;
		}

		$connection = $this->get_active_slack_connection();

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook: no active Slack connection with assigned assistants found.' );
			return;
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) )
			: array();

		if ( empty( $assigned_assistant_ids ) ) {
			return;
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return;
		}

		// When the connection requires an @slug mention, only reply if the message
		// explicitly addresses an assigned assistant by its WordPress post slug.
		if ( ! empty( $connection['require_mention'] ) && ! $this->message_mentions_assistant( $text, $assigned_assistant_ids ) ) {
			return;
		}

		// Find or create the contact in the Channel Contacts CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'slack',
				$user_id,
				array( 'display_name' => $user_id )
			);
			if ( $contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_row_id );
			}
		}

		// Persist inbound message to Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'slack',
					'channel_contact_id' => $user_id,
					'direction'          => 'inbound',
					'message_id'         => isset( $event['event_ts'] ) ? sanitize_text_field( $event['event_ts'] ) : '',
					'message_type'       => 'text',
					'content'            => $text,
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $channel_id,
					'timestamp'          => isset( $event['ts'] ) ? (int) $event['ts'] : time(),
					'reply_sent'         => 0,
					'assigned_agent'     => (string) $assigned_assistant_ids[0],
				)
			);
		}

		$job_args = array(
			array(
				'assistant_id'  => $assigned_assistant_ids[0],
				'message_text'  => $text,
				'user_id'       => $user_id,
				'channel_id'    => $channel_id,
				'connection_id' => $connection_id,
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();
	}

	/**
	 * Cron callback: generate an AI reply and post it to Slack via chat.postMessage.
	 *
	 * Implements per-user conversation history following the same pattern as the
	 * WhatsApp auto-reply handler (PR #3844), respecting the global
	 * max_history_messages setting and the wp_mcp_ai_slack_max_history_messages filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by process_event().
	 */
	public function handle_slack_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$user_id       = isset( $args['user_id'] ) ? (string) $args['user_id'] : '';
		$channel_id    = isset( $args['channel_id'] ) ? (string) $args['channel_id'] : '';
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';

		if ( ! $assistant_id || '' === $message_text || '' === $channel_id || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: connection not found or bot token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( '' === $bot_token ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: bot token decryption returned empty string.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// --- Per-user conversation history (mirrors PR #3844 for WhatsApp) ---
		$history_key = $this->get_conversation_history_key( $user_id, $channel_id, $connection_id );
		$history     = get_transient( $history_key );
		$history     = is_array( $history ) ? $history : array();

		$max_history = 8;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$max_history = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : $max_history;
		}

		/**
		 * Filters the maximum number of messages kept in a Slack conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_slack_max_history_messages', $max_history, $args );
		$max_history = max( 1, $max_history );

		if ( count( $history ) >= $max_history ) {
			$history = array_slice( $history, -( $max_history - 1 ) );
		}

		$messages = array_merge(
			$history,
			array(
				array(
					'role'    => 'user',
					'content' => $message_text,
				),
			)
		);
		// --- End conversation history ---

		// Call the internal chat REST endpoint.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_body_params(
			array(
				'assistant_id' => $assistant_id,
				'messages'     => $messages,
				'stream'       => false,
			)
		);

		$original_user_id = get_current_user_id();
		$admin_users      = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( ! empty( $admin_users ) ) {
			wp_set_current_user( $admin_users[0] );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		} else {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: no administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Slack AI reply: empty content from assistant.' );
			return;
		}

		// Post reply to Slack via chat.postMessage.
		$payload = array(
			'channel' => $channel_id,
			'text'    => $content,
		);

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			WP_MCP_AI_Logger::log_error( 'Slack AI reply: failed to JSON-encode payload.' );
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'slack_ai_reply_sending',
			'Sending Slack AI reply.',
			array(
				'assistant_id' => $assistant_id,
				'channel_id'   => $channel_id,
			)
		);

		$result = wp_remote_post(
			'https://slack.com/api/chat.postMessage',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $bot_token,
				),
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: HTTP request failed.',
				array( 'error' => $result->get_error_message() )
			);
			return;
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $result );
		$decoded_body = json_decode( wp_remote_retrieve_body( $result ), true );
		$api_ok       = is_array( $decoded_body ) && ! empty( $decoded_body['ok'] );

		if ( 200 !== $http_code || ! $api_ok ) {
			$api_error = is_array( $decoded_body ) && isset( $decoded_body['error'] ) ? $decoded_body['error'] : '';
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: API returned an error.',
				array(
					'http_code' => $http_code,
					'api_error' => $api_error,
				)
			);
			return;
		}

		// Persist updated conversation history.
		$history[] = array(
			'role'    => 'user',
			'content' => $message_text,
		);
		$history[] = array(
			'role'    => 'assistant',
			'content' => $content,
		);
		if ( count( $history ) > $max_history ) {
			$history = array_slice( $history, -$max_history );
		}
		set_transient( $history_key, $history, self::CONVERSATION_HISTORY_TTL );

		WP_MCP_AI_Logger::log_event(
			'slack_ai_reply_sent',
			'Slack AI reply sent successfully.',
			array(
				'assistant_id' => $assistant_id,
				'channel_id'   => $channel_id,
			)
		);

		// Persist the outbound AI reply to the Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'slack',
					'channel_contact_id' => $user_id,
					'direction'          => 'outbound',
					'message_type'       => 'text',
					'content'            => $content,
					'status'             => 'sent',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $channel_id,
					'timestamp'          => time(),
					'reply_sent'         => 1,
					'assigned_agent'     => (string) $assistant_id,
				)
			);
		}

		// Touch the contact record to update last_message_at.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$sl_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'slack', $user_id );
			if ( $sl_contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $sl_contact_row_id );
			}
		}
	}

	/**
	 * Return the transient key for a Slack user/channel conversation history.
	 *
	 * The key is hashed to avoid PII in option names and stay within WordPress's
	 * 172-character transient key limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $user_id       Slack user ID.
	 * @param string $channel_id    Slack channel ID.
	 * @param string $connection_id Remote connection ID.
	 * @return string Transient key.
	 */
	protected function get_conversation_history_key( $user_id, $channel_id, $connection_id ) {
		return 'wp_mcp_ai_sl_conv_' . md5( $user_id . '_' . $channel_id . '_' . $connection_id );
	}

	/**
	 * Check whether an event_id has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_id Slack event ID.
	 * @return bool True if this event was seen before.
	 */
	protected function is_duplicate_event( $event_id ) {
		return (bool) get_transient( 'wp_mcp_ai_sl_dedup_' . md5( $event_id ) );
	}

	/**
	 * Retrieve the HMAC signing secret from the first active Slack connection.
	 *
	 * Checks `signing_secret` first (populated by the updated admin form).
	 * Falls back to `api_secret` for legacy connections saved before the admin
	 * form was updated to write the correct field.
	 *
	 * @since 1.0.0
	 *
	 * @return string Signing secret or empty string if not configured.
	 */
	protected function get_signing_secret() {
		$connection = $this->get_active_slack_connection();

		if ( ! $connection ) {
			return '';
		}

		// Prefer the canonical field; fall back to api_secret for legacy connections.
		$secret = ! empty( $connection['signing_secret'] ) ? $connection['signing_secret']
			: ( ! empty( $connection['api_secret'] ) ? $connection['api_secret'] : '' );

		if ( '' === $secret ) {
			return '';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $secret );
	}

	/**
	 * Find the first active Slack connection with assigned assistants.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_slack_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'slack' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( empty( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
				continue;
			}

			return $connection;
		}

		return null;
	}

	/**
	 * Extract plain-text content from the internal /mcp-ai/v1/chat response.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Response data from the chat endpoint.
	 * @return string Plain-text content or empty string.
	 */
	protected function extract_content_from_chat_response( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$choices = isset( $data['data']['choices'] ) ? $data['data']['choices']
			: ( isset( $data['choices'] ) ? $data['choices'] : array() );

		if ( ! is_array( $choices ) || empty( $choices ) ) {
			return '';
		}

		$first = reset( $choices );

		if ( isset( $first['message']['content'] ) && is_string( $first['message']['content'] ) ) {
			return trim( $first['message']['content'] );
		}

		return '';
	}

	/**
	 * Check whether any assigned assistant is mentioned by @slug in the message text.
	 *
	 * Used when a connection has require_mention enabled so the bot only replies
	 * when a user explicitly addresses it with @assistant-slug in a group chat.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message_text  The incoming message text.
	 * @param int[]  $assistant_ids Array of assigned assistant post IDs.
	 * @return bool True if any assistant slug is found as @slug in the text.
	 */
	protected function message_mentions_assistant( $message_text, array $assistant_ids ) {
		if ( '' === $message_text ) {
			return false;
		}
		foreach ( $assistant_ids as $assistant_id ) {
			$slug = get_post_field( 'post_name', absint( $assistant_id ) );
			if ( is_string( $slug ) && '' !== $slug && preg_match( '/@' . preg_quote( $slug, '/' ) . '(?:[^a-zA-Z0-9-]|$)/i', $message_text ) ) {
				return true;
			}
		}
		return false;
	}
}

new WP_MCP_AI_Slack_Event_Controller();
