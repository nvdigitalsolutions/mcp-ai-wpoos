<?php
/**
 * Discord Interactions Endpoint Controller
 *
 * Handles Discord bot interactions with industry-standard security validation.
 * Implements Discord's Interactions Endpoint best practices:
 * - Ed25519 signature verification (X-Signature-Ed25519 + X-Signature-Timestamp)
 * - PING/PONG handshake (synchronous, required by Discord)
 * - Deferred channel message response for AI replies (type 5)
 * - Follow-up message delivery via WordPress cron
 * - Per-user conversation history respecting max_history_messages
 * - Message deduplication via transient cache
 *
 * Ed25519 verification requires the PHP sodium extension (available in PHP 7.2+
 * and enabled by default in most environments). If sodium is unavailable the
 * controller falls back to allowing through with a security warning.
 *
 * @see https://discord.com/developers/docs/interactions/receiving-and-responding
 * @see https://discord.com/developers/docs/tutorials/hosting-on-cloudflare-workers#5-verifying-security-headers
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
 * Discord Interactions endpoint REST controller.
 */
class WP_MCP_AI_Discord_Interaction_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/discord';

	/**
	 * Cron hook for dispatching AI follow-up replies to Discord interactions.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_discord_send_ai_reply';

	/**
	 * Discord Interaction type: PING.
	 */
	const INTERACTION_TYPE_PING = 1;

	/**
	 * Discord Interaction type: APPLICATION_COMMAND (slash command).
	 */
	const INTERACTION_TYPE_APPLICATION_COMMAND = 2;

	/**
	 * Discord Interaction type: MESSAGE_COMPONENT (buttons, select menus).
	 */
	const INTERACTION_TYPE_MESSAGE_COMPONENT = 3;

	/**
	 * Discord Interaction response type: PONG.
	 */
	const RESPONSE_TYPE_PONG = 1;

	/**
	 * Discord Interaction response type: DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE.
	 * Acknowledges the interaction and shows "Bot is thinking…" to users.
	 * The bot has up to 15 minutes to post a follow-up message.
	 */
	const RESPONSE_TYPE_DEFERRED = 5;

	/**
	 * Discord REST API base URL.
	 */
	const DISCORD_API_BASE = 'https://discord.com/api/v10';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_discord_reply_job' ) );
	}

	/**
	 * Register REST routes for Discord interactions.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_interaction' ),
				'permission_callback' => array( $this, 'validate_discord_signature' ),
			)
		);
	}

	/**
	 * Validate the Discord request signature using Ed25519.
	 *
	 * Discord requires every interaction request to be verified with the
	 * application's public key. The signature is sent in the
	 * X-Signature-Ed25519 header and must be verified against
	 * (timestamp + body) using the Ed25519 algorithm.
	 *
	 * Falls back to allowing the request if the public key is not configured
	 * or if the PHP sodium extension is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if signature is valid, false otherwise.
	 */
	public function validate_discord_signature( $request ) {
		$public_key = $this->get_public_key();

		if ( empty( $public_key ) ) {
			WP_MCP_AI_Logger::log_error(
				'Discord interaction rejected: public key is not configured. Configure public_key in the connection settings to enable this webhook.',
				array()
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'Discord webhook authentication is not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Ed25519 verification requires the sodium PHP extension.
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			WP_MCP_AI_Logger::log_error(
				'Discord interaction rejected: PHP sodium extension is required for Ed25519 signature validation but is not available on this server.',
				array()
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'Discord webhook requires the PHP sodium extension for Ed25519 signature validation.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$signature = $request->get_header( 'x-signature-ed25519' );
		$timestamp = $request->get_header( 'x-signature-timestamp' );

		if ( empty( $signature ) || empty( $timestamp ) ) {
			WP_MCP_AI_Logger::log_error( 'Discord interaction rejected: missing signature or timestamp header.' );
			return false;
		}

		$body    = $request->get_body();
		$message = $timestamp . $body;

		// Convert hex strings to binary.
		$signature_binary  = @hex2bin( $signature ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$public_key_binary = @hex2bin( $public_key ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $signature_binary || false === $public_key_binary ) {
			WP_MCP_AI_Logger::log_error( 'Discord interaction rejected: invalid signature or public key encoding.' );
			return false;
		}

		try {
			$valid = sodium_crypto_sign_verify_detached( $signature_binary, $message, $public_key_binary );
		} catch ( \Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'Discord interaction rejected: Ed25519 verification threw an exception.',
				array( 'error' => $e->getMessage() )
			);
			return false;
		}

		if ( ! $valid ) {
			WP_MCP_AI_Logger::log_error( 'Discord interaction rejected: Ed25519 signature mismatch.' );
		}

		return $valid;
	}

	/**
	 * Handle an incoming Discord interaction.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_interaction( $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Discord interaction: empty or invalid JSON payload.' );
			// Return PONG to avoid Discord retrying with a 200.
			return rest_ensure_response( array( 'type' => self::RESPONSE_TYPE_PONG ) );
		}

		$type = isset( $payload['type'] ) ? (int) $payload['type'] : 0;

		WP_MCP_AI_Logger::log_event(
			'discord_interaction_received',
			'Discord interaction received.',
			array( 'type' => $type )
		);

		// Discord PING — must respond with PONG immediately.
		if ( self::INTERACTION_TYPE_PING === $type ) {
			return rest_ensure_response( array( 'type' => self::RESPONSE_TYPE_PONG ) );
		}

		$interaction_id = isset( $payload['id'] ) ? sanitize_text_field( $payload['id'] ) : '';
		$token          = isset( $payload['token'] ) ? (string) $payload['token'] : '';

		// Deduplicate interactions.
		if ( $interaction_id && $this->is_duplicate_interaction( $interaction_id ) ) {
			return rest_ensure_response( array( 'type' => self::RESPONSE_TYPE_DEFERRED ) );
		}

		if ( $interaction_id ) {
			set_transient( 'wp_mcp_ai_ds_dedup_' . md5( $interaction_id ), 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Extract message text and sender.
		$message_text = $this->extract_message_text( $payload );
		$user_id      = $this->extract_user_id( $payload );
		$channel_id   = isset( $payload['channel_id'] ) ? sanitize_text_field( $payload['channel_id'] ) : '';
		$guild_id     = isset( $payload['guild_id'] ) ? sanitize_text_field( $payload['guild_id'] ) : '';
		$app_id       = isset( $payload['application_id'] ) ? sanitize_text_field( $payload['application_id'] ) : '';

		if ( '' !== $message_text && '' !== $channel_id && '' !== $token ) {
			$connection = $this->get_active_discord_connection( $app_id, $guild_id );

			if ( $connection ) {
				$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
					? array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) )
					: array();

				if ( ! empty( $assigned_assistant_ids ) ) {
					$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

					// When the connection requires an @slug mention, only reply if the message
					// explicitly addresses an assigned assistant by its WordPress post slug.
					if ( '' !== $connection_id && ( empty( $connection['require_mention'] ) || $this->message_mentions_assistant( $message_text, $assigned_assistant_ids ) ) ) {
						// Find or create the contact in the Channel Contacts CCT.
						if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
							$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
								'discord',
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
									'channel'            => 'discord',
									'channel_contact_id' => $user_id,
									'direction'          => 'inbound',
									'message_id'         => $interaction_id,
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
								'assistant_id'      => $assigned_assistant_ids[0],
								'message_text'      => $message_text,
								'user_id'           => $user_id,
								'channel_id'        => $channel_id,
								'interaction_id'    => $interaction_id,
								'interaction_token' => $token,
								'app_id'            => $app_id,
								'connection_id'     => $connection_id,
							),
						);

						wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
						spawn_cron();
					}
				}
			}
		}

		// Acknowledge immediately with a deferred response so Discord shows
		// "Bot is thinking…". The actual reply is posted as a follow-up.
		return rest_ensure_response( array( 'type' => self::RESPONSE_TYPE_DEFERRED ) );
	}

	/**
	 * Cron callback: generate an AI reply and post it as a Discord follow-up message.
	 *
	 * Implements per-user conversation history following the same pattern as the
	 * WhatsApp auto-reply handler (PR #3844), respecting the global
	 * max_history_messages setting and the wp_mcp_ai_discord_max_history_messages filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by handle_interaction().
	 */
	public function handle_discord_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id      = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text      = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$user_id           = isset( $args['user_id'] ) ? (string) $args['user_id'] : '';
		$channel_id        = isset( $args['channel_id'] ) ? (string) $args['channel_id'] : '';
		$interaction_id    = isset( $args['interaction_id'] ) ? (string) $args['interaction_id'] : '';
		$interaction_token = isset( $args['interaction_token'] ) ? (string) $args['interaction_token'] : '';
		$app_id            = isset( $args['app_id'] ) ? (string) $args['app_id'] : '';
		$connection_id     = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';

		if ( ! $assistant_id || '' === $message_text || '' === $interaction_token || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Discord AI reply: connection not found or bot token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( '' === $bot_token ) {
			WP_MCP_AI_Logger::log_error(
				'Discord AI reply: bot token decryption returned empty string.',
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
		 * Filters the maximum number of messages kept in a Discord conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_discord_max_history_messages', $max_history, $args );
		$max_history = max( 1, $max_history );

		// When the transient cache is empty (e.g. after expiry or a cache flush),
		// hydrate the conversation context from the Channel Messages CCT so that
		// prior exchanges are never silently dropped. The CCT is the persistent
		// source of truth; the transient is a fast in-memory cache on top of it.
		if ( empty( $history ) && $max_history > 1 && class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			$history = WP_MCP_AI_Channel_Messages_CCT::get_recent_messages(
				'discord',
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
				'Discord AI reply: no administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Discord AI reply: chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Discord AI reply: empty content from assistant.' );
			return;
		}

		// Discord message limit is 2000 characters.
		if ( mb_strlen( $content ) > 2000 ) {
			$content = mb_substr( $content, 0, 1997 ) . '...';
		}

		// Post as an interaction follow-up via Discord webhook endpoint.
		// This uses the interaction token which is valid for 15 minutes.
		$effective_app_id = '' !== $app_id ? $app_id : ( isset( $connection['application_id'] ) ? $connection['application_id'] : '' );

		if ( '' !== $effective_app_id && '' !== $interaction_token ) {
			$followup_endpoint = sprintf(
				'%s/webhooks/%s/%s',
				self::DISCORD_API_BASE,
				rawurlencode( $effective_app_id ),
				rawurlencode( $interaction_token )
			);

			$followup_payload = array( 'content' => $content );
			$followup_body    = wp_json_encode( $followup_payload );

			if ( $followup_body ) {
				$followup_result = wp_remote_post(
					$followup_endpoint,
					array(
						'headers' => array( 'Content-Type' => 'application/json' ),
						'timeout' => 20,
						'body'    => $followup_body,
					)
				);

				if ( ! is_wp_error( $followup_result ) ) {
					$http_code = (int) wp_remote_retrieve_response_code( $followup_result );

					if ( 200 === $http_code ) {
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
							'discord_ai_reply_sent',
							'Discord AI reply sent successfully via interaction follow-up.',
							array( 'assistant_id' => $assistant_id )
						);
						// Persist the outbound AI reply to the Channel Messages CCT.
						if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
							WP_MCP_AI_Channel_Messages_CCT::insert(
								array(
									'channel'            => 'discord',
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
							$ds_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'discord', $user_id );
							if ( $ds_contact_row_id ) {
								WP_MCP_AI_Channel_Contacts_CCT::touch( $ds_contact_row_id );
							}
						}
						return;
					}

					WP_MCP_AI_Logger::log_error(
						'Discord AI reply: interaction follow-up returned non-200.',
						array( 'http_code' => $http_code )
					);
				} else {
					WP_MCP_AI_Logger::log_error(
						'Discord AI reply: interaction follow-up HTTP request failed.',
						array( 'error' => $followup_result->get_error_message() )
					);
				}
			}
		}

		// Fallback: post as a regular channel message if follow-up fails or no app_id.
		if ( '' === $channel_id ) {
			return;
		}

		$channel_endpoint = sprintf( '%s/channels/%s/messages', self::DISCORD_API_BASE, rawurlencode( $channel_id ) );
		$channel_payload  = array( 'content' => $content );
		$channel_body     = wp_json_encode( $channel_payload );

		if ( ! $channel_body ) {
			return;
		}

		$channel_result = wp_remote_post(
			$channel_endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bot ' . $bot_token,
				),
				'timeout' => 20,
				'body'    => $channel_body,
			)
		);

		if ( is_wp_error( $channel_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Discord AI reply: channel message HTTP request failed.',
				array( 'error' => $channel_result->get_error_message() )
			);
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $channel_result );

		if ( 200 === $http_code ) {
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
				'discord_ai_reply_sent',
				'Discord AI reply sent via channel message fallback.',
				array( 'assistant_id' => $assistant_id )
			);
			// Persist the outbound AI reply to the Channel Messages CCT.
			if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
				WP_MCP_AI_Channel_Messages_CCT::insert(
					array(
						'channel'            => 'discord',
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
				$ds_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'discord', $user_id );
				if ( $ds_contact_row_id ) {
					WP_MCP_AI_Channel_Contacts_CCT::touch( $ds_contact_row_id );
				}
			}
		} else {
			WP_MCP_AI_Logger::log_error(
				'Discord AI reply: channel message returned non-200.',
				array( 'http_code' => $http_code )
			);
		}
	}

	/**
	 * Return the transient key for a Discord user/channel conversation history.
	 *
	 * @since 1.0.0
	 *
	 * @param string $user_id       Discord user snowflake ID.
	 * @param string $channel_id    Discord channel ID.
	 * @param string $connection_id Remote connection ID.
	 * @return string Transient key (hashed, within 172-character limit).
	 */
	protected function get_conversation_history_key( $user_id, $channel_id, $connection_id ) {
		return 'wp_mcp_ai_ds_conv_' . md5( $user_id . '_' . $channel_id . '_' . $connection_id );
	}

	/**
	 * Check whether an interaction ID has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $interaction_id Discord interaction ID.
	 * @return bool True if already processed.
	 */
	protected function is_duplicate_interaction( $interaction_id ) {
		return (bool) get_transient( 'wp_mcp_ai_ds_dedup_' . md5( $interaction_id ) );
	}

	/**
	 * Retrieve the Ed25519 public key from the first active Discord connection.
	 *
	 * @since 1.0.0
	 *
	 * @return string Hex-encoded public key or empty string.
	 */
	protected function get_public_key() {
		$connection = $this->get_active_discord_connection();

		if ( ! $connection || empty( $connection['public_key'] ) ) {
			return '';
		}

		return sanitize_text_field( $connection['public_key'] );
	}

	/**
	 * Find the first active Discord connection with assigned assistants.
	 *
	 * Optionally matches by application_id and/or guild_id when provided.
	 *
	 * @since 1.0.0
	 *
	 * @param string $app_id   Optional application ID to match.
	 * @param string $guild_id Optional guild ID to match.
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_discord_connection( $app_id = '', $guild_id = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		$first_match = null;

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'discord' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( empty( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
				continue;
			}

			// Prefer exact match when we have routing hints.
			$matches_app   = '' === $app_id || ( isset( $connection['application_id'] ) && $connection['application_id'] === $app_id );
			$matches_guild = '' === $guild_id || ( isset( $connection['guild_id'] ) && $connection['guild_id'] === $guild_id );

			if ( $matches_app && $matches_guild ) {
				return $connection;
			}

			if ( null === $first_match ) {
				$first_match = $connection;
			}
		}

		return $first_match;
	}

	/**
	 * Extract message text from a Discord interaction payload.
	 *
	 * For APPLICATION_COMMAND interactions the text is in data.options[0].value.
	 * For MESSAGE_COMPONENT interactions it may be in data.custom_id.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Discord interaction payload.
	 * @return string Message text or empty string.
	 */
	protected function extract_message_text( array $payload ) {
		// Slash command: text is in the first string-type option.
		if ( isset( $payload['data']['options'] ) && is_array( $payload['data']['options'] ) ) {
			foreach ( $payload['data']['options'] as $option ) {
				if ( isset( $option['value'] ) && is_string( $option['value'] ) ) {
					return sanitize_textarea_field( $option['value'] );
				}
			}
		}

		// Some integrations put the message in data.name (command name used as text).
		if ( isset( $payload['data']['name'] ) && is_string( $payload['data']['name'] ) ) {
			return sanitize_text_field( $payload['data']['name'] );
		}

		return '';
	}

	/**
	 * Extract the Discord user snowflake ID from an interaction payload.
	 *
	 * Guild interactions have the user under member.user.id; DM interactions
	 * have it directly under user.id.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Discord interaction payload.
	 * @return string User ID or empty string.
	 */
	protected function extract_user_id( array $payload ) {
		if ( isset( $payload['member']['user']['id'] ) ) {
			return sanitize_text_field( $payload['member']['user']['id'] );
		}

		if ( isset( $payload['user']['id'] ) ) {
			return sanitize_text_field( $payload['user']['id'] );
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

new WP_MCP_AI_Discord_Interaction_Controller();
