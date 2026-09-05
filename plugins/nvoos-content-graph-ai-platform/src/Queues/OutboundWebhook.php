<?php
/**
 * Outbound webhook manager for the Content Graph AI Platform addon.
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Outbound_Webhook`
 * (Wave E2): byte-identical `wp_mcp_ai_outbound_webhooks` option key,
 * subscription lifecycle (`whk_*` lowercase IDs, sanitized URL/events,
 * enabled flag), signed non-blocking dispatch (JSON body, `sha256=`
 * HMAC header pair, delivery + event headers), inbound signature
 * verification via `hash_equals`, and the four core event listeners
 * (`wp_mcp_ai_workflow_run_completed|failed|paused`,
 * `wp_mcp_ai_approval_requested`).
 *
 * Decoupling (documented, additive):
 * - The singleton is instantiated standalone-only via
 *   `Plugin::registerOutboundWebhook()` (boot-gated on
 *   `defined( 'WP_MCP_AI_PATH' )`) — the base plugin's loader owns the
 *   same listener registration in monolith installs, and double
 *   registration would double-dispatch every event. `get_instance()`
 *   itself stays public and un-gated (byte-identical surface), so the
 *   eventual workflow (E1) and approvals (E3) ports can consume it in
 *   both modes without touching the base.
 *
 * @package NvoosContentGraphAiPlatform\Queues
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Queues;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages outbound webhook subscriptions.
 *
 * Subscriptions are stored in the WordPress options table under the
 * `wp_mcp_ai_outbound_webhooks` key as a serialised array.
 *
 * @since 2.1.0
 */
class OutboundWebhook {

	/**
	 * Option key for subscription storage — byte-identical.
	 */
	const OPTION_KEY = 'wp_mcp_ai_outbound_webhooks';

	/**
	 * Singleton instance.
	 *
	 * @var OutboundWebhook|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return OutboundWebhook
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — registers event listeners for core plugin events.
	 */
	private function __construct() {
		add_action( 'wp_mcp_ai_workflow_run_completed', array( $this, 'on_workflow_completed' ), 10, 2 );
		add_action( 'wp_mcp_ai_workflow_run_failed', array( $this, 'on_workflow_failed' ), 10, 2 );
		add_action( 'wp_mcp_ai_workflow_run_paused', array( $this, 'on_workflow_paused' ), 10, 2 );
		add_action( 'wp_mcp_ai_approval_requested', array( $this, 'on_approval_requested' ), 10, 2 );
	}

	// ── Subscription Management ─────────────────────────────────────

	/**
	 * Subscribe a URL to one or more events.
	 *
	 * @param string $url    Target URL (must be HTTPS or http for local dev).
	 * @param array  $events Array of event name strings.
	 * @param string $secret Optional HMAC signing secret.
	 * @return string Webhook ID.
	 */
	public function subscribe( $url, array $events, $secret = '' ) {
		$url    = esc_url_raw( $url );
		$secret = sanitize_text_field( $secret );
		$id     = 'whk_' . strtolower( wp_generate_password( 16, false ) ); // Lowercase so sanitize_key() lookups in unsubscribe() match the stored key.

		$subscriptions = $this->load_subscriptions();

		$subscriptions[ $id ] = array(
			'id'         => $id,
			'url'        => $url,
			// Event names are dotted (e.g. workflow.completed); match the
			// sanitizer used by dispatch() and the REST boundary.
			'events'     => array_map( 'sanitize_text_field', $events ),
			'secret'     => $secret,
			'enabled'    => true,
			'created_at' => time(),
		);

		$this->save_subscriptions( $subscriptions );

		return $id;
	}

	/**
	 * Unsubscribe a webhook by ID.
	 *
	 * @param string $webhook_id Webhook ID returned by subscribe().
	 * @return bool True on success, false if not found.
	 */
	public function unsubscribe( $webhook_id ) {
		$webhook_id    = sanitize_key( $webhook_id );
		$subscriptions = $this->load_subscriptions();

		if ( ! isset( $subscriptions[ $webhook_id ] ) ) {
			return false;
		}

		unset( $subscriptions[ $webhook_id ] );
		$this->save_subscriptions( $subscriptions );

		return true;
	}

	/**
	 * List all webhook subscriptions.
	 *
	 * @return array
	 */
	public function list_subscriptions() {
		return array_values( $this->load_subscriptions() );
	}

