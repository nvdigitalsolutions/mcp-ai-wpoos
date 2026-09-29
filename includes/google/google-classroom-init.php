<?php
/**
 * Google Classroom integration bootstrap.
 *
 * Loads the shared Google Classroom services and registers the push
 * notification receiver and its daily renewal cron. Self-gating: nothing is
 * scheduled until at least one Classroom push registration exists, and the
 * foundation classes make no network calls on load, so the file is safe to
 * load unconditionally.
 *
 * The Classroom consumers (ECA toolkit tools, the roster/course sync engine,
 * the Remote Sites connection type) live in the Pro addon; this file only
 * owns the shared foundation.
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

require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-scopes.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-client.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-credentials.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-push.php';

if ( ! function_exists( 'wp_mcp_ai_google_classroom_has_registrations' ) ) {
	/**
	 * Whether any Google Classroom push registration exists.
	 *
	 * Used to gate scheduling so a site that never configures push pays no
	 * cron cost.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	function wp_mcp_ai_google_classroom_has_registrations() {
		return ! empty( WP_MCP_AI_Google_Classroom_Push::get_registrations() );
	}
}

if ( ! function_exists( 'wp_mcp_ai_google_classroom_init' ) ) {
	/**
	 * Register Google Classroom hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function wp_mcp_ai_google_classroom_init() {
		// The push receiver registers its own `rest_api_init` route and the
		// daily renewal cron callback.
		new WP_MCP_AI_Google_Classroom_Push();

		// Schedule the renewal pass only once registrations exist.
		if ( is_admin() && wp_mcp_ai_google_classroom_has_registrations() ) {
			wp_mcp_ai_google_classroom_schedule_renewal();
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_google_classroom_schedule_renewal' ) ) {
	/**
	 * Schedule the daily push-registration renewal check.
	 *
	 * Registrations expire after at most 7 days with no auto-renewal, so the
	 * check runs daily and renews anything inside its threshold.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function wp_mcp_ai_google_classroom_schedule_renewal() {
		$hook = WP_MCP_AI_Google_Classroom_Push::RENEW_HOOK;

		if ( wp_next_scheduled( $hook ) ) {
			return;
		}

		// Only worth scheduling when push can actually be delivered.
		if ( is_wp_error( WP_MCP_AI_Google_Classroom_Push::is_push_eligible() ) ) {
			return;
		}

		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', $hook );
	}
}

add_action( 'init', 'wp_mcp_ai_google_classroom_init' );
