<?php
/**
 * Local Dev: NV oOS Cloud Worker Integration
 *
 * Drop this file into wp-content/mu-plugins/ (create the directory if needed)
 * or add these constants to wp-config.php.
 *
 * This wires your local WordPress to the local Cloud Worker running on
 * http://localhost:8787.
 */

// Point the plugin to your local cloud worker instead of nvoos.cloud.
if ( ! defined( 'WP_MCP_AI_NV_CLOUD_BASE_URL' ) ) {
	define( 'WP_MCP_AI_NV_CLOUD_BASE_URL', 'http://localhost:8787/v1' );
}

/**
 * Optional: Pre-seed the connect token so you don't have to paste it manually.
 *
 * After running the seed script (see addons/cloud-worker/README-LOCAL.md),
 * uncomment the line below and paste your token.
 *
 * The token is stored encrypted; the NV Cloud Service will auto-decrypt it.
 */
// add_action( 'init', function () {
// if ( class_exists( 'WP_MCP_AI_NV_Cloud_Service' ) && ! WP_MCP_AI_NV_Cloud_Service::get_instance()->is_connected() ) {
// WP_MCP_AI_NV_Cloud_Service::get_instance()->save_connection(
// 'nvc_YOUR_TOKEN_HERE',
// array( 'account_id' => 'local-dev' )
// );
// }
// } );
