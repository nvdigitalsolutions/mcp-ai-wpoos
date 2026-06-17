<?php
/**
 * Uninstall handler.
 *
 * @package NV_oOS_Document_Editor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_document_editor_settings' );
