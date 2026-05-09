<?php
/**
 * Uninstall handler.
 *
 * @package NV_oOS_Media_Studio
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_media_studio_settings' );
