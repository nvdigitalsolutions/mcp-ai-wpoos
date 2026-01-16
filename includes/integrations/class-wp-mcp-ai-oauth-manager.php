<?php
/**
 * OAuth Manager for NV oOS
 *
 * Handles OAuth flows for third-party service integrations.
 * Note: Gmail OAuth has been moved to Pro's Remote Sites feature.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

if ( ! class_exists( 'WP_MCP_AI_OAuth_Manager' ) ) {
/**
 * Manages OAuth authentication flows for external services.
 * 
 * This class is currently preserved for future OAuth integrations.
 * Gmail OAuth has been migrated to the Pro addon's Remote Sites feature.
 */
class WP_MCP_AI_OAuth_Manager {
/**
 * Constructor - placeholder for future OAuth integrations.
 */
public function __construct() {
// OAuth integrations will be added here in the future.
// Gmail has been moved to Pro's Remote Sites feature.
}

/**
 * Handle Gmail OAuth start request.
 * 
 * This is a stub method that redirects to Pro upgrade page.
 * Gmail OAuth functionality has been moved to Pro's Remote Sites feature.
 */
public function handle_gmail_oauth_start() {
// Check nonce for security.
if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wp_mcp_ai_gmail_oauth_start' ) ) {
wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ) );
}

// Check user capability.
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
}

// Redirect back to settings page with Pro upgrade message.
wp_safe_redirect(
add_query_arg(
array(
'page'                => 'wp-mcp-ai-dashboard',
'tab'                 => 'tools',
'subtab'              => 'connections',
'connection'          => 'gmail',
'gmail_requires_pro'  => '1',
),
admin_url( 'admin.php' )
)
);
exit;
}

/**
 * Allow the Google OAuth authorize endpoint host when using wp_safe_redirect().
 * Preserved for backward compatibility.
 *
 * @param string[] $allowed_hosts Existing list of allowed hosts.
 * @param string   $redirect      Requested redirect destination.
 *
 * @return string[]
 */
public function allow_gmail_oauth_redirect_host( $allowed_hosts, $redirect = '' ) {
// This method is preserved for backward compatibility.
// Gmail OAuth is now handled by Pro's Remote Sites feature.
$allowed_hosts[] = 'accounts.google.com';
return array_values( array_unique( $allowed_hosts ) );
}
}
}
