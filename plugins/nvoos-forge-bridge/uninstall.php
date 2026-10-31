<?php
/**
 * Uninstall — full data removal (reversible per-deactivation cleanup lives
 * in the plugin itself; uninstall is the point of no return).
 *
 * @package NvoosForgeBridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'NVOOS_FORGE_BRIDGE_PATH' ) ) {
	define( 'NVOOS_FORGE_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
}
require_once NVOOS_FORGE_BRIDGE_PATH . 'includes/class-nvoos-forge-bridge-settings.php';

$options = \NvoosForgeBridge\Settings::option_keys();
foreach ( $options as $option ) {
	delete_option( $option );
}
delete_transient( 'nvoos_forge_bridge_webhook_sent' );
delete_transient( 'nvoos_forge_bridge_preview_tokens' );
