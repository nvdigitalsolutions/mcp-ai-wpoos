<?php
/**
 * A2A Push Notifications — webhook-based task update delivery.
 *
 * Manages push notification configurations and fires webhook
 * notifications when A2A tasks change state.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @see       https://a2a-protocol.org/latest/specification/
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/a2a/class-wp-mcp-ai-a2a-push-notifications.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages A2A push notification configurations and delivery.
 */
class PushNotifications {

	/**
	 * Option key for push notification configs.
	 *
	 * @var string
	 */
	const CONFIGS_OPTION = 'wp_mcp_ai_a2a_push_configs';

	/**
	 * Maximum retry attempts for webhook delivery.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Webhook request timeout in seconds.
	 *
	 * @var int
	 */
	const WEBHOOK_TIMEOUT = 15;

	/**
	 * Create a push notification configuration for a task.
	 *
	 * @param string $task_id The task ID.
	 * @param array  $config  The push notification configuration.
	 * @return array The created configuration with assigned ID.
	 */
	public static function create_config( $task_id, $config ) {
		$config_id = wp_generate_uuid4();

		$push_config = array(
			'id'             => $config_id,
			'taskId'         => sanitize_text_field( $task_id ),
			'url'            => esc_url_raw( $config['url'] ),
			'authentication' => isset( $config['authentication'] ) ? self::sanitize_auth_info( $config['authentication'] ) : null,
			'createdAt'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		$all_configs = self::get_all_configs();

		if ( ! isset( $all_configs[ $task_id ] ) ) {
			$all_configs[ $task_id ] = array();
		}

		$all_configs[ $task_id ][ $config_id ] = $push_config;
		update_option( self::CONFIGS_OPTION, $all_configs, false );

		return $push_config;
	}

	/**
	 * Get a push notification configuration.
	 *
	 * @param string $task_id   The task ID.
	 * @param string $config_id The config ID.
	 * @return array|\WP_Error The configuration or error.
	 */
	public static function get_config( $task_id, $config_id ) {
		$all_configs = self::get_all_configs();

		if ( ! isset( $all_configs[ $task_id ][ $config_id ] ) ) {
			return new \WP_Error(
				'a2a_task_not_found',
				__( 'Push notification configuration not found.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		return $all_configs[ $task_id ][ $config_id ];
	}

	/**
	 * List push notification configurations for a task.
	 *
	 * @param string $task_id The task ID.
	 * @return array Array of configurations.
	 */
	public static function list_configs( $task_id ) {
		$all_configs = self::get_all_configs();

		if ( ! isset( $all_configs[ $task_id ] ) ) {
			return array();
		}

		return array_values( $all_configs[ $task_id ] );
	}

	/**
	 * Delete a push notification configuration.
	 *
	 * @param string $task_id   The task ID.
	 * @param string $config_id The config ID.
	 * @return array|\WP_Error Success or error.
	 */
	public static function delete_config( $task_id, $config_id ) {
		$all_configs = self::get_all_configs();

		if ( ! isset( $all_configs[ $task_id ][ $config_id ] ) ) {
			// Idempotent — already deleted is success.
			return array( 'deleted' => true );
		}

		unset( $all_configs[ $task_id ][ $config_id ] );

		// Clean up empty task entries.
		if ( empty( $all_configs[ $task_id ] ) ) {
			unset( $all_configs[ $task_id ] );
		}

		update_option( self::CONFIGS_OPTION, $all_configs, false );

		return array( 'deleted' => true );
	}

	/**
	 * Fire push notifications for a task.
	 *
	 * Sends webhook POST requests to all configured endpoints for the task.
	 *
	 * @param string $task_id The task ID.
	 */
	public static function fire_notifications( $task_id ) {
		$configs = self::list_configs( $task_id );
		if ( empty( $configs ) ) {
			return;
		}

		$task = TaskManager::get_task( $task_id );
		if ( ! $task ) {
			return;
		}

		// Build the StreamResponse payload.
		$payload = array(
			'task' => $task,
		);

		foreach ( $configs as $config ) {
			self::deliver_notification( $config, $payload );
		}

		// Clean up configs for terminal state tasks.
		if ( TaskManager::is_terminal_state( $task['status']['state'] ) ) {
			self::cleanup_task_configs( $task_id );
		}
	}

	/**
	 * Deliver a webhook notification.
	 *
	 * Note: Uses blocking sleep() for retry delays. This is acceptable because
	 * push notifications are fired after task completion (non-critical path).
	 * For high-volume deployments, consider offloading to WP-Cron via the
	 * wp_mcp_ai_a2a_task_state_change action hook.
	 *
	 * @param array $config  The push notification configuration.
	 * @param array $payload The webhook payload.
	 */
	protected static function deliver_notification( $config, $payload ) {
		$url     = $config['url'];
		$headers = array(
			'Content-Type' => 'application/json',
			'A2A-Version'  => AgentCard::PROTOCOL_VERSION,
		);

		// Apply authentication.
		if ( ! empty( $config['authentication'] ) ) {
			$auth = $config['authentication'];
			if ( ! empty( $auth['scheme'] ) && ! empty( $auth['credentials'] ) ) {
				$headers['Authorization'] = $auth['scheme'] . ' ' . $auth['credentials'];
			}
		}

		$attempt = 0;
		$delay   = 1;

		while ( $attempt < self::MAX_RETRIES ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout'   => self::WEBHOOK_TIMEOUT,
					'sslverify' => true,
					'headers'   => $headers,
					'body'      => wp_json_encode( $payload ),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$status = wp_remote_retrieve_response_code( $response );
				if ( $status >= 200 && $status < 300 ) {
					return; // Success.
				}
			}

			++$attempt;
			if ( $attempt < self::MAX_RETRIES ) {
				sleep( $delay );
				$delay *= 2; // Exponential backoff.
			}
		}

		// Log failure after all retries exhausted.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::warning(
				sprintf(
					'A2A push notification delivery failed after %d attempts for task %s to %s',
					self::MAX_RETRIES,
					$config['taskId'],
					$url
				)
			);
		}
	}

	/**
	 * Clean up push notification configs for a task.
	 *
	 * @param string $task_id The task ID.
	 */
	protected static function cleanup_task_configs( $task_id ) {
		$all_configs = self::get_all_configs();
		unset( $all_configs[ $task_id ] );
		update_option( self::CONFIGS_OPTION, $all_configs, false );
	}

	/**
	 * Get all push notification configs.
	 *
	 * @return array Map of task_id => configs.
	 */
	protected static function get_all_configs() {
		$configs = get_option( self::CONFIGS_OPTION, array() );
		return is_array( $configs ) ? $configs : array();
	}

	/**
	 * Sanitize authentication info.
	 *
	 * @param array $auth The authentication info.
	 * @return array|null Sanitized authentication info.
	 */
	protected static function sanitize_auth_info( $auth ) {
		if ( ! is_array( $auth ) ) {
			return null;
		}

		return array(
			'scheme'      => isset( $auth['scheme'] ) ? sanitize_text_field( $auth['scheme'] ) : '',
			'credentials' => isset( $auth['credentials'] ) ? sanitize_text_field( $auth['credentials'] ) : '',
		);
	}
}
