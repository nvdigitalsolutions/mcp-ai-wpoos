<?php
/**
 * Uninstall handler.
 *
 * @package NV_oOS_LibreChat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_librechat_settings' );
