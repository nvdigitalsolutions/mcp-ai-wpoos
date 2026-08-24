<?php
/**
 * Composio Connect — trigger bridge.
 *
 * Subscribes to the wp_mcp_ai_composio_trigger action fired by the Composio
 * webhook controller and hands trigger payloads to the site's automation
 * surfaces (Pro Workflow Builder / Schedule Manager integrations) through a
 * pluggable handler map.
 *
 * PHP 8.1+ only (Pro addon).
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
 * Composio trigger bridge.
 */
class WP_MCP_AI_Composio_Trigger_Bridge {

	/**
	 * Register hook subscriptions.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_mcp_ai_composio_trigger', array( __CLASS__, 'handle_trigger' ), 10, 2 );
		add_action( 'wp_mcp_ai_composio_account_expired', array( __CLASS__, 'handle_account_expired' ), 10, 2 );
		add_action( 'wp_mcp_ai_composio_trigger_disabled', array( __CLASS__, 'handle_trigger_disabled' ), 10, 2 );
	}

	/**
	 * Dispatch an incoming trigger payload.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Composio connection record.
	 * @param array $payload    Normalised trigger payload (event, payload).
	 * @return void
	 */
	public static function handle_trigger( $connection, $payload ) {
		if ( ! is_array( $connection ) || ! is_array( $payload ) ) {
			return;
		}

		$trigger_name = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';
		$data         = isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : array();

		if ( '' === $trigger_name ) {
			return;
		}

		/**
		 * Filter the default handler map for Composio trigger events.
		 *
		 * Keys are trigger slugs (e.g. gmail.message.new) and values are
		 * callables receiving ( $connection, $data ). Returning false from a
		 * callable stops further processing.
		 *
		 * @since 1.4.0
		 *
		 * @param array $handlers Trigger slug => callable map.
		 */
		$handlers = apply_filters( 'wp_mcp_ai_composio_trigger_handlers', array() );

		// Built-in handling: persist a structured event for downstream
		// consumers (Workflow Builder, Schedule Manager) and audit log.
		do_action(
			'wp_mcp_ai_composio_trigger_received',
			array(
				'connection_id' => isset( $connection['id'] ) ? $connection['id'] : '',
				'trigger'       => $trigger_name,
				'data'          => $data,
				'time'          => time(),
			)
		);

		if ( isset( $handlers[ $trigger_name ] ) && is_callable( $handlers[ $trigger_name ] ) ) {
			call_user_func( $handlers[ $trigger_name ], $connection, $data );
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'composio_trigger_received',
				/* translators: %s: trigger slug */
				sprintf( __( 'Composio trigger received: %s', 'mcp-ai-wpoos-pro' ), $trigger_name ),
				array(
					'connection_id' => isset( $connection['id'] ) ? $connection['id'] : '',
					'trigger'       => $trigger_name,
				)
			);
		}
	}

	/**
	 * Handle a composio.connected_account.expired event.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Composio connection record.
	 * @param array $payload    Event payload.
	 * @return void
	 */
	public static function handle_account_expired( $connection, $payload ) {
		if ( ! is_array( $connection ) || ! is_array( $payload ) ) {
			return;
		}

		$account_id = isset( $payload['connected_account_id'] ) ? sanitize_text_field( $payload['connected_account_id'] ) : '';

		// The toolkit lets the health ledger build a reconnect link without an
		// extra API round-trip. v3.1 nests it under { slug: ... }.
		$toolkit = isset( $payload['toolkit'] ) ? $payload['toolkit'] : '';
		if ( is_array( $toolkit ) ) {
			$toolkit = isset( $toolkit['slug'] ) ? $toolkit['slug'] : '';
		}
		$toolkit = sanitize_key( (string) $toolkit );

		if ( '' !== $account_id && class_exists( 'WP_MCP_AI_Composio_Auth_Handler' ) ) {
			WP_MCP_AI_Composio_Auth_Handler::mark_account_expired(
				isset( $connection['id'] ) ? $connection['id'] : '',
				$account_id,
				$toolkit
			);
		}

		do_action( 'wp_mcp_ai_composio_account_expired_notify', $connection, $account_id );
	}

	/**
	 * Handle a composio.trigger.disabled event.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Composio connection record.
	 * @param array $payload    Event payload.
	 * @return void
	 */
	public static function handle_trigger_disabled( $connection, $payload ) {
		if ( ! is_array( $connection ) ) {
			return;
		}

		do_action( 'wp_mcp_ai_composio_trigger_disabled_notify', $connection, is_array( $payload ) ? $payload : array() );
	}
}
