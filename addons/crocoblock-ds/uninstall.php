<?php
/**
 * Uninstall handler.
 *
 * Removes plugin options when the plugin is deleted (not just deactivated).
 *
 * @package NV_oOS_Crocoblock_DS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nvoos_cds_settings' );
delete_option( 'nvoos_cds_use_typed_properties' );
delete_option( 'nvoos_cds_elementor_sync' );
delete_transient( 'nvoos_cds_compiled_css' );
