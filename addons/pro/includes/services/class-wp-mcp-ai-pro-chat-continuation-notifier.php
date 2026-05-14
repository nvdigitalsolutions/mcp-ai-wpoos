<?php
/**
 * Pro Chat-Continuation Multi-Channel Notifier.
 *
 * When an async-tool continuation is fully dispatched
 * (`wp_mcp_ai_chat_continuation_dispatched` action) this service posts a
 * compact JSON summary to a user-configured webhook URL.  It is the
 * lightweight, always-available delivery channel; richer channels
 * (Web Push, email) can be layered on by hooking the same action.
 *
 * ## Configuration (WordPress options / admin UI)
 *
 * | Option key                                    | Type   | Default |
 * |-----------------------------------------------|--------|---------|
 * | `wp_mcp_ai_pro_continuation_notify_url`       | string | ''      |
 * | `wp_mcp_ai_pro_continuation_notify_secret`    | string | ''      |
 * | `wp_mcp_ai_pro_continuation_notify_enabled`   | bool   | true    |
 *
 * The secret, if set, is included as `X-WP-MCP-AI-Signature` (HMAC-SHA256
 * of the raw JSON body, matching the same scheme as the job-webhook system).
 *
 * ## Filters
 *
 * - `wp_mcp_ai_pro_continuation_notify_payload` — modify the outbound payload.
 *   `( array $payload, array $snapshot, string $terminal_status )`
 * - `wp_mcp_ai_pro_continuation_notify_args` — modify `wp_remote_post` args.
 *   `( array $args, string $url, array $payload )`
 * - `wp_mcp_ai_pro_continuation_notify_enabled` — runtime kill-switch.
 *   `( bool $enabled, array $snapshot )`
 *
 * ## Actions
 *
 * - `wp_mcp_ai_pro_continuation_notified` — fires after a successful HTTP 2xx
 *   response.  `( string $job_id, string $url, array $payload )`
 * - `wp_mcp_ai_pro_continuation_notify_failed` — fires on HTTP error or
 *   non-2xx response.  `( string $job_id, string $url, WP_Error|array $response )`
 *
 * @package WP_MCP_AI_Pro
 * @since   1.9.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_Chat_Continuation_Notifier
 */
class WP_MCP_AI_Pro_Chat_Continuation_Notifier {

	/**
	 * Option: webhook URL.
	 */
	const OPTION_URL = 'wp_mcp_ai_pro_continuation_notify_url';

	/**
	 * Option: shared secret for HMAC signature.
	 */
	const OPTION_SECRET = 'wp_mcp_ai_pro_continuation_notify_secret';

	/**
	 * Option: enabled flag.
	 */
	const OPTION_ENABLED = 'wp_mcp_ai_pro_continuation_notify_enabled';

	/**
	 * HTTP request timeout in seconds.
	 */
	const REQUEST_TIMEOUT = 10;

	// ── Bootstrap ──────────────────────────────────────────────────────────────

	/**
	 * Register WordPress hooks.
	 *
	 * Called from the Pro init file.  Idempotent — WP_Hook deduplicates same
	 * callback/priority pairs automatically.
	 */
	public static function init() {
		add_action(
			'wp_mcp_ai_chat_continuation_dispatched',
			array( __CLASS__, 'on_continuation_dispatched' ),
			10,
			3
		);
	}

	// ── Hook handler ───────────────────────────────────────────────────────────

	/**
	 * Handle the `wp_mcp_ai_chat_continuation_dispatched` action.
	 *
	 * Fires when the cron worker has successfully driven a continuation to
	 * completion (LLM responded, frame buffered, frame in SSE channel).
	 *
	 * @param string $job_id          Async job identifier.
	 * @param array  $snapshot        Continuation snapshot.
	 * @param string $terminal_status completed|failed|cancelled.
	 */
	public static function on_continuation_dispatched( $job_id, $snapshot, $terminal_status ) {
		$url = self::get_url();
		if ( '' === $url ) {
			return;
		}

		/**
		 * Runtime kill-switch.
		 *
		 * @since 1.9.5
		 *
		 * @param bool  $enabled  Whether to send the notification.
		 * @param array $snapshot Continuation snapshot.
		 */
		$enabled = apply_filters(
			'wp_mcp_ai_pro_continuation_notify_enabled',
			(bool) get_option( self::OPTION_ENABLED, true ),
			$snapshot
		);

		if ( ! $enabled ) {
			return;
		}

		$payload = self::build_payload( $job_id, $snapshot, $terminal_status );

		/**
		 * Modify or extend the outbound webhook payload.
		 *
		 * @since 1.9.5
		 *
		 * @param array  $payload         Payload array before JSON encoding.
		 * @param array  $snapshot        Continuation snapshot.
		 * @param string $terminal_status Job terminal status.
		 */
		$payload = apply_filters(
			'wp_mcp_ai_pro_continuation_notify_payload',
			$payload,
			$snapshot,
			$terminal_status
		);

		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return;
		}

