<?php
/**
 * Uninstall handler for NV oOS Cloudways Dashboard.
 *
 * Cleans up all options and transients created by the addon.
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefixes = array(
	'nvoos_cw_pending_toolkits_',    // Pending toolkit configuration.
	'nvoos_cw_provisioning_',        // Provisioning status.
	'nvoos_cw_site_toolkits_',       // Per-site toolkit state.
	'nvoos_cw_app_plugin_intent_',   // Plugin install intents.
	'nvoos_cw_toolkit_intents_',     // Toolkit application intents.
);

foreach ( $prefixes as $prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$prefix . '%' // phpcs:ignore WordPress.DB.PreparedSQLPlaceholder.UnquotedComplexPlaceholder
		)
	);
	if ( is_array( $keys ) ) {
		foreach ( $keys as $key ) {
			delete_option( $key );
		}
	}
}

// Clear any scheduled actions.
if ( function_exists( 'as_unschedule_action' ) ) {
	as_unschedule_action( 'nvoos_cloudways_dashboard_provision_app' );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'nvoos_cloudways_dashboard_provision_app' );
}
