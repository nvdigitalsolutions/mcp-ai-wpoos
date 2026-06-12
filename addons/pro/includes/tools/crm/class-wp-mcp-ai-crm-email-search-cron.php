<?php
/**
 * CRM Email Search Cron Scheduler
 *
 * Centralized WP Cron registration for the three CRM email search tools'
 * cache-refresh hooks. Each tool declares a CRON_HOOK constant but the
 * scheduling was never wired up. This class registers them on init using
 * wp_schedule_event with appropriate intervals.
 *
 * Hooks wired:
 *  - wp_mcp_ai_crm_email_search_leads_refresh          (hourly)
 *  - wp_mcp_ai_crm_email_search_correspondence_refresh  (hourly)
 *  - wp_mcp_ai_crm_email_search_accounting_refresh      (daily)
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Email Search cron scheduler.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_CRM_Email_Search_Cron {

	/**
	 * Hooks to schedule: hook_name => recurrence.
	 *
	 * @var array<string, string>
	 */
	const SCHEDULES = array(
		'wp_mcp_ai_crm_email_search_leads_refresh'          => 'hourly',
		'wp_mcp_ai_crm_email_search_correspondence_refresh'  => 'hourly',
		'wp_mcp_ai_crm_email_search_accounting_refresh'      => 'twicedaily',
	);

	/**
	 * Initialize cron scheduling.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'maybe_schedule_all' ), 30 );
	}

	/**
	 * Schedule all email search cron hooks if not already scheduled.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule_all() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		foreach ( self::SCHEDULES as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}
	}

	/**
	 * Clear all email search cron hooks (called on deactivation).
	 *
	 * @since 2.9.0
	 */
	public static function unschedule_all() {
		foreach ( array_keys( self::SCHEDULES ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_CRM_Email_Search_Cron', 'init' ), 30 );