		self::dispatch( $job_id, $url, $payload );
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Build the outbound payload.
	 *
	 * @param string $job_id          Job identifier.
	 * @param array  $snapshot        Continuation snapshot.
	 * @param string $terminal_status completed|failed|cancelled.
	 *
	 * @return array Associative payload array ready for JSON encoding.
	 */
	protected static function build_payload( $job_id, array $snapshot, $terminal_status ) {
		return array(
			'event'           => 'chat.continuation.dispatched',
			'job_id'          => sanitize_text_field( $job_id ),
			'session_id'      => isset( $snapshot['chat_session_id'] )
				? sanitize_text_field( $snapshot['chat_session_id'] )
				: '',
			'assistant_id'    => isset( $snapshot['assistant_id'] ) ? (int) $snapshot['assistant_id'] : 0,
			'user_id'         => isset( $snapshot['user_id'] ) ? (int) $snapshot['user_id'] : 0,
			'tool_name'       => isset( $snapshot['tool_name'] )
				? sanitize_text_field( $snapshot['tool_name'] )
				: '',
			'terminal_status' => sanitize_text_field( $terminal_status ),
			'occurred_at'     => isset( $snapshot['terminal_at'] ) ? (int) $snapshot['terminal_at'] : time(),
			'site_url'        => esc_url_raw( home_url() ),
		);
	}

	/**
	 * POST the payload to the configured webhook URL.
	 *
	 * @param string $job_id  Job identifier (for action hooks).
	 * @param string $url     Destination URL.
	 * @param array  $payload Payload to send.
	 */
	protected static function dispatch( $job_id, $url, array $payload ) {
		$body    = wp_json_encode( $payload );
		$headers = array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'NV-oOS-Continuation-Notifier/1.9.5 (WordPress/' . get_bloginfo( 'version' ) . ')',
		);

		$secret = self::get_secret();
		if ( '' !== $secret ) {
			$headers['X-WP-MCP-AI-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$args = array(
			'method'      => 'POST',
			'timeout'     => self::REQUEST_TIMEOUT,
			'redirection' => 3,
			'headers'     => $headers,
			'body'        => $body,
			'sslverify'   => apply_filters( 'wp_mcp_ai_remote_ssl_verify', true ),
		);

		/**
		 * Modify `wp_remote_post` arguments before sending.
		 *
		 * @since 1.9.5
		 *
		 * @param array  $args    `wp_remote_post` args.
		 * @param string $url     Destination URL.
		 * @param array  $payload Payload array.
		 */
		$args = apply_filters( 'wp_mcp_ai_pro_continuation_notify_args', $args, $url, $payload );

		$response = wp_remote_post( esc_url_raw( $url ), $args );

		if ( is_wp_error( $response ) ) {
			/**
			 * Fires when the webhook delivery fails with a WP_Error (network issue).
			 *
			 * @since 1.9.5
			 *
			 * @param string   $job_id   Job identifier.
			 * @param string   $url      Destination URL.
			 * @param WP_Error $response WP_Error object.
			 */
			do_action( 'wp_mcp_ai_pro_continuation_notify_failed', $job_id, $url, $response );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			/** This action is documented above. */
			do_action( 'wp_mcp_ai_pro_continuation_notify_failed', $job_id, $url, $response );
			return;
		}

		/**
		 * Fires after a successful (HTTP 2xx) webhook delivery.
		 *
		 * @since 1.9.5
		 *
		 * @param string $job_id  Job identifier.
		 * @param string $url     Destination URL.
		 * @param array  $payload Payload that was sent.
		 */
		do_action( 'wp_mcp_ai_pro_continuation_notified', $job_id, $url, $payload );
	}

	/**
	 * Return the configured webhook URL (empty string if not set or not HTTPS).
	 *
	 * HTTP is accepted for local dev (non-public URLs); HTTPS is required for
	 * public URLs to avoid leaking session data over plain-text connections.
	 *
	 * @return string
	 */
	protected static function get_url() {
		$raw = (string) get_option( self::OPTION_URL, '' );
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$url = esc_url_raw( $raw );
		if ( '' === $url || ! preg_match( '/^https?:\/\//i', $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Return the configured shared secret.
	 *
	 * @return string
	 */
	protected static function get_secret() {
		return (string) get_option( self::OPTION_SECRET, '' );
	}
}
