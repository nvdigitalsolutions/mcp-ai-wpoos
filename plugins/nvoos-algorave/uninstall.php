<?php
/**
 * Uninstall handler for NV oOS Algorave.
 *
 * Standalone file — no autoloader, no plugin bootstrap.
 * Runs when the plugin is deleted via the WordPress admin.
 *
 * Removes plugin options only. Pattern and session posts (user content)
 * are intentionally left in place.
 *
 * @package NV_oOS_Algorave
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options.
delete_option( 'nvoos_algorave_settings' );
delete_option( 'nvoos_algorave_patterns_seeded' );

// Clear transients.
delete_transient( 'nvoos_algorave_activated' );
