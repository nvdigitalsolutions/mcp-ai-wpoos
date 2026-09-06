<?php
/**
 * Google Calendar integration bootstrap.
 *
 * Loads the shared Google Calendar services and registers the scheduling and
 * push-notification hooks. Self-gating: nothing is scheduled and no channel work
 * happens until at least one Google Calendar connection is authorised, so the
 * file is safe to load unconditionally.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-scopes.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-oauth-service.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-client.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-credentials.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-sync.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-push.php';

if ( ! function_exists( 'wp_mcp_ai_google_calendar_has_connection' ) ) {
	/**
	 * Whether any Google Calendar connection is authorised.
	 *
	 * Used to gate scheduling so a site that never configures Calendar pays no
	 * cron cost.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	function wp_mcp_ai_google_calendar_has_connection() {
		$targets = WP_MCP_AI_Google_Calendar_Sync::get_sync_targets();

		return ! empty( $targets );
	}
}

if ( ! function_exists( 'wp_mcp_ai_google_calendar_init' ) ) {
	/**
	 * Register Google Calendar hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function wp_mcp_ai_google_calendar_init() {
		// Custom cron interval for the jittered safety-net sync. Registered
		// unconditionally because `cron_schedules` runs before the gate below.
		add_filter(
			'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Interval is >= 5 minutes; see WP_MCP_AI_Google_Calendar_Sync::jittered_interval().
			array( 'WP_MCP_AI_Google_Calendar_Sync', 'register_cron_schedule' )
		);

		// Cron callbacks. Registered unconditionally so an already-scheduled event
		// still fires if a connection is temporarily unreadable.
		add_action(
			WP_MCP_AI_Google_Calendar_Sync::SYNC_HOOK,
			'wp_mcp_ai_google_calendar_run_sync',
			10,
			2
		);
		add_action(
			WP_MCP_AI_Google_Calendar_Sync::RENEW_HOOK,
			array( 'WP_MCP_AI_Google_Calendar_Push', 'renew_expiring_channels' )
		);

		// The notification receiver registers its own `rest_api_init` hook.
		new WP_MCP_AI_Google_Calendar_Push();

		// Schedule only once a connection exists.
		if ( is_admin() && wp_mcp_ai_google_calendar_has_connection() ) {
			WP_MCP_AI_Google_Calendar_Sync::schedule();
			wp_mcp_ai_google_calendar_schedule_renewal();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_google_calendar_run_sync' ) ) {
	/**
	 * Cron callback: run a Google Calendar sync.
	 *
	 * The recurring safety-net event passes no arguments and syncs every target;
	 * push-triggered single events pass a specific connection and calendar.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Optional connection ID.
	 * @param string $calendar_id   Optional calendar identifier.
	 * @return void
	 */
	function wp_mcp_ai_google_calendar_run_sync( $connection_id = null, $calendar_id = null ) {
		if ( null === $connection_id && null === $calendar_id ) {
			WP_MCP_AI_Google_Calendar_Sync::run_scheduled_sync();

			return;
		}

		WP_MCP_AI_Google_Calendar_Sync::run( (string) $connection_id, (string) $calendar_id );
	}
}

if ( ! function_exists( 'wp_mcp_ai_google_calendar_schedule_renewal' ) ) {
	/**
	 * Schedule the daily push-channel renewal check.
	 *
	 * Channels expire after at most 7 days with no auto-renewal, so the check runs
	 * daily and renews anything inside its threshold.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function wp_mcp_ai_google_calendar_schedule_renewal() {
		if ( wp_next_scheduled( WP_MCP_AI_Google_Calendar_Sync::RENEW_HOOK ) ) {
			return;
		}

		// Only worth scheduling when push can actually be delivered.
		if ( is_wp_error( WP_MCP_AI_Google_Calendar_Push::is_push_eligible() ) ) {
			return;
		}

		wp_schedule_event(
			time() + WP_MCP_AI_Google_Calendar_Sync::site_offset( DAY_IN_SECONDS ),
			'daily',
			WP_MCP_AI_Google_Calendar_Sync::RENEW_HOOK
		);
	}
}

add_action( 'init', 'wp_mcp_ai_google_calendar_init' );
