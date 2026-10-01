<?php
/**
 * Database output guard.
 *
 * Prevents WordPress database error display from corrupting JSON response
 * surfaces (REST, admin-ajax, SSE) when a query fails inside tool execution
 * or dashboard data collection while `$wpdb` error display is enabled
 * (WP_DEBUG). Without this guard, `$wpdb::print_error()` echoes an HTML
 * error block into the response body before the JSON payload is emitted and
 * the client fails to parse it.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a callback with `$wpdb` error output suppressed and logs any failure.
 *
 * @since 1.1.91
 */
class WP_MCP_AI_Db_Output_Guard {

	/**
	 * Run a callback with `$wpdb` error output suppressed.
	 *
	 * Snapshots and restores the `show_errors` / `suppress_errors` state so
	 * the suppression is scoped to the callback. When a query fails during
	 * the callback, the failure is logged (with the last query) instead of
	 * being printed into the response body.
	 *
	 * @since 1.1.91
	 *
	 * @param string   $label    Diagnostic label identifying the guarded context.
	 * @param callable $callback Callback whose database activity to guard.
	 * @return mixed The callback's return value.
	 */
	public static function run( $label, $callback ) {
		global $wpdb;

		$old_suppress = $wpdb->suppress_errors( true );
		$old_show     = $wpdb->show_errors;

		$wpdb->show_errors = false;

		$result = $callback();

		$wpdb->show_errors = $old_show;
		$wpdb->suppress_errors( $old_suppress );

		if ( ! empty( $wpdb->last_error ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error(
				'[DbOutputGuard] Database error suppressed during ' . $label,
				array(
					'last_error' => $wpdb->last_error,
					'last_query' => $wpdb->last_query,
				)
			);
		}

		return $result;
	}
}
