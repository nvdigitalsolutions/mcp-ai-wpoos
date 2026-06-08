<?php
/**
 * CRM WhatsApp Inbound Webhook Listener — Meta Cloud API
 *
 * Receives inbound WhatsApp messages from the Meta WhatsApp Business
 * webhook, validates the X-Hub-Signature-256 header, and routes the
 * message to the CRM inbound evaluation pipeline.
 *
 * Webhook URL: /wp-json/mcp-ai-pro/v1/crm/whatsapp-inbound
 * Method: POST (and GET for webhook verification)
 * Content-Type: application/json
 *
 * Meta sends two types of requests:
 *   1. GET  — Webhook verification (hub.mode=subscribe, hub.challenge, hub.verify_token)
 *   2. POST — Message notifications (entry[].changes[].value.messages[])
 *
 * @package WP_MCP_AI_Pro
 * @since 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta WhatsApp inbound webhook listener.
 *
 * @since 2.4.0
 */
class WP_MCP_AI_CRM_WhatsApp_Webhook_Listener {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * REST route.
	 */
	const REST_ROUTE = '/crm/whatsapp-inbound';

	/**
	 * Register the REST route.
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true', // Public webhook; validated via Hub signature.
			)
		);
	}

	/**
	 * Handle incoming Meta WhatsApp webhook.
	 *
	 * GET  — Webhook verification (respond with hub.challenge).
	 * POST — Inbound message notification.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_webhook( $request ) {
		// GET: Webhook verification from Meta.
		if ( 'GET' === $request->get_method() ) {
			return self::handle_verification( $request );
		}

		// POST: Validate Hub signature if configured.
		$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();

		$app_secret = $settings['integrations']['whatsapp_app_secret'] ?? '';

		if ( ! empty( $app_secret ) ) {
			$signature_valid = self::validate_hub_signature( $app_secret );
			if ( ! $signature_valid ) {
				return new WP_Error(
					'invalid_signature',
					__( 'Invalid Meta webhook signature.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		// Parse the webhook payload.
		$body   = $request->get_body();
		$parsed = json_decode( $body, true );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Invalid webhook payload: not valid JSON.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Process each entry in the webhook.
		$entries = isset( $parsed['entry'] ) ? (array) $parsed['entry'] : array();
		foreach ( $entries as $entry ) {
			$changes = isset( $entry['changes'] ) ? (array) $entry['changes'] : array();
			foreach ( $changes as $change ) {
				$value = isset( $change['value'] ) ? (array) $change['value'] : array();

				// Extract metadata.
				$display_phone_number = sanitize_text_field( $value['display_phone_number'] ?? '' );
				$phone_number_id      = sanitize_text_field( $value['phone_number_id'] ?? '' );

				// Process messages (inbound texts).
				$messages = isset( $value['messages'] ) ? (array) $value['messages'] : array();
				foreach ( $messages as $msg ) {
					$from_phone = sanitize_text_field( $msg['from'] ?? '' );
					$msg_id     = sanitize_text_field( $msg['id'] ?? '' );
					$msg_type   = sanitize_key( $msg['type'] ?? 'text' );
					$timestamp  = isset( $msg['timestamp'] ) ? absint( $msg['timestamp'] ) : 0;

					// Extract message content based on type.
					$content = '';
					if ( 'text' === $msg_type && isset( $msg['text']['body'] ) ) {
						$content = sanitize_textarea_field( $msg['text']['body'] );
					} elseif ( isset( $msg[ $msg_type ] ) ) {
						// Handle other message types (image, audio, etc.) by noting the type.
						$content = sprintf(
							/* translators: %s: WhatsApp message type (image, audio, document, etc.) */
							__( '[%s message — see WhatsApp for content]', 'mcp-ai-wpoos-pro' ),
							$msg_type
						);
					}

					if ( empty( $from_phone ) || empty( $content ) ) {
						continue;
					}

					// Route to the CRM inbound pipeline.
					self::route_to_crm( $from_phone, $content, $msg_type, $msg_id );
				}

				// Process statuses (delivered, read, etc.) if needed — logged for now.
				$statuses = isset( $value['statuses'] ) ? (array) $value['statuses'] : array();
				foreach ( $statuses as $status ) {
					// Status updates can be used for delivery tracking.
					// Logged for future use.
				}
			}
		}

		return new WP_REST_Response( array( 'status' => 'received' ), 200 );
	}

	/**
	 * Handle Meta webhook verification (GET request).
	 *
	 * Meta sends:
	 *   ?hub.mode=subscribe&hub.challenge=12345&hub.verify_token=abc
	 *
	 * Respond with the challenge value if verify_token matches.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function handle_verification( $request ) {
		$mode         = $request->get_param( 'hub_mode' );
		$challenge    = $request->get_param( 'hub_challenge' );
		$verify_token = $request->get_param( 'hub_verify_token' );

		// Get the configured verify token from settings.
		$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();

		$configured_token = $settings['integrations']['whatsapp_webhook_verify_token'] ?? '';

		// Default verify token if none configured (fallback for setup).
		if ( empty( $configured_token ) ) {
			$configured_token = 'wp_mcp_ai_crm_whatsapp_webhook';
		}

		if ( 'subscribe' === $mode && $verify_token === $configured_token ) {
			if ( ! empty( $challenge ) ) {
				return new WP_REST_Response( $challenge, 200 );
			}
			return new WP_REST_Response( 'ok', 200 );
		}

		return new WP_Error(
			'verification_failed',
			__( 'Webhook verification failed: verify_token mismatch.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Validate the X-Hub-Signature-256 header against the raw request body.
	 *
	 * Meta signs the raw JSON body with HMAC-SHA256 using the app secret.
	 * Signature format: sha256=<hex-encoded-hmac>
	 *
	 * @param string $app_secret Meta WhatsApp app secret.
	 * @return bool True if signature is valid.
	 */
	private static function validate_hub_signature( $app_secret ) {
		$signature = isset( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) )
			: '';

		if ( empty( $signature ) ) {
			return false;
		}

		// Strip "sha256=" prefix.
		if ( 0 === strpos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		// Get raw request body.
		$raw_body = file_get_contents( 'php://input' );

		// Compute expected signature.
		$expected = hash_hmac( 'sha256', $raw_body, $app_secret, false );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Route an inbound WhatsApp message to the CRM evaluation pipeline.
	 *
	 * @param string $from_phone Sender phone number (E.164 format, digits only from Meta).
	 * @param string $body_text  Message body text.
	 * @param string $msg_type   WhatsApp message type (text, image, etc.).
	 * @param string $msg_id     WhatsApp message ID.
	 */
	private static function route_to_crm( $from_phone, $body_text, $msg_type, $msg_id ) {
		$_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-evaluate-inbound-message.php';
		if ( ! file_exists( $_tool_file ) ) {
			return;
		}
		require_once $_tool_file;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Evaluate_Inbound_Message' ) ) {
			return;
		}

		$tool      = new WP_MCP_AI_Tool_Evaluate_Inbound_Message();
		$arguments = array(
			'channel'      => 'whatsapp',
			'message_body' => $body_text,
			'sender_phone' => $from_phone,
			'source'       => 'whatsapp_webhook',
		);
		$context   = array( 'user_id' => 0 );
		$tool->execute( $arguments, $context );
	}
}
