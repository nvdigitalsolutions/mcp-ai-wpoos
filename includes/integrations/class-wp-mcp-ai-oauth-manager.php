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
