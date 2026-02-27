<?php
/**
 * Telegram Bot API Webhook Controller
 *
 * Handles incoming Telegram bot webhook events with industry-standard security
 * validation. Implements Telegram Bot API best practices:
 * - Optional secret-token header verification (X-Telegram-Bot-Api-Secret-Token)
 * - Per-user conversation history respecting max_history_messages
 * - AI auto-reply via WordPress cron (async, no timeout risk)
 * - Message deduplication via transient cache
 *
 * @see https://core.telegram.org/bots/api#setwebhook
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
 * Telegram webhook REST controller.
 */
class WP_MCP_AI_Telegram_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/telegram';

	/**
	 * Cron hook for dispatching AI replies to incoming Telegram messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_telegram_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient used to prevent
	 * double-processing the same update_id.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Telegram message text length limit enforced before sending a reply.
	 */
	const MAX_MESSAGE_LENGTH = 4096;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_telegram_reply_job' ) );
	}

	/**
	 * Register REST routes for Telegram webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Webhook event receiver endpoint (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_secret' ),
			)
		);
	}

	/**
	 * Validate incoming webhook request using the optional secret token.
	 *
	 * Telegram sends the secret token set via setWebhook in the
	 * X-Telegram-Bot-Api-Secret-Token header. When no secret token is
	 * configured on the connection the webhook is allowed through with a
	 * security warning so that incoming messages can still be processed.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the secret token is valid or not configured.
	 */
	public function validate_webhook_secret( $request ) {
		$stored_secret = $this->get_secret_token();

		if ( empty( $stored_secret ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_webhook_no_secret_token',
				'Telegram webhook received without secret token configured. Header validation skipped. Configure a secret_token in the connection settings for enhanced security.',
				array()
			);
			return true;
		}

		$provided_token = $request->get_header( 'x-telegram-bot-api-secret-token' );

		if ( empty( $provided_token ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram webhook rejected: missing secret token header.' );
			return false;
		}

		if ( ! hash_equals( $stored_secret, $provided_token ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram webhook rejected: invalid secret token.' );
			return false;
		}

		return true;
	}

	/**
	 * Handle incoming Telegram webhook update.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object (always 200 to prevent Telegram retries).
	 */
	public function handle_webhook( $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram webhook received with empty or invalid payload.' );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$update_id = isset( $payload['update_id'] ) ? absint( $payload['update_id'] ) : 0;

		WP_MCP_AI_Logger::log_event(
			'telegram_webhook_received',
			'Telegram webhook update received.',
			array( 'update_id' => $update_id )
		);

		// Deduplicate: skip updates we have already processed.
		if ( $update_id && $this->is_duplicate_update( $update_id ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_webhook_duplicate',
				'Telegram update already processed; skipping.',
				array( 'update_id' => $update_id )
			);
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// Mark as processed.
		if ( $update_id ) {
			set_transient( 'wp_mcp_ai_tg_dedup_' . $update_id, 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Handle text message updates.
		if ( isset( $payload['message'] ) && is_array( $payload['message'] ) ) {
			$this->process_message( $payload['message'] );
		}

		// Always return 200 so Telegram does not retry.
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Process a Telegram message object and dispatch an AI reply if applicable.
	 *
	 * Mirrors the WhatsApp auto-reply logic: checks automation rules for human
	 * takeover / AI resume keywords, enforces the human takeover gate, falls back
	 * to the global default assistant when no per-connection assistant is assigned,
	 * and exposes a filter so site developers can override the decision.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message Telegram message object.
	 */
	protected function process_message( array $message ) {
		if ( empty( $message['text'] ) ) {
			// Non-text messages (photos, stickers, etc.) are not handled.
			return;
		}

		$text    = (string) $message['text'];
		$chat_id = isset( $message['chat']['id'] ) ? (string) $message['chat']['id'] : '';
		$from_id = isset( $message['from']['id'] ) ? (string) $message['from']['id'] : '';

		if ( '' === $chat_id ) {
			return;
		}

		// --- Automation keyword checks (mirrors WhatsApp maybe_auto_reply) ---
		$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
		$text_lower       = strtolower( $text );

		if ( ! empty( $automation_rules['human_takeover_keywords'] ) && '' !== $text_lower ) {
			$takeover_kws = array_map( 'trim', explode( ',', strtolower( $automation_rules['human_takeover_keywords'] ) ) );
			foreach ( $takeover_kws as $kw ) {
				if ( '' !== $kw && false !== strpos( $text_lower, $kw ) ) {
					if ( '' !== $from_id && class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
						$contact_id = $this->get_channel_contact_id( 'telegram', $from_id );
						if ( $contact_id ) {
							WP_MCP_AI_Channel_Contacts_CCT::set_human_takeover( $contact_id, true );
						}
					}
					WP_MCP_AI_Logger::log_event(
						'telegram_human_takeover_triggered',
						'Human takeover triggered by keyword.',
						array(
							'from_id' => substr( $from_id, 0, 4 ) . '***',
							'keyword' => $kw,
						)
					);
					return; // Do not auto-reply; human agent will respond.
				}
			}
		}

		if ( ! empty( $automation_rules['ai_resume_keywords'] ) && '' !== $text_lower ) {
			$resume_kws = array_map( 'trim', explode( ',', strtolower( $automation_rules['ai_resume_keywords'] ) ) );
			foreach ( $resume_kws as $kw ) {
				if ( '' !== $kw && false !== strpos( $text_lower, $kw ) ) {
					if ( '' !== $from_id && class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
						$contact_id = $this->get_channel_contact_id( 'telegram', $from_id );
						if ( $contact_id ) {
							WP_MCP_AI_Channel_Contacts_CCT::set_human_takeover( $contact_id, false );
						}
					}
					WP_MCP_AI_Logger::log_event(
						'telegram_ai_resumed',
						'AI auto-reply resumed by keyword.',
						array(
							'from_id' => substr( $from_id, 0, 4 ) . '***',
							'keyword' => $kw,
						)
					);
					break; // Continue and allow AI to reply.
				}
			}
		}

		// --- Human takeover gate ---
		if ( '' !== $from_id && class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			if ( WP_MCP_AI_Channel_Contacts_CCT::is_human_takeover_active( 'telegram', $from_id ) ) {
				WP_MCP_AI_Logger::log_event(
					'telegram_auto_reply_skipped_human_takeover',
					'Auto-reply skipped: human takeover is active for this contact.',
					array( 'from_id' => substr( $from_id, 0, 4 ) . '***' )
				);
				return;
			}
		}

		// Resolve the Telegram connection.
		$connection = $this->get_active_telegram_connection();

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram webhook: no active Telegram connection found.'
			);
			return;
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		// Fall back to the global default assistant from automation settings.
		if ( empty( $assigned_assistant_ids ) && ! empty( $automation_rules['default_assistant_id'] ) ) {
			$assigned_assistant_ids = array( absint( $automation_rules['default_assistant_id'] ) );
		}

		// Final fallback: use any published assistant so all messages get a reply.
		if ( empty( $assigned_assistant_ids ) ) {
			$any_id = $this->get_any_assistant_id();
			if ( $any_id ) {
				$assigned_assistant_ids = array( $any_id );
			}
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

		/**
		 * Filter whether to auto-reply to Telegram messages.
		 *
		 * Defaults to true when the connection has one or more assigned AI assistants
		 * or a global default assistant is configured in the automation rules.
		 *
		 * @since 1.0.0
		 *
		 * @param bool  $auto_reply       Whether to auto-reply.
		 * @param array $message          Telegram message object.
		 * @param array $automation_rules Saved automation rule settings.
		 */
		$should_reply = apply_filters( 'wp_mcp_ai_telegram_should_auto_reply', ! empty( $assigned_assistant_ids ), $message, $automation_rules );

		if ( ! $should_reply ) {
			return;
		}

		do_action( 'wp_mcp_ai_telegram_auto_reply', $message, $automation_rules, $assigned_assistant_ids );

		if ( ! empty( $assigned_assistant_ids ) ) {
			$tg_contact_id = '' !== $from_id ? $from_id : $chat_id;

			// Find or create the contact in the Channel Contacts CCT.
			if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
				$tg_contact_name = '';
				if ( isset( $message['from']['first_name'] ) ) {
					$tg_contact_name = trim( $message['from']['first_name'] . ' ' . ( isset( $message['from']['last_name'] ) ? $message['from']['last_name'] : '' ) );
				}
				$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
					'telegram',
					$tg_contact_id,
					array( 'display_name' => $tg_contact_name ? $tg_contact_name : $tg_contact_id )
				);
				if ( $contact_row_id ) {
					WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_row_id );
				}
			}

			// Persist inbound message to Channel Messages CCT.
			if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
				$tg_message_id = isset( $message['message_id'] ) ? (string) $message['message_id'] : '';
				WP_MCP_AI_Channel_Messages_CCT::insert(
					array(
						'channel'            => 'telegram',
						'channel_contact_id' => $tg_contact_id,
						'direction'          => 'inbound',
						'message_id'         => $tg_message_id,
						'message_type'       => 'text',
						'content'            => $text,
						'status'             => 'received',
						'connection_id'      => $connection_id,
						'phone_number_id'    => $chat_id,
						'timestamp'          => isset( $message['date'] ) ? absint( $message['date'] ) : time(),
						'reply_sent'         => 0,
						'assigned_agent'     => (string) $assigned_assistant_ids[0],
					)
				);
			}

			$job_args = array(
				array(
					'assistant_id'  => $assigned_assistant_ids[0],
					'message_text'  => $text,
					'chat_id'       => $chat_id,
					'from_id'       => '' !== $from_id ? $from_id : $chat_id,
					'connection_id' => $connection_id,
				),
			);

			wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
			spawn_cron();
		}
	}

	/**
	 * Cron callback: generate an AI reply and send it back via the Telegram Bot API.
	 *
	 * Implements per-user conversation history following the same pattern as the
	 * WhatsApp auto-reply handler (PR #3844), respecting the global
	 * max_history_messages setting and the wp_mcp_ai_telegram_max_history_messages
	 * filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by process_message().
	 */
	public function handle_telegram_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$chat_id       = isset( $args['chat_id'] ) ? (string) $args['chat_id'] : '';
		$from_id       = isset( $args['from_id'] ) ? (string) $args['from_id'] : $chat_id;
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';

		if ( ! $assistant_id || '' === $message_text || '' === $chat_id || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: connection not found or bot token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( '' === $bot_token ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: bot token decryption returned empty string.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// --- Per-user conversation history (mirrors PR #3844 for WhatsApp) ---
		$history_key = $this->get_conversation_history_key( $from_id, $connection_id );
		$history     = get_transient( $history_key );
		$history     = is_array( $history ) ? $history : array();

		$max_history = 8;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$max_history = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : $max_history;
		}

		/**
		 * Filters the maximum number of messages kept in a Telegram conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_telegram_max_history_messages', $max_history, $args );
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
				'Telegram AI reply: no administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Telegram AI reply: empty content from assistant.' );
			return;
		}

		// Enforce Telegram message length limit.
		if ( mb_strlen( $content ) > self::MAX_MESSAGE_LENGTH ) {
			$content = mb_substr( $content, 0, self::MAX_MESSAGE_LENGTH - 3 ) . '...';
		}

		// Send reply via Telegram Bot API.
		$endpoint = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $bot_token ) );

		$payload = array(
			'chat_id' => $chat_id,
			'text'    => $content,
		);

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			WP_MCP_AI_Logger::log_error( 'Telegram AI reply: failed to JSON-encode payload.' );
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'telegram_ai_reply_sending',
			'Sending Telegram AI reply.',
			array(
				'assistant_id' => $assistant_id,
				'chat_id'      => substr( $chat_id, 0, 4 ) . '***',
			)
		);

		$result = wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: HTTP request failed.',
				array( 'error' => $result->get_error_message() )
			);
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $result );

		if ( 200 !== $http_code ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: API returned non-200 status.',
				array( 'http_code' => $http_code )
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
			'telegram_ai_reply_sent',
			'Telegram AI reply sent successfully.',
			array(
				'assistant_id' => $assistant_id,
				'chat_id'      => substr( $chat_id, 0, 4 ) . '***',
			)
		);

		// Persist the outbound AI reply to the Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'telegram',
					'channel_contact_id' => $from_id,
					'direction'          => 'outbound',
					'message_type'       => 'text',
					'content'            => $content,
					'status'             => 'sent',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $chat_id,
					'timestamp'          => time(),
					'reply_sent'         => 1,
					'assigned_agent'     => (string) $assistant_id,
				)
			);
		}

		// Touch the contact record to update last_message_at.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$tg_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'telegram', $from_id );
			if ( $tg_contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $tg_contact_row_id );
			}
		}
	}

	/**
	 * Return the transient key used to store the conversation history for a
	 * specific Telegram sender on a specific connection.
	 *
	 * The key is hashed to avoid PII in option names and to stay within
	 * WordPress's 172-character transient key limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from_id       Sender's Telegram user/chat ID.
	 * @param string $connection_id Remote connection ID.
	 * @return string Transient key.
	 */
	protected function get_conversation_history_key( $from_id, $connection_id ) {
		return 'wp_mcp_ai_tg_conv_' . md5( $from_id . '_' . $connection_id );
	}

	/**
	 * Check whether an update_id has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param int $update_id Telegram update ID.
	 * @return bool True if this update was seen before.
	 */
	protected function is_duplicate_update( $update_id ) {
		return (bool) get_transient( 'wp_mcp_ai_tg_dedup_' . $update_id );
	}

	/**
	 * Retrieve the secret_token from the first active Telegram connection.
	 *
	 * @since 1.0.0
	 *
	 * @return string Secret token or empty string if not configured.
	 */
	protected function get_secret_token() {
		$connection = $this->get_active_telegram_connection();

		if ( ! $connection || empty( $connection['secret_token'] ) ) {
			return '';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['secret_token'] );
	}

	/**
	 * Find the first active (enabled) Telegram connection.
	 *
	 * Unlike the previous implementation, this no longer requires
	 * assigned_assistant_ids to be set on the connection so that the global
	 * default_assistant_id from the automation rules can serve as a fallback
	 * (mirroring the WhatsApp auto-reply behaviour).
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_telegram_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'telegram' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			return $connection;
		}

		return null;
	}

	/**
	 * Look up the channel contact record ID for the given Telegram user.
	 *
	 * Used to set/clear the human takeover flag on per-contact records stored
	 * in the WP_MCP_AI_Channel_Contacts_CCT table (mirrors the identical helper
	 * in the WhatsApp webhook controller).
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel            Platform slug ('telegram').
	 * @param string $channel_contact_id Platform-level contact identifier (Telegram user ID).
	 * @return int|null Contact record ID or null if not found.
	 */
	protected function get_channel_contact_id( $channel, $channel_contact_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) || ! WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			return null;
		}

		global $wpdb;
		$table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT _ID FROM {$table} WHERE channel = %s AND channel_contact_id = %s LIMIT 1",
				sanitize_key( $channel ),
				sanitize_text_field( $channel_contact_id )
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Extract the plain-text reply from the internal /mcp-ai/v1/chat response.
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
	/**
	 * Return the ID of any published AI assistant as a last-resort fallback.
	 *
	 * When no assistant is explicitly assigned to a connection and no global
	 * default_assistant_id is configured in the automation rules, this helper
	 * queries for the first published mcp_ai_assistant post so that incoming
	 * messages always receive a reply rather than being silently dropped.
	 *
	 * @since 1.0.0
	 *
	 * @return int Assistant post ID, or 0 if none exist.
	 */
	protected function get_any_assistant_id() {
		$posts = get_posts(
			array(
				'post_type'              => 'mcp_ai_assistant',
				'post_status'            => 'publish',
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}
}

new WP_MCP_AI_Telegram_Webhook_Controller();
