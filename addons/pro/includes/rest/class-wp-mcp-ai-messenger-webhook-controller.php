<?php
/**
 * Meta Messenger Platform Webhook Controller
 *
 * Handles incoming Facebook Messenger webhook events with industry-standard
 * security validation. Implements Meta's Messenger Platform webhook spec:
 * - Webhook verification (GET request with hub.challenge)
 * - Signature validation (HMAC-SHA256 via X-Hub-Signature-256)
 * - Message type handling (message, postback, optin, referral, read, delivery, reaction)
 * - Error handling and logging
 *
 * @see https://developers.facebook.com/docs/messenger-platform/webhooks
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Messenger webhook REST controller.
 */
class WP_MCP_AI_Messenger_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/messenger';

	/**
	 * Cron hook for dispatching AI replies to incoming Messenger messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_messenger_send_ai_reply';

	/**
	 * Default Messenger Graph API version used when none is stored on the connection.
	 */
	const DEFAULT_GRAPH_API_VERSION = 'v21.0';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_messenger_reply_job' ) );
	}

	/**
	 * Register REST routes for Messenger webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Webhook verification endpoint (GET).
		// Note: WordPress converts dots to underscores in query parameters,
		// so hub.mode becomes hub_mode, hub.verify_token becomes hub_verify_token, etc.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'verify_webhook' ),
				'permission_callback' => '__return_true', // Public endpoint for webhook verification.
				'args'                => array(
					'hub_mode'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'hub_verify_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'hub_challenge'    => array(
						'required'          => true,
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
	}

	/**
	 * Verify webhook subscription.
	 *
	 * Handles GET requests from Meta to verify the webhook endpoint.
	 * Returns the hub_challenge value if verification token matches.
	 * Note: WordPress converts dots to underscores in query parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function verify_webhook( $request ) {
		$mode         = $request->get_param( 'hub_mode' );
		$verify_token = $request->get_param( 'hub_verify_token' );
		$challenge    = $request->get_param( 'hub_challenge' );

		WP_MCP_AI_Logger::log_event(
			'messenger_webhook_verification_attempt',
			'Messenger webhook verification request received.',
			array(
				'mode'         => $mode,
				'verify_token' => substr( $verify_token, 0, 4 ) . '***', // Masked for security.
			)
		);

		// Get stored verify token from connection settings.
		$stored_token = $this->get_verify_token();

		if ( empty( $stored_token ) ) {
			WP_MCP_AI_Logger::log_error(
				'Messenger webhook verification failed: No verify token configured.',
				array( 'mode' => $mode )
			);

			return new WP_Error(
				'messenger_no_verify_token',
				__( 'Messenger webhook verify token not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		// Verify mode and token.
		if ( 'subscribe' === $mode && hash_equals( $stored_token, $verify_token ) ) {
			WP_MCP_AI_Logger::log_event(
				'messenger_webhook_verified',
				'Messenger webhook successfully verified.'
			);

			// Return challenge as plain text (not JSON) to complete verification.
			// Meta requires the exact challenge string without any wrapping.
			$response = new WP_REST_Response( $challenge, 200 );
			$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
			return $response;
		}

		WP_MCP_AI_Logger::log_error(
			'Messenger webhook verification failed: Invalid token or mode.',
			array(
				'mode'          => $mode,
				'token_matches' => hash_equals( $stored_token, $verify_token ),
			)
		);

		return new WP_Error(
			'messenger_verification_failed',
			__( 'Messenger webhook verification failed.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Validate webhook signature using HMAC-SHA256.
	 *
	 * Implements Meta's signature validation as documented at:
	 * https://developers.facebook.com/docs/messenger-platform/webhooks#validate-payloads
	 *
	 * The signature is sent in the X-Hub-Signature-256 header as sha256=<hash>.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if signature is valid, false otherwise.
	 */
	public function validate_webhook_signature( $request ) {
		// Get app secret from connection settings.
		$app_secret = $this->get_app_secret();

		// When the App Secret is not configured, skip signature validation and
		// allow the webhook to be processed. Log a security warning so the site
		// owner knows to configure the App Secret for hardened security.
		if ( empty( $app_secret ) ) {
			WP_MCP_AI_Logger::log_event(
				'messenger_webhook_no_app_secret',
				'Messenger webhook received without App Secret configured. Signature validation skipped. Configure your App Secret in the connection settings for enhanced security.',
				array()
			);
			return true;
		}

		// App Secret is configured — the signature header is required.
		$signature_header = $request->get_header( 'x-hub-signature-256' );

		if ( empty( $signature_header ) ) {
			WP_MCP_AI_Logger::log_error(
				'Messenger webhook rejected: Missing X-Hub-Signature-256 header.'
			);
			return false;
		}

		// Get raw request body.
		$payload = $request->get_body();

		// Calculate expected signature.
		$expected_signature = 'sha256=' . hash_hmac( 'sha256', $payload, $app_secret );

		// Compare signatures using timing-safe comparison.
		if ( ! hash_equals( $expected_signature, $signature_header ) ) {
			WP_MCP_AI_Logger::log_error(
				'Messenger webhook rejected: Invalid signature.',
				array(
					'header_present' => ! empty( $signature_header ),
					'secret_present' => ! empty( $app_secret ),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Handle incoming webhook events.
	 *
	 * Processes Messenger webhook notifications. The payload uses the
	 * Messenger Platform structure with entry[].messaging[] arrays containing
	 * individual messaging events.
	 *
	 * @see https://developers.facebook.com/docs/messenger-platform/webhooks#format
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_webhook( $request ) {
		// Get webhook payload.
		$payload = $request->get_json_params();

		if ( empty( $payload ) ) {
			WP_MCP_AI_Logger::log_error(
				'Messenger webhook received with empty payload.'
			);

			// Return 200 to prevent retries for malformed requests.
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => 'Empty payload',
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'messenger_webhook_received',
			'Messenger webhook event received.',
			array(
				'object'      => isset( $payload['object'] ) ? $payload['object'] : 'unknown',
				'entry_count' => isset( $payload['entry'] ) && is_array( $payload['entry'] ) ? count( $payload['entry'] ) : 0,
			)
		);

		// Validate webhook object type.
		// Messenger Platform webhooks use 'page' as the object type.
		if ( ! isset( $payload['object'] ) || 'page' !== $payload['object'] ) {
			WP_MCP_AI_Logger::log_error(
				'Messenger webhook rejected: Invalid object type.',
				array( 'object' => isset( $payload['object'] ) ? $payload['object'] : 'missing' )
			);

			return rest_ensure_response(
				array(
					'success' => false,
					'message' => 'Invalid object type',
				)
			);
		}

		// Process each entry in the webhook.
		if ( isset( $payload['entry'] ) && is_array( $payload['entry'] ) ) {
			foreach ( $payload['entry'] as $entry ) {
				$this->process_webhook_entry( $entry );
			}
		}

		// Return 200 OK response immediately.
		// Meta requires a 200 response within 20 seconds; processing should be fast or async.
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'EVENT_RECEIVED',
			)
		);
	}

	/**
	 * Process a single webhook entry.
	 *
	 * Each entry corresponds to a Page and contains a messaging array
	 * with individual messaging events.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry Webhook entry data.
	 */
	protected function process_webhook_entry( $entry ) {
		$page_id = isset( $entry['id'] ) ? sanitize_text_field( $entry['id'] ) : '';

		// Process messaging events array (standard Messenger Platform format).
		if ( isset( $entry['messaging'] ) && is_array( $entry['messaging'] ) ) {
			foreach ( $entry['messaging'] as $messaging_event ) {
				$this->process_messaging_event( $messaging_event, $page_id );
			}
		}
	}

	/**
	 * Process a single messaging event.
	 *
	 * Dispatches to the appropriate handler based on the event type
	 * as defined in the Messenger Platform webhook documentation.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event   Messaging event data.
	 * @param string $page_id Page ID for the current entry.
	 */
	protected function process_messaging_event( $event, $page_id ) {
		$sender_id    = isset( $event['sender']['id'] ) ? sanitize_text_field( $event['sender']['id'] ) : '';
		$recipient_id = isset( $event['recipient']['id'] ) ? sanitize_text_field( $event['recipient']['id'] ) : '';
		$timestamp    = isset( $event['timestamp'] ) ? absint( $event['timestamp'] ) : time();

		// Determine event type and dispatch to handler.
		if ( isset( $event['message'] ) ) {
			// Echo events: messages sent from the page itself.
			if ( ! empty( $event['message']['is_echo'] ) ) {
				$this->process_message_echo( $event, $sender_id, $recipient_id, $timestamp, $page_id );
			} else {
				$this->process_incoming_message( $event, $sender_id, $recipient_id, $timestamp, $page_id );
			}
		} elseif ( isset( $event['postback'] ) ) {
			$this->process_postback( $event, $sender_id, $recipient_id, $timestamp, $page_id );
		} elseif ( isset( $event['optin'] ) ) {
			$this->process_optin( $event, $sender_id, $recipient_id, $timestamp, $page_id );
		} elseif ( isset( $event['referral'] ) ) {
			$this->process_referral( $event, $sender_id, $recipient_id, $timestamp, $page_id );
		} elseif ( isset( $event['read'] ) ) {
			$this->process_read_receipt( $event, $sender_id, $recipient_id, $timestamp, $page_id );
		} elseif ( isset( $event['delivery'] ) ) {
			$this->process_delivery_receipt( $event, $sender_id, $recipient_id, $timestamp, $page_id );
		} elseif ( isset( $event['reaction'] ) ) {
			$this->process_reaction( $event, $sender_id, $recipient_id, $timestamp, $page_id );
		} else {
			WP_MCP_AI_Logger::log_event(
				'messenger_webhook_unknown_event',
				'Unknown Messenger webhook event type received.',
				array(
					'sender_id'    => substr( $sender_id, 0, 4 ) . '***',
					'event_keys'   => array_keys( $event ),
				)
			);
		}
	}

	/**
	 * Process an incoming message event.
	 *
	 * Handles standard user messages including text and attachments.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event        Messaging event data.
	 * @param string $sender_id    Sender PSID.
	 * @param string $recipient_id Recipient page-scoped ID.
	 * @param int    $timestamp    Event timestamp.
	 * @param string $page_id      Page ID.
	 */
	protected function process_incoming_message( $event, $sender_id, $recipient_id, $timestamp, $page_id ) {
		$message    = $event['message'];
		$message_id = isset( $message['mid'] ) ? sanitize_text_field( $message['mid'] ) : '';

		// Deduplicate: skip if we already started processing this message mid within
		// DEDUP_TRANSIENT_TTL seconds (Meta can retry webhook deliveries).
		if ( ! empty( $message_id ) ) {
			$dedup_key = 'wp_mcp_ai_msng_msg_' . md5( $message_id );
			if ( false !== get_transient( $dedup_key ) ) {
				WP_MCP_AI_Logger::log_event(
					'messenger_incoming_message_duplicate',
					'Duplicate Messenger message skipped.',
					array( 'message_id' => $message_id )
				);
				return;
			}
			set_transient( $dedup_key, 1, self::DEDUP_TRANSIENT_TTL );
		}

		WP_MCP_AI_Logger::log_event(
			'messenger_incoming_message',
			'Incoming Messenger message received.',
			array(
				'message_id'   => $message_id,
				'sender_id'    => substr( $sender_id, 0, 4 ) . '***',
				'type'         => isset( $message['attachments'] ) ? 'attachment' : 'text',
				'timestamp'    => $timestamp,
			)
		);

		$message_data = array(
			'id'           => $message_id,
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
			'page_id'      => $page_id,
			'timestamp'    => $timestamp,
			'text'         => isset( $message['text'] ) ? sanitize_textarea_field( $message['text'] ) : '',
			'attachments'  => isset( $message['attachments'] ) ? $message['attachments'] : array(),
			'nlp'          => isset( $message['nlp'] ) ? $message['nlp'] : array(),
			'reply_to'     => isset( $message['reply_to'] ) ? $message['reply_to'] : null,
			'quick_reply'  => isset( $message['quick_reply'] ) ? $message['quick_reply'] : null,
		);

		/**
		 * Fires when a Messenger message is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $message_data Parsed message data.
		 * @param array  $event        Raw messaging event from webhook.
		 * @param string $page_id      Page ID.
		 */
		do_action( 'wp_mcp_ai_messenger_message_received', $message_data, $event, $page_id );

		// Auto-reply or process with AI assistant if configured.
		$this->maybe_auto_reply( $message_data, $event, $page_id );
	}

	/**
	 * Process a message echo event.
	 *
	 * Echoes are sent when a message is sent from the page itself, allowing
	 * the webhook to track outgoing messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event        Messaging event data.
	 * @param string $sender_id    Sender PSID (the page).
	 * @param string $recipient_id Recipient PSID.
	 * @param int    $timestamp    Event timestamp.
	 * @param string $page_id      Page ID.
	 */
	protected function process_message_echo( $event, $sender_id, $recipient_id, $timestamp, $page_id ) {
		$message    = $event['message'];
		$message_id = isset( $message['mid'] ) ? sanitize_text_field( $message['mid'] ) : '';
		$app_id     = isset( $message['app_id'] ) ? absint( $message['app_id'] ) : 0;

		WP_MCP_AI_Logger::log_event(
			'messenger_message_echo',
			'Messenger message echo received (outgoing message).',
			array(
				'message_id'   => $message_id,
				'recipient_id' => substr( $recipient_id, 0, 4 ) . '***',
				'app_id'       => $app_id,
				'timestamp'    => $timestamp,
			)
		);

		/**
		 * Fires when a Messenger message echo is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $message  Raw message data.
		 * @param array  $event    Raw messaging event from webhook.
		 * @param string $page_id  Page ID.
		 */
		do_action( 'wp_mcp_ai_messenger_message_echo', $message, $event, $page_id );
	}

	/**
	 * Process a postback event.
	 *
	 * Postbacks occur when a user taps a postback button, Get Started button,
	 * or persistent menu item.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event        Messaging event data.
	 * @param string $sender_id    Sender PSID.
	 * @param string $recipient_id Recipient page-scoped ID.
	 * @param int    $timestamp    Event timestamp.
	 * @param string $page_id      Page ID.
	 */
	protected function process_postback( $event, $sender_id, $recipient_id, $timestamp, $page_id ) {
		$postback = $event['postback'];
		$payload  = isset( $postback['payload'] ) ? sanitize_text_field( $postback['payload'] ) : '';
		$title    = isset( $postback['title'] ) ? sanitize_text_field( $postback['title'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'messenger_postback',
			'Messenger postback event received.',
			array(
				'sender_id' => substr( $sender_id, 0, 4 ) . '***',
				'payload'   => $payload,
				'title'     => $title,
				'timestamp' => $timestamp,
			)
		);

		$postback_data = array(
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
			'page_id'      => $page_id,
			'timestamp'    => $timestamp,
			'payload'      => $payload,
			'title'        => $title,
			'referral'     => isset( $postback['referral'] ) ? $postback['referral'] : null,
		);

		/**
		 * Fires when a Messenger postback is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $postback_data Parsed postback data.
		 * @param array  $event         Raw messaging event from webhook.
		 * @param string $page_id       Page ID.
		 */
		do_action( 'wp_mcp_ai_messenger_postback', $postback_data, $event, $page_id );
	}

	/**
	 * Process an opt-in event.
	 *
	 * Opt-in events fire when a user opts in to receive messages, for example
	 * via the checkbox plugin, customer matching, or a send-to-messenger plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event        Messaging event data.
	 * @param string $sender_id    Sender PSID.
	 * @param string $recipient_id Recipient page-scoped ID.
	 * @param int    $timestamp    Event timestamp.
	 * @param string $page_id      Page ID.
	 */
	protected function process_optin( $event, $sender_id, $recipient_id, $timestamp, $page_id ) {
		$optin = $event['optin'];
		$ref   = isset( $optin['ref'] ) ? sanitize_text_field( $optin['ref'] ) : '';
		$type  = isset( $optin['type'] ) ? sanitize_text_field( $optin['type'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'messenger_optin',
			'Messenger opt-in event received.',
			array(
				'sender_id' => substr( $sender_id, 0, 4 ) . '***',
				'ref'       => $ref,
				'type'      => $type,
				'timestamp' => $timestamp,
			)
		);

		$optin_data = array(
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
			'page_id'      => $page_id,
			'timestamp'    => $timestamp,
			'ref'          => $ref,
			'type'         => $type,
			'user_ref'     => isset( $optin['user_ref'] ) ? sanitize_text_field( $optin['user_ref'] ) : '',
		);

		/**
		 * Fires when a Messenger opt-in event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $optin_data Parsed opt-in data.
		 * @param array  $event      Raw messaging event from webhook.
		 * @param string $page_id    Page ID.
		 */
		do_action( 'wp_mcp_ai_messenger_optin', $optin_data, $event, $page_id );
	}

	/**
	 * Process a referral event.
	 *
	 * Referral events fire when a user is referred to the bot via an m.me link
	 * with a ref parameter, an ad, or a sponsored message.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event        Messaging event data.
	 * @param string $sender_id    Sender PSID.
	 * @param string $recipient_id Recipient page-scoped ID.
	 * @param int    $timestamp    Event timestamp.
	 * @param string $page_id      Page ID.
	 */
	protected function process_referral( $event, $sender_id, $recipient_id, $timestamp, $page_id ) {
		$referral = $event['referral'];
		$ref      = isset( $referral['ref'] ) ? sanitize_text_field( $referral['ref'] ) : '';
		$source   = isset( $referral['source'] ) ? sanitize_text_field( $referral['source'] ) : '';
		$type     = isset( $referral['type'] ) ? sanitize_text_field( $referral['type'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'messenger_referral',
			'Messenger referral event received.',
			array(
				'sender_id' => substr( $sender_id, 0, 4 ) . '***',
				'ref'       => $ref,
				'source'    => $source,
				'type'      => $type,
				'timestamp' => $timestamp,
			)
		);

		$referral_data = array(
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
			'page_id'      => $page_id,
			'timestamp'    => $timestamp,
			'ref'          => $ref,
			'source'       => $source,
			'type'         => $type,
		);

		/**
		 * Fires when a Messenger referral event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $referral_data Parsed referral data.
		 * @param array  $event         Raw messaging event from webhook.
		 * @param string $page_id       Page ID.
		 */
		do_action( 'wp_mcp_ai_messenger_referral', $referral_data, $event, $page_id );
	}

	/**
	 * Process a read receipt event.
	 *
	 * Read receipt events fire when a user reads a message sent by the page.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event        Messaging event data.
	 * @param string $sender_id    Sender PSID.
	 * @param string $recipient_id Recipient page-scoped ID.
	 * @param int    $timestamp    Event timestamp.
	 * @param string $page_id      Page ID.
	 */
	protected function process_read_receipt( $event, $sender_id, $recipient_id, $timestamp, $page_id ) {
		$read        = $event['read'];
		$watermark   = isset( $read['watermark'] ) ? absint( $read['watermark'] ) : 0;

		WP_MCP_AI_Logger::log_event(
			'messenger_read_receipt',
			'Messenger read receipt received.',
			array(
				'sender_id' => substr( $sender_id, 0, 4 ) . '***',
				'watermark' => $watermark,
				'timestamp' => $timestamp,
			)
		);

		$read_data = array(
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
			'page_id'      => $page_id,
			'timestamp'    => $timestamp,
			'watermark'    => $watermark,
		);

		/**
		 * Fires when a Messenger read receipt is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $read_data Parsed read receipt data.
		 * @param array  $event     Raw messaging event from webhook.
		 * @param string $page_id   Page ID.
		 */
		do_action( 'wp_mcp_ai_messenger_read_receipt', $read_data, $event, $page_id );
	}

	/**
	 * Process a delivery receipt event.
	 *
	 * Delivery receipt events fire when a message sent by the page is delivered.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event        Messaging event data.
	 * @param string $sender_id    Sender PSID.
	 * @param string $recipient_id Recipient page-scoped ID.
	 * @param int    $timestamp    Event timestamp.
	 * @param string $page_id      Page ID.
	 */
	protected function process_delivery_receipt( $event, $sender_id, $recipient_id, $timestamp, $page_id ) {
		$delivery  = $event['delivery'];
		$watermark = isset( $delivery['watermark'] ) ? absint( $delivery['watermark'] ) : 0;
		$mids      = isset( $delivery['mids'] ) && is_array( $delivery['mids'] ) ? $delivery['mids'] : array();

		WP_MCP_AI_Logger::log_event(
			'messenger_delivery_receipt',
			'Messenger delivery receipt received.',
			array(
				'sender_id'   => substr( $sender_id, 0, 4 ) . '***',
				'watermark'   => $watermark,
				'mids_count'  => count( $mids ),
				'timestamp'   => $timestamp,
			)
		);

		$delivery_data = array(
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
			'page_id'      => $page_id,
			'timestamp'    => $timestamp,
			'watermark'    => $watermark,
			'mids'         => $mids,
		);

		/**
		 * Fires when a Messenger delivery receipt is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $delivery_data Parsed delivery receipt data.
		 * @param array  $event         Raw messaging event from webhook.
		 * @param string $page_id       Page ID.
		 */
		do_action( 'wp_mcp_ai_messenger_delivery_receipt', $delivery_data, $event, $page_id );
	}

	/**
	 * Process a reaction event.
	 *
	 * Reaction events fire when a user reacts to a message.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $event        Messaging event data.
	 * @param string $sender_id    Sender PSID.
	 * @param string $recipient_id Recipient page-scoped ID.
	 * @param int    $timestamp    Event timestamp.
	 * @param string $page_id      Page ID.
	 */
	protected function process_reaction( $event, $sender_id, $recipient_id, $timestamp, $page_id ) {
		$reaction  = $event['reaction'];
		$action    = isset( $reaction['action'] ) ? sanitize_text_field( $reaction['action'] ) : '';
		$emoji     = isset( $reaction['emoji'] ) ? $reaction['emoji'] : '';
		$mid       = isset( $reaction['mid'] ) ? sanitize_text_field( $reaction['mid'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'messenger_reaction',
			'Messenger reaction event received.',
			array(
				'sender_id' => substr( $sender_id, 0, 4 ) . '***',
				'action'    => $action,
				'mid'       => $mid,
				'timestamp' => $timestamp,
			)
		);

		$reaction_data = array(
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
			'page_id'      => $page_id,
			'timestamp'    => $timestamp,
			'action'       => $action,
			'emoji'        => $emoji,
			'mid'          => $mid,
		);

		/**
		 * Fires when a Messenger reaction event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $reaction_data Parsed reaction data.
		 * @param array  $event         Raw messaging event from webhook.
		 * @param string $page_id       Page ID.
		 */
		do_action( 'wp_mcp_ai_messenger_reaction', $reaction_data, $event, $page_id );
	}

	/**
	 * Maybe auto-reply to incoming message.
	 *
	 * Looks up the Messenger connection that matches the page and dispatches an
	 * AI-generated reply via a cron job when assigned assistants are configured.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $message_data Parsed message data.
	 * @param array  $event        Raw messaging event from webhook.
	 * @param string $page_id      Page ID.
	 */
	protected function maybe_auto_reply( $message_data, $event, $page_id ) {
		// Only reply to plain text messages — skip attachments, quick-replies without text, etc.
		$text = isset( $message_data['text'] ) ? trim( $message_data['text'] ) : '';

		// A quick_reply tap also carries the button payload text; use it when the
		// message text is empty (e.g. the user tapped a quick-reply button).
		if ( '' === $text && isset( $message_data['quick_reply'] ) && is_array( $message_data['quick_reply'] ) && ! empty( $message_data['quick_reply']['payload'] ) ) {
			$text = sanitize_text_field( $message_data['quick_reply']['payload'] );
		}

		if ( '' === $text ) {
			return;
		}

		// Find the Messenger connection that serves the page that received the message.
		$connection             = $this->get_connection_by_page_id( $page_id );
		$assigned_assistant_ids = $connection ? $this->get_assigned_assistant_ids( $connection ) : array();

		// When the connection requires an @slug mention, only reply if the message
		// explicitly addresses an assigned assistant by its WordPress post slug.
		if ( ! empty( $connection['require_mention'] ) && ! $this->message_mentions_assistant( $text, $assigned_assistant_ids ) ) {
			return;
		}

		/**
		 * Filter whether to auto-reply to Messenger messages.
		 *
		 * Defaults to true when the connection has one or more assigned AI assistants.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $auto_reply   Whether to auto-reply.
		 * @param array  $message_data Parsed message data.
		 * @param array  $event        Raw messaging event from webhook.
		 * @param string $page_id      Page ID.
		 */
		$should_reply = apply_filters( 'wp_mcp_ai_messenger_should_auto_reply', ! empty( $assigned_assistant_ids ), $message_data, $event, $page_id );

		if ( ! $should_reply ) {
			return;
		}

		do_action( 'wp_mcp_ai_messenger_auto_reply', $message_data, $event, $page_id );

		if ( $connection && ! empty( $assigned_assistant_ids ) ) {
			$this->dispatch_messenger_ai_reply( $message_data, $text, $connection, $assigned_assistant_ids );
		}
	}

	/**
	 * Schedule an asynchronous cron job to generate and send a Messenger AI reply.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $message_data           Parsed message data.
	 * @param string $text                   Plain text to send to the AI.
	 * @param array  $connection             Messenger connection configuration.
	 * @param int[]  $assigned_assistant_ids Assistant post IDs assigned to this connection.
	 */
	protected function dispatch_messenger_ai_reply( $message_data, $text, $connection, $assigned_assistant_ids ) {
		$sender_id     = isset( $message_data['sender_id'] ) ? $message_data['sender_id'] : '';
		$connection_id = isset( $connection['id'] ) ? $connection['id'] : '';

		if ( '' === $sender_id || '' === $connection_id ) {
			return;
		}

		$graph_api_version = isset( $connection['graph_api_version'] ) && $connection['graph_api_version']
			? $connection['graph_api_version']
			: self::DEFAULT_GRAPH_API_VERSION;

		$job_args = array(
			array(
				'assistant_id'      => $assigned_assistant_ids[0],
				'message_text'      => $text,
				'sender_id'         => $sender_id,
				'connection_id'     => $connection_id,
				'graph_api_version' => $graph_api_version,
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();
	}

	/**
	 * Cron callback: generate an AI reply via the chat endpoint and send it via Messenger.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by dispatch_messenger_ai_reply().
	 */
	public function handle_messenger_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id      = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text      = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$sender_id         = isset( $args['sender_id'] ) ? (string) $args['sender_id'] : '';
		$connection_id     = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';
		$graph_api_version = isset( $args['graph_api_version'] ) ? sanitize_text_field( $args['graph_api_version'] ) : self::DEFAULT_GRAPH_API_VERSION;

		if ( ! $assistant_id || '' === $message_text || '' === $sender_id || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error( 'Messenger AI reply: connection not found or access token missing.', array( 'connection_id' => $connection_id ) );
			return;
		}

		$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		if ( '' === $access_token ) {
			WP_MCP_AI_Logger::log_error( 'Messenger AI reply: access token decryption returned empty string.', array( 'connection_id' => $connection_id ) );
			return;
		}

		// Generate AI response via internal chat endpoint.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_body_params(
			array(
				'assistant_id' => $assistant_id,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => $message_text,
					),
				),
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
				'Messenger AI reply: no administrator user found; internal chat request may fail for non-public assistants.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			$error_data = $response->get_data();
			WP_MCP_AI_Logger::log_error(
				'Messenger AI reply: chat request failed.',
				array(
					'assistant_id' => $assistant_id,
					'error_code'   => is_array( $error_data ) && isset( $error_data['code'] ) ? sanitize_text_field( (string) $error_data['code'] ) : '',
				)
			);
			return;
		}

		// Extract text reply from chat endpoint response.
		$data     = $response->get_data();
		$llm_data = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		$choices  = isset( $llm_data['choices'] ) && is_array( $llm_data['choices'] ) ? $llm_data['choices'] : array();
		$content  = '';
		if ( ! empty( $choices ) ) {
			$first = reset( $choices );
			if ( isset( $first['message']['content'] ) && is_string( $first['message']['content'] ) ) {
				$content = $first['message']['content'];
			}
		}

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Messenger AI reply: empty content from assistant.', array( 'assistant_id' => $assistant_id ) );
			return;
		}

		// Messenger does not render HTML; strip tags and cap at 2000 characters.
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( mb_strlen( $content ) > 2000 ) {
			$content = mb_substr( $content, 0, 1997 ) . '...';
		}

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Messenger AI reply: content empty after HTML stripping.', array( 'assistant_id' => $assistant_id ) );
			return;
		}

		// Send reply via Messenger Send API.
		$endpoint = sprintf(
			'https://graph.facebook.com/%s/me/messages',
			rawurlencode( $graph_api_version )
		);

		$payload = array(
			'recipient' => array( 'id' => $sender_id ),
			'message'   => array( 'text' => $content ),
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			WP_MCP_AI_Logger::log_error( 'Messenger AI reply: failed to JSON-encode payload.', array() );
			return;
		}

		$result = wp_remote_post(
			add_query_arg( 'access_token', $access_token, $endpoint ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error( 'Messenger AI reply: HTTP request failed.', array( 'error' => $result->get_error_message() ) );
			return;
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $result );
		$send_body    = wp_remote_retrieve_body( $result );
		$decoded_body = ! empty( $send_body ) ? json_decode( $send_body, true ) : null;
		$api_error    = is_array( $decoded_body ) && isset( $decoded_body['error'] ) ? $decoded_body['error'] : array();

		if ( 200 !== $http_code || ! empty( $api_error ) ) {
			WP_MCP_AI_Logger::log_error(
				'Messenger AI reply: send request returned an error.',
				array(
					'assistant_id' => $assistant_id,
					'http_code'    => $http_code,
					'api_error'    => $api_error,
				)
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'messenger_ai_reply_sent',
			'Messenger AI reply dispatched successfully.',
			array(
				'assistant_id' => $assistant_id,
				'http_code'    => $http_code,
				'sender_id'    => substr( $sender_id, 0, 4 ) . '***',
			)
		);
	}

	/**
	 * Find a Messenger connection matching the given page ID.
	 *
	 * The page ID in the Messenger webhook corresponds to the `recipient.id`
	 * field (the Facebook Page that received the message). Connections that
	 * store a `page_id` are matched first; if none match, the first enabled
	 * Messenger connection is returned as a fallback for single-page setups.
	 *
	 * @since 1.0.0
	 *
	 * @param string $page_id Facebook Page ID from the webhook entry.
	 * @return array|null Connection data array or null if not found.
	 */
	protected function get_connection_by_page_id( $page_id ) {
		$connections = $this->get_messenger_connections();
		$fallback    = null;

		foreach ( $connections as $connection ) {
			if ( isset( $connection['page_id'] ) && $connection['page_id'] === $page_id ) {
				return $connection;
			}
			if ( null === $fallback && ! empty( $connection['api_key'] ) ) {
				$fallback = $connection;
			}
		}

		return $fallback;
	}

	/**
	 * Get the assistant IDs assigned to a Messenger connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return int[] Array of assistant post IDs.
	 */
	protected function get_assigned_assistant_ids( $connection ) {
		if ( ! isset( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) );
	}

	/**
	 * Get verify token from connection settings.
	 *
	 * @since 1.0.0
	 *
	 * @return string Verify token or empty string.
	 */
	protected function get_verify_token() {
		$connections = $this->get_messenger_connections();

		foreach ( $connections as $connection ) {
			if ( isset( $connection['verify_token'] ) && ! empty( $connection['verify_token'] ) ) {
				return $connection['verify_token'];
			}
		}

		return '';
	}

	/**
	 * Get app secret from connection settings.
	 *
	 * For security, this should be the App Secret from Meta Developer Dashboard,
	 * not the access token. The signature is validated using the app secret.
	 *
	 * @since 1.0.0
	 *
	 * @return string App secret or empty string.
	 */
	protected function get_app_secret() {
		$connections = $this->get_messenger_connections();

		foreach ( $connections as $connection ) {
			// Try to get dedicated app secret field first.
			if ( isset( $connection['api_secret'] ) && ! empty( $connection['api_secret'] ) ) {
				if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] );
				}
				return $connection['api_secret'];
			}

			// Fallback: Use access token for signature validation if app secret not set.
			// Note: This is not ideal. App secret should be configured separately.
			if ( isset( $connection['api_key'] ) && ! empty( $connection['api_key'] ) ) {
				if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
				}
				return $connection['api_key'];
			}
		}

		return '';
	}

	/**
	 * Get all Facebook Messenger connections.
	 *
	 * @since 1.0.0
	 *
	 * @return array Messenger connections.
	 */
	protected function get_messenger_connections() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$all_connections      = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$messenger_connections = array();

		foreach ( $all_connections as $connection ) {
			if ( isset( $connection['connection_type'] ) && 'facebook_messenger' === $connection['connection_type'] ) {
				$messenger_connections[] = $connection;
			}
		}

		return $messenger_connections;
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

// Initialize the controller.
new WP_MCP_AI_Messenger_Webhook_Controller();
