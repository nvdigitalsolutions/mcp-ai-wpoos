<?php
/**
 * General-purpose notification system for async WordPress jobs.
 *
 * Provides SSE streams and webhook dispatching for any long-running operation,
 * not limited to crawl4ai. Supports job status updates, progress tracking,
 * and completion notifications.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages real-time notifications for async jobs via SSE and webhooks.
 */
class WP_MCP_AI_Job_Notifier {
	const CACHE_PREFIX         = 'wp_mcp_ai_job_status_';
	const CACHE_DURATION       = 3600; // 1 hour.
	const WEBHOOK_OPTION_KEY   = 'wp_mcp_ai_job_webhooks';
	const MAX_WEBHOOKS_PER_JOB = 10;

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// Hook into existing crawl4ai completion.
		add_action( 'wp_mcp_ai_crawl4ai_job_completed', array( __CLASS__, 'handle_job_completed' ), 10, 3 );

		// Generic hooks for any async operation.
		add_action( 'wp_mcp_ai_job_started', array( __CLASS__, 'handle_job_started' ), 10, 2 );
		add_action( 'wp_mcp_ai_job_progress', array( __CLASS__, 'handle_job_progress' ), 10, 3 );
		add_action( 'wp_mcp_ai_job_completed', array( __CLASS__, 'handle_job_completed' ), 10, 3 );
		add_action( 'wp_mcp_ai_job_failed', array( __CLASS__, 'handle_job_failed' ), 10, 3 );
	}

	/**
	 * Handle job started event.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $metadata Job metadata.
	 */
	public static function handle_job_started( $job_id, $metadata = array() ) {
		$status = array(
			'job_id'     => $job_id,
			'status'     => 'started',
			'started_at' => current_time( 'mysql', true ),
			'metadata'   => $metadata,
		);

		self::cache_job_status( $job_id, $status );
		self::dispatch_webhooks( $job_id, 'started', $status );
	}

	/**
	 * Handle job progress update.
	 *
	 * @param string $job_id   Job identifier.
	 * @param float  $progress Progress percentage (0-100).
	 * @param array  $metadata Additional metadata.
	 */
	public static function handle_job_progress( $job_id, $progress, $metadata = array() ) {
		$status = self::get_job_status( $job_id );

		if ( ! $status ) {
			$status = array(
				'job_id' => $job_id,
				'status' => 'running',
			);
		}

		$status['progress']   = max( 0, min( 100, floatval( $progress ) ) );
		$status['updated_at'] = current_time( 'mysql', true );
		$status['metadata']   = $metadata;

		self::cache_job_status( $job_id, $status );
		self::dispatch_webhooks( $job_id, 'progress', $status );
	}

	/**
	 * Handle job completion.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $result Job result data.
	 * @param array  $metadata Job metadata.
	 */
	public static function handle_job_completed( $job_id, $result = array(), $metadata = array() ) {
		$status = array(
			'job_id'       => $job_id,
			'status'       => 'completed',
			'completed_at' => current_time( 'mysql', true ),
			'result'       => $result,
			'metadata'     => $metadata,
		);

		self::cache_job_status( $job_id, $status );
		self::dispatch_webhooks( $job_id, 'completed', $status );
	}

	/**
	 * Handle job failure.
	 *
	 * @param string         $job_id Job identifier.
	 * @param WP_Error|array $error  Error information.
	 * @param array          $metadata Job metadata.
	 */
	public static function handle_job_failed( $job_id, $error, $metadata = array() ) {
		$error_data = array(
			'message' => is_wp_error( $error ) ? $error->get_error_message() : 'Unknown error',
			'code'    => is_wp_error( $error ) ? $error->get_error_code() : 'unknown_error',
		);

		$status = array(
			'job_id'    => $job_id,
			'status'    => 'failed',
			'failed_at' => current_time( 'mysql', true ),
			'error'     => $error_data,
			'metadata'  => $metadata,
		);

		self::cache_job_status( $job_id, $status );
		self::dispatch_webhooks( $job_id, 'failed', $status );
	}

	/**
	 * Cache job status for SSE retrieval.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $status Status data.
	 * @return bool True on success.
	 */
	protected static function cache_job_status( $job_id, array $status ) {
		$cache_key = self::CACHE_PREFIX . sanitize_key( $job_id );
		return set_transient( $cache_key, $status, self::CACHE_DURATION );
	}

	/**
	 * Retrieve cached job status.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|null Status data or null if not found.
	 */
	public static function get_job_status( $job_id ) {
		$cache_key = self::CACHE_PREFIX . sanitize_key( $job_id );
		$status    = get_transient( $cache_key );

		return is_array( $status ) ? $status : null;
	}

	/**
	 * Register a webhook for job notifications.
	 *
	 * @param string $job_id      Job identifier (or '*' for all jobs).
	 * @param string $webhook_url URL to POST notifications to.
	 * @param array  $events      Events to trigger on (started, progress, completed, failed).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function register_webhook( $job_id, $webhook_url, $events = array() ) {
		if ( ! is_string( $webhook_url ) || ! filter_var( $webhook_url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_webhook_url', __( 'Invalid webhook URL provided.', 'wp-mcp-ai' ) );
		}

		$job_id = sanitize_text_field( $job_id );
		if ( '' === $job_id ) {
			return new WP_Error( 'invalid_job_id', __( 'Invalid job ID provided.', 'wp-mcp-ai' ) );
		}

		if ( empty( $events ) ) {
			$events = array( 'completed', 'failed' );
		}

		$webhooks = get_option( self::WEBHOOK_OPTION_KEY, array() );

		if ( ! isset( $webhooks[ $job_id ] ) ) {
			$webhooks[ $job_id ] = array();
		}

		if ( count( $webhooks[ $job_id ] ) >= self::MAX_WEBHOOKS_PER_JOB ) {
			return new WP_Error( 'too_many_webhooks', __( 'Maximum webhooks per job exceeded.', 'wp-mcp-ai' ) );
		}

		$webhooks[ $job_id ][] = array(
			'url'        => esc_url_raw( $webhook_url ),
			'events'     => array_map( 'sanitize_key', (array) $events ),
			'created_at' => current_time( 'mysql', true ),
		);

		return update_option( self::WEBHOOK_OPTION_KEY, $webhooks );
	}

	/**
	 * Dispatch webhooks for a job event.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $event  Event name (started, progress, completed, failed).
	 * @param array  $data   Event data to send.
	 */
	protected static function dispatch_webhooks( $job_id, $event, $data ) {
		$webhooks = get_option( self::WEBHOOK_OPTION_KEY, array() );

		if ( empty( $webhooks ) ) {
			return;
		}

		// Get webhooks for this specific job and wildcard webhooks.
		$job_webhooks = isset( $webhooks[ $job_id ] ) ? $webhooks[ $job_id ] : array();
		$all_webhooks = isset( $webhooks['*'] ) ? $webhooks['*'] : array();
		$targets      = array_merge( $job_webhooks, $all_webhooks );

		foreach ( $targets as $webhook ) {
			if ( ! isset( $webhook['events'] ) || ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}

			if ( empty( $webhook['url'] ) ) {
				continue;
			}

			// Send webhook asynchronously to avoid blocking.
			$timestamp = time();
			$webhook_args = array(
				'url'     => $webhook['url'],
				'payload' => array(
					'event'   => $event,
					'job_id'  => $job_id,
					'data'    => $data,
					'sent_at' => current_time( 'c', true ),
				),
			);

			wp_schedule_single_event(
				$timestamp,
				'wp_mcp_ai_send_webhook',
				$webhook_args
			);

			// Record in cron manager for visibility and management.
			if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
				// Try to get user_id from metadata or data.
				$user_id = 0;
				if ( isset( $data['metadata']['user_id'] ) ) {
					$user_id = absint( $data['metadata']['user_id'] );
				} elseif ( isset( $data['user_id'] ) ) {
					$user_id = absint( $data['user_id'] );
				} else {
					$user_id = get_current_user_id();
				}

				WP_MCP_AI_Cron_Manager::record_job(
					'wp_mcp_ai_send_webhook',
					$webhook_args,
					'single',
					$timestamp,
					$user_id
				);
			}
		}
	}

	/**
	 * Send a webhook notification.
	 *
	 * Hooked to 'wp_mcp_ai_send_webhook' action for async delivery.
	 *
	 * @param string $url     Webhook URL.
	 * @param array  $payload Payload to send.
	 */
	public static function send_webhook( $url, $payload ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'WP-MCP-AI-Webhook/1.0',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Webhook delivery failed',
				array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				)
			);
		}
	}

	/**
	 * Clean up expired job statuses.
	 *
	 * Should be called periodically via cron.
	 */
	public static function cleanup_expired_jobs() {
		global $wpdb;

		// Clean up old transients.
		$pattern = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$pattern,
				time() - self::CACHE_DURATION
			)
		);
	}
}

// Register webhook sender.
add_action( 'wp_mcp_ai_send_webhook', array( 'WP_MCP_AI_Job_Notifier', 'send_webhook' ), 10, 2 );
