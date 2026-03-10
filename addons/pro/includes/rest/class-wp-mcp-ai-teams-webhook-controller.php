<?php
/**
 * Microsoft Teams Outgoing Webhook Controller
 *
 * Handles incoming Microsoft Teams outgoing webhook requests with
 * industry-standard security validation. Implements Teams platform best practices:
 * - HMAC-SHA256 signature verification (Authorization header)
 * - Per-user conversation history respecting max_history_messages
 * - AI auto-reply via WordPress cron
 * - Message deduplication via transient cache
 *
 * Teams outgoing webhooks are configured in the Teams Admin Center and send
 * requests signed with a shared HMAC-SHA256 secret (the "Security token"
 * shown when creating the outgoing webhook). Store this as signing_secret on
 * the connection.
 *
 * @see https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-outgoing-webhook
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
 * Microsoft Teams outgoing webhook REST controller.
 */
class WP_MCP_AI_Teams_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/teams';

	/**
	 * Cron hook for dispatching AI replies to incoming Teams messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_teams_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Microsoft Graph API base URL.
	 */
	const GRAPH_API_BASE = 'https://graph.microsoft.com/v1.0';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_teams_reply_job' ) );
	}

	/**
	 * Register REST routes for Teams outgoing webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_teams_signature' ),
			)
		);
	}

	/**
	 * Validate the Teams outgoing webhook HMAC-SHA256 signature.
	 *
	 * Teams signs the request body using the HMAC-SHA256 algorithm with the
	 * shared security token (base64-encoded) and sends the resulting signature
	 * (also base64-encoded) in the Authorization header.
	 *
	 * When the signing secret is not configured the webhook is allowed through
	 * with a security warning.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if signature is valid or signing secret is not configured.
	 */
	public function validate_teams_signature( $request ) {
		$signing_secret = $this->get_signing_secret();

		if ( empty( $signing_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'Teams webhook rejected: no signing secret configured. Set signing_secret in the connection settings to enable HMAC validation.'
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'Webhook authentication is not configured. Please set a signing secret in the connection settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$auth_header = $request->get_header( 'authorization' );

		if ( empty( $auth_header ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook rejected: missing Authorization header.' );
			return false;
		}

		// Teams sends "HMAC {base64-signature}".
		if ( 0 !== strncmp( $auth_header, 'HMAC ', 5 ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook rejected: Authorization header does not start with "HMAC ".' );
			return false;
		}

		$provided_signature = substr( $auth_header, 5 );

		$raw_body      = $request->get_body();
		$key_bytes     = base64_decode( $signing_secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$computed_hmac = hash_hmac( 'sha256', $raw_body, $key_bytes, true );
		$computed_b64  = base64_encode( $computed_hmac ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		if ( ! hash_equals( $computed_b64, $provided_signature ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook rejected: invalid HMAC-SHA256 signature.' );
			return false;
		}

		return true;
	}

	/**
	 * Handle an incoming Teams outgoing webhook request.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_webhook( $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook: empty or invalid JSON payload.' );
			return rest_ensure_response( $this->empty_response() );
		}

		// Extract Teams-specific identifiers for deduplication.
		$activity_id = isset( $payload['id'] ) ? sanitize_text_field( $payload['id'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'teams_webhook_received',
			'Teams outgoing webhook activity received.',
			array( 'activity_id' => $activity_id )
		);

		if ( $activity_id && $this->is_duplicate_activity( $activity_id ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		if ( $activity_id ) {
			set_transient( 'wp_mcp_ai_ms_dedup_' . md5( $activity_id ), 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Extract the message text.
		$message_text = $this->extract_message_text( $payload );

		if ( '' === $message_text ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// Extract user and channel identifiers for routing and history.
		$user_id      = $this->extract_user_id( $payload );
		$channel_id   = isset( $payload['channelData']['channel']['id'] ) ? sanitize_text_field( $payload['channelData']['channel']['id'] ) : '';
		$team_id      = isset( $payload['channelData']['team']['id'] ) ? sanitize_text_field( $payload['channelData']['team']['id'] ) : '';
		$tenant_id    = isset( $payload['channelData']['tenant']['id'] ) ? sanitize_text_field( $payload['channelData']['tenant']['id'] ) : '';
		$service_url  = isset( $payload['serviceUrl'] ) ? esc_url_raw( $payload['serviceUrl'] ) : '';
		$conversation = isset( $payload['conversation'] ) && is_array( $payload['conversation'] ) ? $payload['conversation'] : array();

		$connection = $this->get_active_teams_connection( $tenant_id );

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook: no active Teams connection with assigned assistants found.' );
			return rest_ensure_response( $this->empty_response() );
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) )
			: array();

		if ( empty( $assigned_assistant_ids ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// When the connection requires an @slug mention, only reply if the message
		// explicitly addresses an assigned assistant by its WordPress post slug.
		if ( ! empty( $connection['require_mention'] ) && ! $this->message_mentions_assistant( $message_text, $assigned_assistant_ids ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// Find or create the contact in the Channel Contacts CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'teams',
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
					'channel'            => 'teams',
					'channel_contact_id' => $user_id,
					'direction'          => 'inbound',
					'message_id'         => $activity_id,
					'message_type'       => 'text',
					'content'            => $message_text,
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $channel_id,
					'timestamp'          => time(),
					'reply_sent'         => 0,
					'assigned_agent'     => (string) $assigned_assistant_ids[0],
				)
			);
		}

		$job_args = array(
			array(
				'assistant_id'  => $assigned_assistant_ids[0],
				'message_text'  => $message_text,
				'user_id'       => $user_id,
				'channel_id'    => $channel_id,
				'team_id'       => $team_id,
				'service_url'   => $service_url,
				'conversation'  => $conversation,
				'connection_id' => $connection_id,
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();

		// Return empty acknowledgement — Teams shows this in the channel.
		// An empty text means the outgoing webhook is silent until the cron reply arrives.
		return rest_ensure_response( $this->empty_response() );
	}

	/**
	 * Cron callback: generate an AI reply and post it to the Teams channel.
	 *
	 * Implements per-user conversation history following the same pattern as the
	 * WhatsApp auto-reply handler (PR #3844), respecting the global
	 * max_history_messages setting and the wp_mcp_ai_teams_max_history_messages filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by handle_webhook().
	 */
	public function handle_teams_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$user_id       = isset( $args['user_id'] ) ? (string) $args['user_id'] : '';
		$channel_id    = isset( $args['channel_id'] ) ? (string) $args['channel_id'] : '';
		$team_id       = isset( $args['team_id'] ) ? (string) $args['team_id'] : '';
		$service_url   = isset( $args['service_url'] ) ? esc_url_raw( (string) $args['service_url'] ) : '';
		$conversation  = isset( $args['conversation'] ) && is_array( $args['conversation'] ) ? $args['conversation'] : array();
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';

		if ( ! $assistant_id || '' === $message_text || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['token'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Teams AI reply: connection not found or access token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['token'] );

		if ( '' === $access_token ) {
			WP_MCP_AI_Logger::log_error(
				'Teams AI reply: access token decryption returned empty string.',
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
		 * Filters the maximum number of messages kept in a Teams conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_teams_max_history_messages', $max_history, $args );
		$max_history = max( 1, $max_history );

		// When the transient cache is empty (e.g. after expiry or a cache flush),
		// hydrate the conversation context from the Channel Messages CCT so that
		// prior exchanges are never silently dropped. The CCT is the persistent
		// source of truth; the transient is a fast in-memory cache on top of it.
		if ( empty( $history ) && $max_history > 1 && class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			$history = WP_MCP_AI_Channel_Messages_CCT::get_recent_messages(
				'teams',
				$user_id,
				$connection_id,
				$max_history - 1
			);
		}

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
				'Teams AI reply: no administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Teams AI reply: chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Teams AI reply: empty content from assistant.' );
			return;
		}

		// Post the reply via Microsoft Graph API to the Teams channel.
		$sent = false;

		if ( '' !== $team_id && '' !== $channel_id ) {
			$endpoint = sprintf(
				'%s/teams/%s/channels/%s/messages',
				self::GRAPH_API_BASE,
				rawurlencode( $team_id ),
				rawurlencode( $channel_id )
			);

			$payload = array(
				'body' => array(
					'contentType' => 'text',
					'content'     => $content,
				),
			);

			$body = wp_json_encode( $payload );

			if ( $body ) {
				WP_MCP_AI_Logger::log_event(
					'teams_ai_reply_sending',
					'Sending Teams AI reply via Graph API.',
					array(
						'assistant_id' => $assistant_id,
						'team_id'      => substr( $team_id, 0, 8 ) . '***',
					)
				);

				$result = wp_remote_post(
					$endpoint,
					array(
						'headers' => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $access_token,
						),
						'timeout' => 20,
						'body'    => $body,
					)
				);

				if ( ! is_wp_error( $result ) && 201 === (int) wp_remote_retrieve_response_code( $result ) ) {
					$sent = true;
				} elseif ( is_wp_error( $result ) ) {
					WP_MCP_AI_Logger::log_error(
						'Teams AI reply: Graph API request failed.',
						array( 'error' => $result->get_error_message() )
					);
				} else {
					WP_MCP_AI_Logger::log_error(
						'Teams AI reply: Graph API returned non-201.',
						array( 'http_code' => (int) wp_remote_retrieve_response_code( $result ) )
					);
				}
			}
		}

		if ( $sent ) {
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
				'teams_ai_reply_sent',
				'Teams AI reply sent successfully.',
				array( 'assistant_id' => $assistant_id )
			);

			// Persist the outbound AI reply to the Channel Messages CCT.
			if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
				WP_MCP_AI_Channel_Messages_CCT::insert(
					array(
						'channel'            => 'teams',
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
				$ms_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'teams', $user_id );
				if ( $ms_contact_row_id ) {
					WP_MCP_AI_Channel_Contacts_CCT::touch( $ms_contact_row_id );
				}
			}
		}
	}

	/**
	 * Return the transient key for a Teams user/channel conversation history.
	 *
	 * @since 1.0.0
	 *
	 * @param string $user_id       Teams user AAD object ID.
	 * @param string $channel_id    Teams channel ID.
	 * @param string $connection_id Remote connection ID.
	 * @return string Transient key (hashed, within 172-character limit).
	 */
	protected function get_conversation_history_key( $user_id, $channel_id, $connection_id ) {
		return 'wp_mcp_ai_ms_conv_' . md5( $user_id . '_' . $channel_id . '_' . $connection_id );
	}

	/**
	 * Check whether an activity ID has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $activity_id Teams activity ID.
	 * @return bool True if already processed.
	 */
	protected function is_duplicate_activity( $activity_id ) {
		return (bool) get_transient( 'wp_mcp_ai_ms_dedup_' . md5( $activity_id ) );
	}

	/**
	 * Retrieve the HMAC signing secret from the first active Teams connection.
	 *
	 * @since 1.0.0
	 *
	 * @return string Base64-encoded signing secret or empty string.
	 */
	protected function get_signing_secret() {
		$connection = $this->get_active_teams_connection();

		if ( ! $connection || empty( $connection['signing_secret'] ) ) {
			return '';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['signing_secret'] );
	}

	/**
	 * Find the first active Microsoft Teams connection with assigned assistants.
	 *
	 * Optionally filters by tenant_id for multi-tenant setups.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tenant_id Optional tenant ID to match.
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_teams_connection( $tenant_id = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		$first_match = null;

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'teams' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( empty( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
				continue;
			}

			if ( '' !== $tenant_id && isset( $connection['tenant_id'] ) && $connection['tenant_id'] === $tenant_id ) {
				return $connection;
			}

			if ( null === $first_match ) {
				$first_match = $connection;
			}
		}

		return $first_match;
	}

	/**
	 * Extract plain-text message from a Teams outgoing webhook activity payload.
	 *
	 * Teams outgoing webhooks send the message in text (plain) or
	 * attachments[0].content.body[0].text (Adaptive Card). This helper
	 * prefers the simple text field.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Teams activity payload.
	 * @return string Plain-text message or empty string.
	 */
	protected function extract_message_text( array $payload ) {
		if ( isset( $payload['text'] ) && is_string( $payload['text'] ) ) {
			// Strip the bot @mention before stripping HTML tags so the
			// display-name inside <at>…</at> is removed entirely.
			$text = preg_replace( '/<at>.*?<\/at>\s*/i', '', $payload['text'] );
			$text = trim( wp_strip_all_tags( $text ) );
			return sanitize_textarea_field( trim( $text ) );
		}

		return '';
	}

	/**
	 * Extract the Teams user AAD object ID from an activity payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Teams activity payload.
	 * @return string User AAD object ID or empty string.
	 */
	protected function extract_user_id( array $payload ) {
		if ( isset( $payload['from']['aadObjectId'] ) ) {
			return sanitize_text_field( $payload['from']['aadObjectId'] );
		}

		if ( isset( $payload['from']['id'] ) ) {
			return sanitize_text_field( $payload['from']['id'] );
		}

		return '';
	}

	/**
	 * Extract plain-text content from the internal /mcp-ai/v1/chat response.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Response data.
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
	 * Return an empty Teams-compatible response body.
	 *
	 * Teams expects a JSON object response; an empty type field is acceptable.
	 *
	 * @since 1.0.0
	 *
	 * @return array Empty Teams response.
	 */
	protected function empty_response() {
		return array(
			'type' => 'message',
			'text' => '',
		);
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

new WP_MCP_AI_Teams_Webhook_Controller();
