<?php
/**
 * Uninstall handler.
 *
 * @package NV_oOS_Canvas_Toolkit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_canvas_toolkit_settings' );
