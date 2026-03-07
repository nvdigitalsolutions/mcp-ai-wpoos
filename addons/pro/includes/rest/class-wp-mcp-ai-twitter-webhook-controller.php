<?php
/**
 * Twitter/X Account Activity API Webhook Controller
 *
 * Handles incoming Twitter/X webhook events following the Account Activity API
 * best practices:
 * - CRC (Challenge-Response Check) validation via GET request
 * - HMAC-SHA256 signature verification via X-Twitter-Webhooks-Signature header
 * - Direct Message event handling with per-user conversation history
 * - Async AI auto-reply via WordPress cron
 * - Message deduplication
 *
 * Key difference from WhatsApp:
 * - CRC challenge: GET with ?crc_token=… → respond with JSON
 *   {"response_token":"sha256=BASE64(HMAC-SHA256(consumer_secret, crc_token))"}
 * - Signature header: X-Twitter-Webhooks-Signature: sha256=BASE64(HMAC-SHA256(consumer_secret, body))
 *   (base64-encoded, not hex-encoded like WhatsApp)
 *
 * @see https://developer.twitter.com/en/docs/twitter-api/enterprise/account-activity-api/guides/securing-webhooks
 * @see https://developer.twitter.com/en/docs/twitter-api/direct-messages/api-reference
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
 * Twitter/X webhook REST controller.
 */
