<?php
/**
 * Composio Connect — webhook receiver.
 *
 * Receives trigger events pushed by Composio to a project webhook
 * subscription. The endpoint is unauthenticated by design (provider-to-site
 * callback) and is therefore signature-gated: every payload must carry a
 * valid HMAC-SHA256 signature over the raw request body, computed with the
 * connection's webhook signing secret.
 *
 * Event types handled:
 *  - composio.trigger.message (V3 payload)   → wp_mcp_ai_composio_trigger
 *  - composio.connected_account.expired      → wp_mcp_ai_composio_account_expired
 *  - composio.trigger.disabled               → wp_mcp_ai_composio_trigger_disabled
 *
 * Duplicate deliveries are deduped by event ID with a short TTL transient.
 *
 * Mirrors the Teams/Discord webhook controller pattern
 * (per-connection route + permission_callback doing signature validation).
 *
 * @see https://docs.composio.dev/reference/api-reference/webhook-subscriptions
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio webhook REST controller.
 */
class WP_MCP_AI_Composio_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/composio';

	/**
	 * Dedup transient TTL in seconds.
	 */
	const DEDUP_TTL = HOUR_IN_SECONDS;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes for Composio webhooks.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_signature' ),
				'args'                => array(
					'connection_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Resolve and decrypt the webhook signing secret for a connection.
	 *
	 * @since 1.4.0
	 *
	 * @param string $connection_id Connection ID from the URL.
	 * @return string Secret or empty string when unavailable.
	 */
	protected function get_signing_secret( $connection_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return '';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection || 'composio' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
			return '';
		}

		if ( empty( $connection['enabled'] ) ) {
			return '';
		}

		$secret = isset( $connection['webhook_secret'] ) ? (string) $connection['webhook_secret'] : '';

		if ( '' === $secret ) {
			return '';
		}

		// Stored secrets are always encrypted at rest; decrypt unconditionally
		// (mirrors the Teams controller signing-secret lookup).
		$decrypted = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $secret );

		return null !== $decrypted ? $decrypted : '';
	}

	/**
	 * Validate the incoming webhook signature (permission_callback).
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function validate_signature( $request ) {
		$connection_id = $request->get_param( 'connection_id' );
		$secret        = $this->get_signing_secret( $connection_id );

		if ( '' === $secret ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Webhook authentication is not configured for this connection.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$signature = $request->get_header( 'x-composio-signature' );
		$signature = null === $signature ? '' : $signature;
		if ( '' === $signature ) {
			$signature = $request->get_header( 'composio-signature' );
			$signature = null === $signature ? '' : $signature;
		}

		if ( '' === $signature ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Missing webhook signature.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$raw_body = $request->get_body();

		if ( ! WP_MCP_AI_Composio_Client::verify_webhook_signature( $raw_body, $signature, $secret ) ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'Composio webhook rejected: invalid signature.',
					array( 'connection_id' => $connection_id )
				);
			}
			return false;
		}

		return true;
	}

	/**
	 * Handle an incoming Composio webhook delivery.
	 *
	 * @since 1.4.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$connection_id = $request->get_param( 'connection_id' );
		$payload       = $request->get_json_params();

		// Fall back to a raw body parse when the content-type header is absent
		// or non-standard (providers occasionally send charset variants).
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			$decoded = json_decode( (string) $request->get_body(), true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => 'invalid_payload',
				),
				200
			);
		}

		$event = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';
		if ( '' === $event && isset( $payload['type'] ) ) {
			$event = sanitize_text_field( $payload['type'] );
		}

		// Event-level dedup (idempotent handling of redeliveries).
		$event_id = isset( $payload['id'] ) ? sanitize_text_field( $payload['id'] ) : '';
		if ( '' === $event_id && isset( $payload['event_id'] ) ) {
			$event_id = sanitize_text_field( $payload['event_id'] );
		}

		if ( '' !== $event_id ) {
			$dedup_key = 'wp_mcp_ai_composio_evt_' . md5( $connection_id . '|' . $event . '|' . $event_id );
			if ( false !== get_transient( $dedup_key ) ) {
				return new WP_REST_Response(
					array(
						'status' => 'ok',
						'dedup'  => true,
					),
					200
				);
			}
			set_transient( $dedup_key, 1, self::DEDUP_TTL );
		}

		// Load the connection record once for all downstream consumers.
		$connection = null;
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		}

		if ( null === $connection ) {
			return new WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => 'unknown_connection',
				),
				200
			);
		}

		$event_data = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();

		switch ( $event ) {
			case 'composio.trigger.message':
				do_action(
					'wp_mcp_ai_composio_trigger',
					$connection,
					array(
						'event'   => isset( $event_data['triggerName'] ) ? sanitize_text_field( $event_data['triggerName'] ) : '',
						'payload' => $event_data,
					)
				);
				break;

			case 'composio.connected_account.expired':
				do_action(
					'wp_mcp_ai_composio_account_expired',
					$connection,
					$event_data
				);
				break;

			case 'composio.trigger.disabled':
				do_action(
					'wp_mcp_ai_composio_trigger_disabled',
					$connection,
					$event_data
				);
				break;

			default:
				// Acknowledge unknown-but-valid events so Composio does not
				// retry deliveries the site intentionally does not consume.
				break;
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
}

new WP_MCP_AI_Composio_Webhook_Controller();
