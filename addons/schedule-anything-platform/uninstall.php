<?php
/**
 * Uninstall handler for Schedule Anything Platform.
 *
 * Cleans up all options, transients, and scheduled actions
 * created by the platform plugin. Does NOT delete tenant data —
 * each tenant subsite must be offboarded individually before
 * uninstalling the platform plugin.
 *
 * @package Schedule_Anything
 * @since   0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Prefixes for platform-level options.
$prefixes = array(
	'sa_platform_',        // Platform settings.
	'sa_usage_',           // Usage tracking cache.
	'sa_last_',            // Last heartbeat timestamps.
	'sa_otl_',             // One-time login token transients.
	'sa_login_token_',     // Cross-domain SSO tokens.
);

foreach ( $prefixes as $prefix ) {
	// Clean options.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$prefix . '%'
		)
	);

	if ( is_array( $keys ) ) {
		foreach ( $keys as $key ) {
			delete_option( $key );
		}
	}

	// Clean transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$transient_keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			'_transient_' . $prefix . '%'
		)
	);

	if ( is_array( $transient_keys ) ) {
		foreach ( $transient_keys as $key ) {
			$transient = str_replace( '_transient_', '', $key );
			delete_transient( $transient );
		}
	}
}

// Clear scheduled cron hooks.
$hooks = array(
	'sa_usage_heartbeat',
);

foreach ( $hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// Clear Action Scheduler actions if available.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( $hooks as $hook ) {
		as_unschedule_all_actions( $hook );
	}
}

// Remove the tenant admin role.
remove_role( 'sa_tenant_admin' );

// Note: Tenant subsite data is NOT deleted here.
// Each tenant must be individually offboarded via the offboard API
// before uninstalling the platform plugin, which handles data export,
// blog deletion, and Stripe cancellation.