class WP_MCP_AI_Twitter_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/twitter';

	/**
	 * Cron hook for dispatching AI replies to incoming Twitter DMs.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_twitter_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Maximum DM text length enforced before sending a reply.
	 * Twitter DMs support up to 10,000 characters via API v2.
	 */
	const MAX_DM_LENGTH = 10000;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_twitter_reply_job' ) );
	}

	/**
	 * Register REST routes for Twitter/X webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// CRC challenge endpoint (GET) — Twitter sends ?crc_token=… to verify the URL.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_crc_challenge' ),
				'permission_callback' => '__return_true', // Public: Twitter must reach it without auth.
				'args'                => array(
					'crc_token' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Webhook event receiver endpoint (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_signature' ),
			)
		);

		// Channel-specific CRC endpoint (GET) — per-connection routing.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_crc_challenge' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'crc_token'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'connection_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Channel-specific webhook event receiver endpoint (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_signature' ),
			)
		);
	}

	/**
	 * Handle Twitter CRC (Challenge-Response Check) verification.
	 *
	 * Twitter sends GET ?crc_token=<token> to verify the webhook URL is valid
	 * and belongs to the registered app. The response must be JSON:
	 * {"response_token":"sha256=<base64(HMAC-SHA256(consumer_secret, crc_token))>"}
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_crc_challenge( $request ) {
		$crc_token     = $request->get_param( 'crc_token' );
		$connection_id = $request->get_param( 'connection_id' );

		if ( empty( $crc_token ) ) {
			return new WP_Error(
				'twitter_crc_missing_token',
				__( 'Twitter CRC challenge missing crc_token parameter.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'twitter_crc_challenge_received',
			'Twitter CRC challenge request received.',
			array(
				'connection_id' => $connection_id ? sanitize_key( $connection_id ) : 'generic',
				'token_prefix'  => substr( $crc_token, 0, 4 ) . '***',
			)
		);

		$consumer_secret = $this->get_consumer_secret( $connection_id ? sanitize_key( $connection_id ) : '' );

		if ( empty( $consumer_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'Twitter CRC challenge failed: no consumer secret configured.',
				array( 'connection_id' => $connection_id ? sanitize_key( $connection_id ) : 'generic' )
			);
			return new WP_Error(
				'twitter_no_consumer_secret',
				__( 'Twitter consumer secret not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		// Build the HMAC-SHA256 response token (base64-encoded, not hex).
		$response_token = 'sha256=' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			hash_hmac( 'sha256', $crc_token, $consumer_secret, true )
		);

		WP_MCP_AI_Logger::log_event(
			'twitter_crc_challenge_responded',
			'Twitter CRC challenge answered successfully.'
		);

		return new WP_REST_Response(
			array( 'response_token' => $response_token ),
			200
		);
	}

	/**
	 * Validate webhook signature using HMAC-SHA256.
	 *
	 * Twitter sends the signature in the X-Twitter-Webhooks-Signature header as:
	 *   sha256=<base64(HMAC-SHA256(consumer_secret, raw_body))>
	 *
	 * When the consumer secret is not configured, the webhook is allowed through
	 * with a security warning so messages can still be processed.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function validate_webhook_signature( $request ) {
		$connection_id   = $request->get_param( 'connection_id' );
		$consumer_secret = $this->get_consumer_secret( $connection_id ? sanitize_key( $connection_id ) : '' );

		if ( empty( $consumer_secret ) ) {
			WP_MCP_AI_Logger::log_event(
				'twitter_webhook_no_consumer_secret',
				'Twitter webhook received without consumer secret configured. Signature validation skipped. Configure your API Secret in the connection settings for enhanced security.',
				array()
			);
			return true;
		}

		$signature_header = $request->get_header( 'x-twitter-webhooks-signature' );

		if ( empty( $signature_header ) ) {
			WP_MCP_AI_Logger::log_error( 'Twitter webhook rejected: missing X-Twitter-Webhooks-Signature header.' );
			return false;
		}

		$payload            = $request->get_body();
		$expected_signature = 'sha256=' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			hash_hmac( 'sha256', $payload, $consumer_secret, true )
		);

		if ( ! hash_equals( $expected_signature, $signature_header ) ) {
			WP_MCP_AI_Logger::log_error(
				'Twitter webhook rejected: invalid signature.',
				array(
					'header_present' => ! empty( $signature_header ),
					'secret_present' => ! empty( $consumer_secret ),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Handle incoming Twitter/X webhook events.
	 *
	 * Twitter sends a JSON payload containing event arrays such as
	 * direct_message_events, tweet_create_events, follow_events, etc.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Twitter webhook received with empty or invalid payload.' );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		WP_MCP_AI_Logger::log_event(
			'twitter_webhook_received',
			'Twitter webhook event received.',
			array(
				'has_dm_events' => isset( $payload['direct_message_events'] ),
				'for_user_id'   => isset( $payload['for_user_id'] ) ? $payload['for_user_id'] : 'unknown',
			)
		);

		// Process Direct Message events.
		if ( ! empty( $payload['direct_message_events'] ) && is_array( $payload['direct_message_events'] ) ) {
			$connection_id = $request->get_param( 'connection_id' );
			$for_user_id   = isset( $payload['for_user_id'] ) ? sanitize_text_field( $payload['for_user_id'] ) : '';

			foreach ( $payload['direct_message_events'] as $dm_event ) {
				$this->process_dm_event( $dm_event, $for_user_id, $connection_id ? sanitize_key( $connection_id ) : '' );
			}
		}

		/**
		 * Fires when a Twitter/X webhook event payload is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $payload Full webhook payload.
		 */
		do_action( 'wp_mcp_ai_twitter_webhook_received', $payload );

		// Always return 200 to prevent Twitter from disabling the webhook.
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Process a single Direct Message event.
	 *
	 * DM events have type "message_create". The bot should only reply to
	 * messages it did not send itself (sender_id must differ from for_user_id).
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event         DM event object from the webhook payload.
	 * @param string $for_user_id   Twitter user ID of the authenticated app user.
	 * @param string $connection_id Connection ID from the URL route (may be empty).
	 */
	protected function process_dm_event( array $event, $for_user_id, $connection_id ) {
		$event_type = isset( $event['type'] ) ? $event['type'] : '';

		if ( 'message_create' !== $event_type ) {
			return;
		}

		$message_create = isset( $event['message_create'] ) ? $event['message_create'] : array();
		$sender_id      = isset( $message_create['sender_id'] ) ? sanitize_text_field( $message_create['sender_id'] ) : '';
		$message_text   = isset( $message_create['message_data']['text'] ) ? sanitize_textarea_field( $message_create['message_data']['text'] ) : '';
		$event_id       = isset( $event['id'] ) ? sanitize_text_field( $event['id'] ) : '';

		if ( '' === $sender_id || '' === $message_text ) {
			return;
		}

		// Skip messages the bot sent itself to prevent reply loops.
		if ( ! empty( $for_user_id ) && $sender_id === $for_user_id ) {
			return;
		}

		// Deduplication: skip if already processed.
		if ( ! empty( $event_id ) ) {
			$dedup_key = 'wp_mcp_ai_tw_dedup_' . md5( $event_id );
			if ( false !== get_transient( $dedup_key ) ) {
				WP_MCP_AI_Logger::log_event(
					'twitter_dm_duplicate',
					'Duplicate Twitter DM event skipped.',
					array( 'event_id' => $event_id )
				);
				return;
			}
			set_transient( $dedup_key, 1, self::DEDUP_TRANSIENT_TTL );
		}

		WP_MCP_AI_Logger::log_event(
			'twitter_dm_received',
			'Incoming Twitter DM received.',
			array(
				'event_id'      => $event_id,
				'sender_prefix' => substr( $sender_id, 0, 4 ) . '***',
			)
		);

		/**
		 * Fires when a Twitter DM message_create event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $event         DM event object.
		 * @param string $for_user_id   App user ID.
		 * @param string $connection_id Connection ID.
		 */
		do_action( 'wp_mcp_ai_twitter_dm_received', $event, $for_user_id, $connection_id );

		// Dispatch AI reply if a connection with assigned assistants exists.
		$connection = $this->get_active_twitter_connection( $connection_id );
		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error(
				'Twitter webhook: no active Twitter connection with assigned assistants found.'
			);
			return;
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) )
			: array();

		if ( empty( $assigned_assistant_ids ) ) {
			return;
		}

		$resolved_connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';
		if ( '' === $resolved_connection_id ) {
			return;
		}

		// Find or create the contact in the Channel Contacts CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'twitter',
				$sender_id,
				array( 'display_name' => $sender_id )
			);
			if ( $contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_row_id );
			}
		}

		// Persist inbound message to Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'twitter',
					'channel_contact_id' => $sender_id,
					'direction'          => 'inbound',
					'message_id'         => $event_id,
					'message_type'       => 'text',
					'content'            => $message_text,
					'status'             => 'received',
					'connection_id'      => $resolved_connection_id,
					'phone_number_id'    => $for_user_id,
					'timestamp'          => time(),
					'reply_sent'         => 0,
					'assigned_agent'     => (string) reset( $assigned_assistant_ids ),
				)
			);
		}

		$job_args = array(
			array(
				'assistant_id'  => reset( $assigned_assistant_ids ),
				'message_text'  => $message_text,
				'sender_id'     => $sender_id,
				'connection_id' => $resolved_connection_id,
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();
	}

	/**
	 * Cron callback: generate an AI reply and send it as a Twitter DM.
	 *
	 * Uses the Twitter API v2 DM endpoint with OAuth 1.0a authentication,
	 * which is required for write operations (sending DMs) on behalf of a user.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by process_dm_event().
	 */
	public function handle_twitter_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$sender_id     = isset( $args['sender_id'] ) ? (string) $args['sender_id'] : '';
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';

		if ( ! $assistant_id || '' === $message_text || '' === $sender_id || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Twitter AI reply: connection not found or API key missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// Decrypt credentials — none are stored in cron args.
		$api_key              = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		$api_secret           = isset( $connection['api_secret'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] ) : '';
		$access_token         = isset( $connection['client_id'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_id'] ) : '';
		$access_token_secret  = isset( $connection['client_secret'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] ) : '';

		if ( '' === $api_key || '' === $api_secret || '' === $access_token || '' === $access_token_secret ) {
			WP_MCP_AI_Logger::log_error(
				'Twitter AI reply: one or more OAuth 1.0a credentials are missing or empty.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// --- Per-user conversation history ---
		$history_key = $this->get_conversation_history_key( $sender_id, $connection_id );
		$history     = get_transient( $history_key );
		$history     = is_array( $history ) ? $history : array();

		$max_history = 8;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$max_history = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : $max_history;
		}

		/**
		 * Filters the maximum number of messages kept in a Twitter conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_twitter_max_history_messages', $max_history, $args );
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

		// Run the get_vector_store tool when the assistant uses OpenAI with a vector store.
		// Prepend a system message so the AI is aware of the available knowledge store.
		if ( function_exists( 'wp_mcp_ai_run_get_vector_store_for_channel' ) ) {
			$vs_result = wp_mcp_ai_run_get_vector_store_for_channel( $assistant_id );
			if ( is_array( $vs_result ) && ! empty( $vs_result['data'] ) ) {
				$vs_data    = $vs_result['data'];
				$file_count = isset( $vs_data['file_counts']['completed'] ) ? (int) $vs_data['file_counts']['completed'] : 0;
				$vs_name    = ( isset( $vs_data['name'] ) && '' !== $vs_data['name'] ) ? $vs_data['name'] : 'Knowledge Base';
				$vs_status  = isset( $vs_data['status'] ) ? $vs_data['status'] : 'unknown';
				$messages   = array_merge(
					array(
						array(
							'role'    => 'system',
							/* translators: 1: vector store name, 2: status, 3: indexed file count */
							'content' => sprintf(
								__( 'Vector store available: "%1$s" (Status: %2$s, Indexed files: %3$d). You may reference this knowledge base when answering questions.', 'mcp-ai-wpoos' ),
								$vs_name,
								$vs_status,
								$file_count
							),
						),
					),
					$messages
				);
			}
		}

		// Call the internal chat REST endpoint.
		$chat_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$chat_request->set_body_params(
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
			$chat_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		} else {
			WP_MCP_AI_Logger::log_error(
				'Twitter AI reply: no administrator user found; internal chat request may fail for non-public assistants.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $chat_request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			$error_data = $response->get_data();
			WP_MCP_AI_Logger::log_error(
				'Twitter AI reply: chat request failed.',
				array(
					'assistant_id' => $assistant_id,
					'error_code'   => is_array( $error_data ) && isset( $error_data['code'] ) ? sanitize_text_field( (string) $error_data['code'] ) : '',
				)
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );
		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Twitter AI reply: empty content from assistant.', array( 'assistant_id' => $assistant_id ) );
			return;
		}

		// Twitter DMs are plain text — strip HTML and enforce the character limit.
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( mb_strlen( $content ) > self::MAX_DM_LENGTH ) {
			$content = mb_substr( $content, 0, self::MAX_DM_LENGTH - 3 ) . '...';
		}

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Twitter AI reply: content empty after HTML stripping.', array( 'assistant_id' => $assistant_id ) );
			return;
		}

		// Send DM via Twitter API v2 with OAuth 1.0a.
		$dm_result = $this->send_twitter_dm( $sender_id, $content, $api_key, $api_secret, $access_token, $access_token_secret );

		if ( is_wp_error( $dm_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Twitter AI reply: DM send failed.',
				array(
					'error'        => $dm_result->get_error_message(),
					'assistant_id' => $assistant_id,
				)
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'twitter_ai_reply_sent',
			'Twitter AI reply dispatched successfully.',
			array(
				'assistant_id'   => $assistant_id,
				'sender_prefix'  => substr( $sender_id, 0, 4 ) . '***',
			)
		);

		// Persist the outbound AI reply to the Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'twitter',
					'channel_contact_id' => $sender_id,
					'direction'          => 'outbound',
					'message_type'       => 'text',
					'content'            => $content,
					'status'             => 'sent',
					'connection_id'      => $connection_id,
					'timestamp'          => time(),
					'reply_sent'         => 1,
					'assigned_agent'     => (string) $assistant_id,
				)
			);
		}

		// Touch the contact record to update last_message_at.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$tw_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'twitter', $sender_id );
			if ( $tw_contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $tw_contact_row_id );
			}
		}

		// Persist conversation history.
		$history[] = array( 'role' => 'user', 'content' => $message_text );
		$history[] = array( 'role' => 'assistant', 'content' => $content );
		if ( count( $history ) > $max_history ) {
			$history = array_slice( $history, -$max_history );
		}
		set_transient( $history_key, $history, self::CONVERSATION_HISTORY_TTL );
	}

	/**
	 * Send a Direct Message via Twitter API v2 using OAuth 1.0a.
	 *
	 * Endpoint: POST https://api.twitter.com/2/dm_conversations/with/:participant_id/messages
	 *
	 * @since 1.0.0
	 *
	 * @param string $recipient_id       Twitter user ID of the recipient.
	 * @param string $text               DM text content.
	 * @param string $api_key            Consumer Key (API Key).
	 * @param string $api_secret         Consumer Secret (API Secret Key).
	 * @param string $access_token       OAuth Access Token.
	 * @param string $access_token_secret OAuth Access Token Secret.
	 * @return array|WP_Error
	 */
	protected function send_twitter_dm( $recipient_id, $text, $api_key, $api_secret, $access_token, $access_token_secret ) {
		$endpoint = 'https://api.twitter.com/2/dm_conversations/with/' . rawurlencode( $recipient_id ) . '/messages';

		$payload = array( 'text' => $text );
		$body    = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'twitter_encoding_error', __( 'Failed to encode Twitter DM payload.', 'mcp-ai-wpoos-pro' ) );
		}

		$oauth_header = $this->build_oauth1_header( 'POST', $endpoint, array(), $api_key, $api_secret, $access_token, $access_token_secret );

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => $oauth_header,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'twitter_http_error',
				__( 'Twitter DM request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded      = json_decode( $response_body, true );

		if ( $http_code < 200 || $http_code >= 300 ) {
			$error_detail = is_array( $decoded ) && isset( $decoded['detail'] ) ? $decoded['detail'] : $response_body;
			return new WP_Error(
				'twitter_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: API error detail */
					__( 'Twitter API returned HTTP %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
					$http_code,
					$error_detail
				),
				array( 'http_code' => $http_code, 'response' => $decoded )
			);
		}

		return is_array( $decoded ) ? $decoded : array( 'raw' => $response_body );
	}

	/**
	 * Build an OAuth 1.0a Authorization header.
	 *
	 * Implements the OAuth 1.0a HMAC-SHA1 signature method as required by the
	 * Twitter v1.1 / Account Activity API and the Twitter v2 DM endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $http_method          HTTP method (POST, GET, etc.).
	 * @param string $url                  Full request URL (without query string).
	 * @param array  $extra_params         Any additional parameters to include in the base string.
	 * @param string $consumer_key         OAuth consumer key (API Key).
	 * @param string $consumer_secret      OAuth consumer secret (API Secret Key).
	 * @param string $access_token         OAuth access token.
	 * @param string $access_token_secret  OAuth access token secret.
	 * @return string OAuth Authorization header value.
	 */
	protected function build_oauth1_header( $http_method, $url, array $extra_params, $consumer_key, $consumer_secret, $access_token, $access_token_secret ) {
		$nonce     = wp_generate_uuid4();
		$timestamp = (string) time();

		$oauth_params = array(
			'oauth_consumer_key'     => $consumer_key,
			'oauth_nonce'            => $nonce,
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => $timestamp,
			'oauth_token'            => $access_token,
			'oauth_version'          => '1.0',
		);

		// Merge with any extra query/body params for the signature base string.
		$all_params = array_merge( $extra_params, $oauth_params );
		ksort( $all_params );

		// Percent-encode each key and value.
		$encoded_pairs = array();
		foreach ( $all_params as $key => $value ) {
			$encoded_pairs[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
		}
		$param_string = implode( '&', $encoded_pairs );

		// Build the signature base string.
		$base_string = strtoupper( $http_method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( $param_string );

		// Build the signing key.
		$signing_key = rawurlencode( $consumer_secret ) . '&' . rawurlencode( $access_token_secret );

		// Generate the HMAC-SHA1 signature and base64-encode it.
		$oauth_params['oauth_signature'] = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			hash_hmac( 'sha1', $base_string, $signing_key, true )
		);

		// Build the Authorization header value.
		$header_parts = array();
		foreach ( $oauth_params as $key => $value ) {
			$header_parts[] = rawurlencode( $key ) . '="' . rawurlencode( $value ) . '"';
		}

		return 'OAuth ' . implode( ', ', $header_parts );
	}

	/**
	 * Get the active Twitter connection that has assigned assistants.
	 *
	 * When a connection_id is supplied (channel-specific URL), only that
	 * connection is checked. Otherwise the first enabled Twitter connection
	 * with assigned assistants is returned.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Optional connection ID. Empty for generic lookup.
	 * @return array|null Connection data or null.
	 */
	protected function get_active_twitter_connection( $connection_id = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		if ( ! empty( $connection_id ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( $connection && isset( $connection['connection_type'] ) && 'twitter' === $connection['connection_type'] ) {
				return $connection;
			}
			return null;
		}

		// Generic: first enabled Twitter connection with assigned assistants.
		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		foreach ( $all_connections as $connection ) {
			if ( isset( $connection['connection_type'] ) && 'twitter' === $connection['connection_type']
				&& ! empty( $connection['enabled'] )
				&& ! empty( $connection['assigned_assistant_ids'] ) ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Get the consumer secret for signature validation.
	 *
	 * When a connection_id is supplied, returns the secret for that specific
	 * connection only. Otherwise falls back to the first non-empty secret found.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Optional connection ID.
	 * @return string Consumer secret or empty string.
	 */
	protected function get_consumer_secret( $connection_id = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		if ( ! empty( $connection_id ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( $connection && isset( $connection['connection_type'] ) && 'twitter' === $connection['connection_type'] ) {
				if ( ! empty( $connection['api_secret'] ) ) {
					return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] );
				}
			}
			return '';
		}

		// Generic lookup.
		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		foreach ( $all_connections as $connection ) {
			if ( isset( $connection['connection_type'] ) && 'twitter' === $connection['connection_type']
				&& ! empty( $connection['api_secret'] ) ) {
				return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] );
			}
		}

		return '';
	}

	/**
	 * Return the transient key for a user's conversation history on a connection.
	 *
	 * The key is hashed so it contains no PII and stays within WordPress's
	 * 172-character transient key limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sender_id     Twitter user ID of the sender.
	 * @param string $connection_id Connection ID.
	 * @return string Transient key.
	 */
	protected function get_conversation_history_key( $sender_id, $connection_id ) {
		return 'wp_mcp_ai_tw_conv_' . md5( $sender_id . '_' . $connection_id );
	}

	/**
	 * Extract the assistant reply text from a /mcp-ai/v1/chat REST response.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $response_data Data returned by WP_REST_Response::get_data().
	 * @return string Assistant reply text, or empty string if not found.
	 */
	protected function extract_content_from_chat_response( $response_data ) {
		if ( ! is_array( $response_data ) ) {
			return '';
		}

		$llm_data = isset( $response_data['data'] ) && is_array( $response_data['data'] ) ? $response_data['data'] : array();
		$choices  = isset( $llm_data['choices'] ) && is_array( $llm_data['choices'] ) ? $llm_data['choices'] : array();

		if ( empty( $choices ) ) {
			return '';
		}

		$first_choice = reset( $choices );
		if ( isset( $first_choice['message']['content'] ) && is_string( $first_choice['message']['content'] ) ) {
			return $first_choice['message']['content'];
		}

		return '';
	}
}

// Initialize the controller.
new WP_MCP_AI_Twitter_Webhook_Controller();
