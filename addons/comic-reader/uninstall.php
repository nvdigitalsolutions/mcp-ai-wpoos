<?php
/**
 * NV oOS Comic Reader — Uninstall
 *
 * Cleans up plugin-specific data when the addon is uninstalled.
 *
 * @package NV_oOS_Comic_Reader
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clean up any addon-specific options or transients.
delete_option( 'nvoos_comic_reader_settings' );
