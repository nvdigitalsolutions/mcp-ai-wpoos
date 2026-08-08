<?php
/**
 * CRM SMS Inbound Webhook Listener — Twilio
 *
 * Receives inbound SMS messages from Twilio webhooks, validates Twilio's
 * X-Twilio-Signature header, and routes the message to the CRM inbound
 * evaluation pipeline.
 *
 * Webhook URL: /wp-json/mcp-ai-pro/v1/crm/sms-inbound
 * Method: POST
 * Content-Type: application/x-www-form-urlencoded (Twilio default)
 *
 * Twilio sends these form fields:
 *   - MessageSid, SmsSid
 *   - From (sender phone), To (your Twilio number)
 *   - Body (message text)
 *   - NumMedia, NumSegments
 *
 * @package WP_MCP_AI_Pro
 * @since 2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Twilio SMS inbound webhook listener.
 *
 * @since 2.4.0
 */
class WP_MCP_AI_CRM_SMS_Webhook_Listener {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * REST route.
	 */
	const REST_ROUTE = '/crm/sms-inbound';

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
				'permission_callback' => '__return_true', // Public webhook; validated via Twilio signature.
			)
		);
	}

	/**
	 * Handle incoming Twilio SMS webhook.
	 *
	 * GET is supported for the initial Twilio webhook validation/connectivity test.
	 * POST handles actual message delivery.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_webhook( $request ) {
		// GET request: Twilio may probe the URL. Return 200.
		if ( 'GET' === $request->get_method() ) {
			return new WP_REST_Response(
				array(
					'status'  => 'ok',
					'message' => __( 'CRM SMS webhook endpoint ready.', 'mcp-ai-wpoos-pro' ),
				),
				200
			);
		}

		// POST: validate Twilio signature if configured.
		$settings = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::get_toolkit_settings()
			: array();

		$auth_token = $settings['integrations']['twilio_auth_token_secret'] ?? '';

		if ( ! empty( $auth_token ) ) {
			$signature_valid = self::validate_twilio_signature( $auth_token );
			if ( ! $signature_valid ) {
				return new WP_Error(
					'invalid_signature',
					__( 'Invalid Twilio signature.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		// Extract message fields from request body.
		$from_phone = sanitize_text_field( $request->get_param( 'From' ) ?? '' );
		$to_phone   = sanitize_text_field( $request->get_param( 'To' ) ?? '' );
		$body_text  = sanitize_textarea_field( $request->get_param( 'Body' ) ?? '' );

		if ( empty( $from_phone ) || empty( $body_text ) ) {
			return new WP_Error(
				'missing_fields',
				__( 'Missing From or Body in webhook payload.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Route to the CRM inbound pipeline.
		self::route_to_crm( $from_phone, $body_text );

		// Return empty 200 OK with no TwiML (silent; no auto-reply from webhook layer).
		return new WP_REST_Response( '', 200 );
	}

	/**
	 * Validate the X-Twilio-Signature header against the request.
	 *
	 * Uses the Twilio auth token to HMAC-SHA1 sign the full request URL
	 * and POST parameters, then compares against the signature header.
	 *
	 * @param string $auth_token Twilio auth token.
	 * @return bool True if signature is valid.
	 */
	private static function validate_twilio_signature( $auth_token ) {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$signature = isset( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ) )
			: '';

		if ( empty( $signature ) ) {
			return false;
		}

		// Build the full URL (with https forced for Twilio).
		$is_https = isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'];
		$url      = ( $is_https ? 'https://' : 'http://' )
			. sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '' ) )
			. sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

		// Sort POST params by key.
		// phpcs:ignore WordPress.Security.NonceVerification -- External webhook from Twilio/notify.lk; no WordPress nonce available.
		$params = $_POST;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		ksort( $params );

		// Build the data string: URL + concatenated keyvalue pairs.
		$data = $url;
		foreach ( $params as $key => $value ) {
			$data .= $key . $value;
		}

		// HMAC-SHA1 with auth token.
		$expected = base64_encode( hash_hmac( 'sha1', $data, $auth_token, true ) );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Route an inbound SMS message to the CRM evaluation pipeline.
	 *
	 * @param string $from_phone Sender phone number (E.164 or raw).
	 * @param string $body_text  Message body text.
	 */
	private static function route_to_crm( $from_phone, $body_text ) {
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
			'channel'      => 'sms',
			'message_body' => $body_text,
			'sender_phone' => $from_phone,
			'source'       => 'twilio_webhook',
		);
		$context   = array( 'user_id' => 0 );
		$tool->execute( $arguments, $context );
	}
}
