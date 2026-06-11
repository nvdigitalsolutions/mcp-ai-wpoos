<?php
/**
 * Schedule Anything — Usage Tracker
 *
 * Reports per-tenant usage metrics to the Cloud Worker for billing
 * and analytics. Runs on a 15-minute heartbeat via WP-Cron.
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Usage tracking and heartbeat sender.
 *
 * @since 0.1.0
 */
class SA_Usage_Tracker {

	/**
	 * Cloud Worker API URL for usage reporting.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	const HEARTBEAT_URL = 'https://api.scheduleanything.com/v1/usage/heartbeat';

	/**
	 * Option key for storing the last heartbeat timestamp.
	 *
	 * @var string
	 */
	const LAST_HEARTBEAT_OPTION = 'sa_last_usage_heartbeat';

	/**
	 * Option key for caching usage stats between heartbeats.
	 *
	 * @var string
	 */
	const USAGE_CACHE_OPTION = 'sa_usage_cache';

	/**
	 * Send a usage heartbeat to the Cloud Worker.
	 *
	 * Collects metrics from the current blog context and POSTs them
	 * to the Cloud Worker's usage endpoint. Runs on every subsite
	 * independently via the sa_usage_heartbeat cron hook.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if heartbeat sent successfully, false on failure.
	 */
	public static function send_heartbeat() {
		$metrics = self::collect_metrics();

		$response = wp_remote_post(
			self::HEARTBEAT_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'X-SaaS-API-Key' => defined( 'SA_SAAS_API_KEY' ) ? SA_SAAS_API_KEY : '',
					'X-Blog-ID'      => (string) get_current_blog_id(),
				),
				'body'    => wp_json_encode( $metrics ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Log the failure but don't throw — heartbeat is best-effort.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'SA Usage Tracker: heartbeat failed — ' . $response->get_error_message() );
			}
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return false;
		}

		update_option( self::LAST_HEARTBEAT_OPTION, time() );
		return true;
	}

	/**
	 * Collect usage metrics from the current tenant subsite.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int|float> Usage metrics.
	 */
	public static function collect_metrics() {
		global $wpdb;

		$blog_id = get_current_blog_id();
		$metrics = array(
			'blog_id'   => $blog_id,
			'timestamp' => time(),
			'date'      => gmdate( 'Y-m-d' ),
		);

		// Count active schedules.
		if ( class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			$schedules                   = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
			$metrics['active_schedules'] = count( $schedules );
		} else {
			$metrics['active_schedules'] = 0;
		}

		// Count appointments (from custom table if available, fallback to CPT).
		$table_name = $wpdb->base_prefix . 'mcp_appointments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		if ( $table_exists ) {
			// Table name comes from SHOW TABLES result — safe to use directly.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$metrics['total_appointments'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table_name} WHERE blog_id = %d",
					$blog_id
				)
			);
		} else {
			// Fallback: count CPT posts.
			$metrics['total_appointments'] = (int) wp_count_posts( 'mcp_appointment' )->publish ?? 0;
		}

		// Count total posts (including CPTs from all toolkits).
		$metrics['total_posts'] = (int) wp_count_posts()->publish;

		// Storage estimate (rough — post content + postmeta size).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_size = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(post_content)) FROM {$wpdb->posts} WHERE post_status != 'auto-draft'"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_size                         = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(meta_value)) FROM {$wpdb->postmeta}"
		);
		$metrics['storage_bytes_estimate'] = $post_size + $meta_size;

		// User count.
		$metrics['user_count'] = count(
			get_users(
				array(
					'blog_id' => $blog_id,
					'fields'  => 'ID',
				)
			)
		);

		/**
		 * Filter the usage metrics before sending to the Cloud Worker.
		 *
		 * @since 0.1.0
		 *
		 * @param array $metrics The collected metrics.
		 * @param int   $blog_id The current blog ID.
		 */
		return apply_filters( 'sa_usage_metrics', $metrics, $blog_id );
	}

	/**
	 * Get cached usage stats for the current tenant (last heartbeat).
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Cached usage data or empty array.
	 */
	public static function get_cached_stats() {
		$cached = get_option( self::USAGE_CACHE_OPTION, array() );

		if ( empty( $cached ) ) {
			// No cache — collect fresh (but don't send heartbeat).
			$cached = self::collect_metrics();
			update_option( self::USAGE_CACHE_OPTION, $cached );
		}

		return $cached;
	}
}
