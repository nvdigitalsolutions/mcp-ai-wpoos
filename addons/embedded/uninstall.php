<?php
/**
 * NV oOS Embedded AI Addon — Uninstall
 *
 * Fired when the plugin is uninstalled (deleted) via the WordPress admin.
 * Cleans up options and transients created by the addon.
 *
 * @package NV_oOS_Embedded
 * @since   0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove addon-specific options.
delete_option( 'nvoos_embedded_settings' );

// Note: We intentionally do NOT delete:
// - wp_mcp_ai_settings (shared with base plugin).
// - mcp_ai_webchat posts (user data preserved for re-activation).
// - GGUF model files in uploads (user-downloaded, preserved).
// - WebLLM feature flag options (shared with base plugin).
