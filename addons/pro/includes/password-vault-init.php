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

	// Load Bitwarden import/export service.
	require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-bitwarden-import-export.php';

	// Load Bitwarden sync service.
	require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-bitwarden-sync-service.php';

	// Load vault item CPT.
	require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-item-cpt.php';

	// Load vault folder CPT.
	require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-folder-cpt.php';

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

/**
 * Register vault tools with tool registry
 *
 * @since 1.3.0
 *
 * @param array $tools Existing tools array.
 * @return array Updated tools array.
 */
function wp_mcp_ai_pro_register_vault_tools( $tools ) {
	$vault_tools = array(
		// Vault Access tool (read-only).
		'WP_MCP_AI_Pro_Tool_Vault_Access'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-vault-access.php',
		// Vault Manage tool (CRUD operations).
		'WP_MCP_AI_Pro_Tool_Vault_Manage'     => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-vault-manage.php',
		// Generate Password tool.
		'WP_MCP_AI_Pro_Tool_Generate_Password' => WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-generate-password.php',
	);

	return array_merge( $tools, $vault_tools );
}

// Register vault tools.
add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_vault_tools', 10 );

// Initialize on init hook.
add_action( 'init', 'wp_mcp_ai_pro_init_password_vault', 20 );