	// ── Dispatch ────────────────────────────────────────────────────

	/**
	 * Dispatch an event to all matching subscriptions.
	 *
	 * @param string $event   Event name (e.g. 'workflow.completed').
	 * @param array  $payload Payload data to send as JSON.
	 * @return int Number of webhooks dispatched.
	 */
	public function dispatch( $event, array $payload ) {
		$event         = sanitize_text_field( $event );
		$subscriptions = $this->load_subscriptions();
		$count         = 0;

		foreach ( $subscriptions as $sub ) {
			if ( empty( $sub['enabled'] ) ) {
				continue;
			}
			if ( ! in_array( $event, $sub['events'], true ) && ! in_array( '*', $sub['events'], true ) ) {
				continue;
			}

			$body      = wp_json_encode(
				array(
					'event'      => $event,
					'payload'    => $payload,
					'dispatched' => time(),
				)
			);
			$delivery  = wp_generate_password( 12, false );
			$signature = $this->build_signature( $body, $sub['secret'] );

			wp_remote_post(
				$sub['url'],
				array(
					'timeout'  => 5,
					'blocking' => false,
					'body'     => $body,
					'headers'  => array(
						'Content-Type'              => 'application/json',
						'X-WP-MCP-AI-Event'         => $event,
						'X-WP-MCP-AI-Delivery'      => $delivery,
						'X-WP-MCP-AI-Signature-256' => 'sha256=' . $signature,
					),
				)
			);

			++$count;
		}

		return $count;
	}

	/**
	 * Verify an inbound HMAC-SHA256 signature.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $signature Value of X-WP-MCP-AI-Signature-256 header (including 'sha256=' prefix).
	 * @param string $secret    Shared secret.
	 * @return bool
	 */
	public function verify_signature( $payload, $signature, $secret ) {
		$expected = 'sha256=' . $this->build_signature( $payload, $secret );
		return hash_equals( $expected, $signature );
	}

	// ── Event Listeners ─────────────────────────────────────────────

	/**
	 * Handle workflow completed event.
	 *
	 * @param int   $run_id      Workflow run post ID.
	 * @param array $run_details Run detail array.
	 * @return void
	 */
	public function on_workflow_completed( $run_id, $run_details ) {
		$this->dispatch(
			'workflow.completed',
			array(
				'run_id'  => absint( $run_id ),
				'details' => $run_details,
			)
		);
	}

	/**
	 * Handle workflow failed event.
	 *
	 * @param int   $run_id      Workflow run post ID.
	 * @param array $run_details Run detail array.
	 * @return void
	 */
	public function on_workflow_failed( $run_id, $run_details ) {
		$this->dispatch(
			'workflow.failed',
			array(
				'run_id'  => absint( $run_id ),
				'details' => $run_details,
			)
		);
	}

	/**
	 * Handle workflow paused event.
	 *
	 * @param int   $run_id      Workflow run post ID.
	 * @param array $run_details Run detail array.
	 * @return void
	 */
	public function on_workflow_paused( $run_id, $run_details ) {
		$this->dispatch(
			'workflow.paused',
			array(
				'run_id'  => absint( $run_id ),
				'details' => $run_details,
			)
		);
	}

	/**
	 * Handle approval requested event.
	 *
	 * @param int   $approval_id Approval queue post ID.
	 * @param array $details     Approval detail array.
	 * @return void
	 */
	public function on_approval_requested( $approval_id, $details ) {
		$this->dispatch(
			'approval.requested',
			array(
				'approval_id' => absint( $approval_id ),
				'details'     => $details,
			)
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────

	/**
	 * Build a hex HMAC-SHA256 signature.
	 *
	 * @param string $body   Raw payload string.
	 * @param string $secret Signing secret (may be empty).
	 * @return string Hex digest.
	 */
	private function build_signature( $body, $secret ) {
		if ( empty( $secret ) ) {
			return hash( 'sha256', $body );
		}
		return hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Load subscriptions from the options table.
	 *
	 * @return array
	 */
	private function load_subscriptions() {
		$data = get_option( self::OPTION_KEY, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Persist subscriptions to the options table.
	 *
	 * @param array $subscriptions Keyed subscriptions array.
	 * @return void
	 */
	private function save_subscriptions( array $subscriptions ): void {
		update_option( self::OPTION_KEY, $subscriptions, false );
	}
}
