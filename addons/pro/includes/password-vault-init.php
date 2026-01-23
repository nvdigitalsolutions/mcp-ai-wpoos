<?php
/**
 * Password Vault Manager initialization - Pro Feature
 *
 * Initializes the WordPress-native password vault manager with AES-256-GCM encryption.
 * Follows OWASP cryptographic storage and password storage best practices.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize Password Vault Manager
 *
 * @since 1.3.0
 */
function wp_mcp_ai_pro_init_password_vault() {
	// Load encryption service (OWASP-compliant cryptography).
	require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-encryption-service.php';

	// Load vault item CPT.
	require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-item-cpt.php';
	WP_MCP_AI_Vault_Item_CPT::init();

	// Load vault folder CPT.
	require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-folder-cpt.php';
	WP_MCP_AI_Vault_Folder_CPT::init();

	// Load admin interface (if in admin context).
	if ( is_admin() ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-password-vault-admin.php';
		new WP_MCP_AI_Password_Vault_Admin();
	}

	// Load REST API controller.
	add_action( 'rest_api_init', 'wp_mcp_ai_pro_register_vault_rest_routes' );
}

/**
 * Register REST API routes for vault
 *
 * @since 1.3.0
 */
function wp_mcp_ai_pro_register_vault_rest_routes() {
	require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-rest-controller.php';
	$controller = new WP_MCP_AI_Vault_REST_Controller();
	$controller->register_routes();
}

// Initialize on init hook.
add_action( 'init', 'wp_mcp_ai_pro_init_password_vault', 20 );
