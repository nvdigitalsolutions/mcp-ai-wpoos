<?php
/**
 * Uninstall handler.
 *
 * @package NV_oOS_Chat_Spa
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_chat_spa_settings' );
