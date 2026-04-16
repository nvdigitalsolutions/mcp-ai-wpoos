<?php
/**
 * NV oOS Graphify Addon — Uninstall
 *
 * Removes all plugin data when the addon is deleted via the
 * WordPress admin Plugins screen.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the database class so we can drop tables.
require_once __DIR__ . '/includes/class-nvoos-graphify-database.php';

// Drop all custom database tables.
NV_oOS_Graphify_Database::drop_tables();

// Delete plugin options.
delete_option( 'nvoos_graphify_settings' );
delete_option( 'nvoos_graphify_db_version' );
delete_option( 'nvoos_graphify_last_report' );

// Clear transients.
delete_transient( 'nvoos_graphify_activated' );

// Clear any scheduled cron events.
$timestamp = wp_next_scheduled( 'nvoos_graphify_scheduled_rebuild' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'nvoos_graphify_scheduled_rebuild' );
}
wp_unschedule_hook( 'nvoos_graphify_scheduled_rebuild' );
