<?php
/**
 * Uninstall — full data removal (reversible per-deactivation cleanup lives
 * in the plugin itself; uninstall is the point of no return).
 *
 * @package NvoosForgeBridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'NVOOS_FORGE_BRIDGE_PATH' ) ) {
	define( 'NVOOS_FORGE_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
}
require_once NVOOS_FORGE_BRIDGE_PATH . 'includes/class-nvoos-forge-bridge-settings.php';

$nvoos_forge_bridge_options = \NvoosForgeBridge\Settings::option_keys();
foreach ( $nvoos_forge_bridge_options as $nvoos_forge_bridge_option ) {
	delete_option( $nvoos_forge_bridge_option );
}
delete_transient( 'nvoos_forge_bridge_webhook_sent' );
delete_transient( 'nvoos_forge_bridge_preview_tokens' );

// The forms rate limiter keys its transients by a hash of the client IP, so
// the finite list above cannot reach them; wildcard-delete both the value and
// the timeout rows.
global $wpdb;
foreach ( array( '_transient_', '_transient_timeout_' ) as $nvoos_forge_bridge_prefix ) {
	$nvoos_forge_bridge_like = $wpdb->esc_like( $nvoos_forge_bridge_prefix . 'nvoos_forge_bridge_forms_rate_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- one-time uninstall cleanup; the table name is the constant wpdb property and the pattern is bound.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $nvoos_forge_bridge_like ) );
}
