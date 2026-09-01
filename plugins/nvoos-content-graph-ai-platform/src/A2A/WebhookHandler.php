<?php
/**
 * A2A Webhook Handler — processes inbound push notifications.
 *
 * Receives and processes push notification webhooks from remote
 * A2A agents, relaying task updates to the originating context.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @see       https://a2a-protocol.org/latest/specification/
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/a2a/class-wp-mcp-ai-a2a-webhook-handler.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes inbound A2A webhook notifications.
 */
class WebhookHandler {

	/**
	 * Handle an inbound webhook payload.
	 *
	 * @param array $payload The webhook payload (StreamResponse format).
	 * @return true|\WP_Error True on success or error.
	 */
	public static function handle_inbound( $payload ) {
		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'a2a_invalid_webhook',
				__( 'Invalid webhook payload.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Determine the event type from the StreamResponse.
		if ( isset( $payload['task'] ) ) {
			return self::process_task_update( $payload['task'] );
		}

		if ( isset( $payload['statusUpdate'] ) ) {
			return self::process_status_update( $payload['statusUpdate'] );
		}

		if ( isset( $payload['artifactUpdate'] ) ) {
			return self::process_artifact_update( $payload['artifactUpdate'] );
		}

		if ( isset( $payload['message'] ) ) {
			return self::process_message( $payload['message'] );
		}

		return new \WP_Error(
			'a2a_unknown_event',
			__( 'Unknown webhook event type.', 'nvoos-content-graph-ai-platform' )
		);
	}

	/**
	 * Process a task update webhook.
	 *
	 * @param array $task The task data.
	 * @return true
	 */
	protected static function process_task_update( $task ) {
		/**
		 * Fires when an A2A task update is received via webhook.
		 *
		 * @param array $task The task data from the remote agent.
		 */
		do_action( 'wp_mcp_ai_a2a_webhook_task_update', $task );

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			$task_id = isset( $task['id'] ) ? $task['id'] : 'unknown';
			$state   = isset( $task['status']['state'] ) ? $task['status']['state'] : 'unknown';
			\WP_MCP_AI_Logger::log_info(
				sprintf( 'A2A webhook: task %s updated to state %s', $task_id, $state )
			);
		}

		return true;
	}

	/**
	 * Process a status update webhook.
	 *
	 * @param array $update The TaskStatusUpdateEvent data.
	 * @return true
	 */
	protected static function process_status_update( $update ) {
		/**
		 * Fires when an A2A status update is received via webhook.
		 *
		 * @param array $update The status update event data.
		 */
		do_action( 'wp_mcp_ai_a2a_webhook_status_update', $update );

		return true;
	}

	/**
	 * Process an artifact update webhook.
	 *
	 * @param array $update The TaskArtifactUpdateEvent data.
	 * @return true
	 */
	protected static function process_artifact_update( $update ) {
		/**
		 * Fires when an A2A artifact update is received via webhook.
		 *
		 * @param array $update The artifact update event data.
		 */
		do_action( 'wp_mcp_ai_a2a_webhook_artifact_update', $update );

		return true;
	}

	/**
	 * Process a message webhook.
	 *
	 * @param array $message The Message data.
	 * @return true
	 */
	protected static function process_message( $message ) {
		/**
		 * Fires when an A2A message is received via webhook.
		 *
		 * @param array $message The message data from the remote agent.
		 */
		do_action( 'wp_mcp_ai_a2a_webhook_message', $message );

		return true;
	}
}
