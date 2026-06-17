<?php
/**
 * Uninstall handler.
 *
 * @package NV_oOS_Toolkit_Shell
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_toolkit_shell_settings' );
