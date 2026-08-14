<?php
/**
 * Media Worker Usage Reporter — Phase 3 W2
 *
 * Pulls the worker's per-site provider usage counters (`tenants.usage`
 * from /api/health/full) on a daily cron and stores a snapshot option,
 * then fires `wp_mcp_ai_media_worker_usage_updated` for other components
 * (e.g. the cost tracker) to consume.
 *
 * Off by default: nothing is scheduled or fetched unless the
 * `wp_mcp_ai_media_worker_usage_tracking` option is enabled. Safe no-op
 * when the Media Worker URL/token are not configured.
 *
 * Note (proposal 028, open Q3): WP_MCP_AI_Cost_Tracker::record() is
 * assistant-scoped USD, while the worker counters are site-scoped event
 * counts — direct cost-tracker wiring waits for the schema decision; this
 * class is the seam.
 *
 * @package WP_MCP_AI
 * @since 1.1.56
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Worker usage reporter class.
 *
 * @since 1.1.56
 */
class WP_MCP_AI_Media_Worker_Usage_Reporter {

	/**
	 * Option flag enabling usage tracking.
	 */
	const OPTION_ENABLED = 'wp_mcp_ai_media_worker_usage_tracking';

	/**
	 * Option storing the latest usage snapshot.
	 */
	const OPTION_SNAPSHOT = 'wp_mcp_ai_media_worker_usage_snapshot';

	/**
	 * Daily cron hook name.
	 */
	const CRON_HOOK = 'wp_mcp_ai_media_worker_usage_sync';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the daily sync when the option is enabled.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! get_option( self::OPTION_ENABLED, false ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Resolve the worker URL (same priority as the sidecar client trait:
	 * constant, then option).
	 *
	 * @return string URL or empty string.
	 */
	private static function get_worker_url() {
		if ( defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL ) {
			return rtrim( WP_MEDIA_WORKER_URL, '/' );
		}
		$option = get_option( 'wp_mcp_ai_media_worker_url', '' );
		return $option ? rtrim( $option, '/' ) : '';
	}

	/**
	 * Resolve the worker token (constant, per-blog option on multisite,
	 * then site option).
	 *
	 * @return string Token or empty string.
	 */
	private static function get_worker_token() {
		if ( defined( 'WP_MEDIA_WORKER_TOKEN' ) && WP_MEDIA_WORKER_TOKEN ) {
			return WP_MEDIA_WORKER_TOKEN;
		}
		if ( is_multisite() ) {
			$blog_token = get_option( 'wp_mcp_ai_media_worker_token_' . get_current_blog_id(), '' );
			if ( ! empty( $blog_token ) ) {
				return $blog_token;
			}
		}
		return (string) get_option( 'wp_mcp_ai_media_worker_token', '' );
	}

	/**
	 * Fetch the worker usage snapshot and store it.
	 *
	 * @return bool True on success, false on any failure (logged).
	 */
	public static function sync() {
		$url   = self::get_worker_url();
		$token = self::get_worker_token();
		if ( empty( $url ) || empty( $token ) ) {
			return false;
		}

		$response = wp_remote_get(
			$url . '/api/health/full',
			array(
				'headers' => array( 'X-Site-Token' => $token ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tenants']['usage'] ) ) {
			return false;
		}

		update_option(
			self::OPTION_SNAPSHOT,
			array(
				'fetched_at' => time(),
				'usage'      => $body['tenants']['usage'],
			),
			false
		);

		/**
		 * Fires after a successful worker usage sync.
		 *
		 * @param array $usage The worker's tenants.usage payload.
		 */
		do_action( 'wp_mcp_ai_media_worker_usage_updated', $body['tenants']['usage'] );

		return true;
	}
}
