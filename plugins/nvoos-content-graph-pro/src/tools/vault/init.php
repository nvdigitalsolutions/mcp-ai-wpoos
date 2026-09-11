<?php
/**
 * Password Vault Manager initialization (ecosystem port — Wave F1,
 * sub-cluster 4b).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/vault/init.php`
 * for the standalone `nvoos-content-graph-pro` addon. Initializes the
 * WordPress-native password vault manager with AES-256-GCM encryption.
 * Follows OWASP cryptographic storage and password storage best practices.
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 * 3. The `wp_mcp_ai_pro_tools` filter stays byte-identical (the base plugin
 *    consumes it monolith; standalone it has no consumer — documented).
 * 4. Standalone-only tool registration: the two vault tools register into
 *    the ecosystem graph ToolRegistry via `WP_MCP_AI_Pro_Tool_Adapter` and
 *    are wrapped into the nvoos/core registry through the AI addon's
 *    GraphToolAdapter (new wiring — the base plugin registers them through
 *    `wp_mcp_ai_pro_register_tools()` monolith).
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/vault/class-wp-mcp-ai-vault-encryption-service.php';

	// Load Bitwarden import/export service.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/vault/class-wp-mcp-ai-bitwarden-import-export.php';

	// Load Bitwarden sync service.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/vault/class-wp-mcp-ai-bitwarden-sync-service.php';

	// Load vault item CPT.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/vault/class-wp-mcp-ai-vault-item-cpt.php';

	// Load vault folder CPT.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/vault/class-wp-mcp-ai-vault-folder-cpt.php';

	// Load conflict resolver (Phase 4).
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/vault/class-wp-mcp-ai-vault-conflict-resolver.php';

	// Load background sync service (Phase 4).
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/vault/class-wp-mcp-ai-vault-background-sync.php';
	new WP_MCP_AI_Vault_Background_Sync();

	// Load admin interface (if in admin context).
	if ( is_admin() ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-password-vault-admin.php';
		new WP_MCP_AI_Password_Vault_Admin();
	}

	// Load REST API controller.
	add_action( 'rest_api_init', 'wp_mcp_ai_pro_register_vault_rest_routes' );

	// Standalone-only ecosystem tool registration (deviation 4). The graph
	// plugin's `nvoos_content_graph/register_tools` action fired at
	// plugins_loaded 10 — before this addon boots at 15 — so register
	// directly into the registries instead of hooking the action.
	if ( ! defined( 'WP_MCP_AI_PATH' ) && function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_vault_ecosystem_tools();
	}
}

/**
 * Register vault tools with the ecosystem registries (standalone only).
 *
 * @since 1.0.0
 * @return void
 */
function wp_mcp_ai_pro_register_vault_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vault/class-wp-mcp-ai-pro-tool-vault-access.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vault/class-wp-mcp-ai-pro-tool-vault-manage.php';

	$parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach ( array( 'WP_MCP_AI_Pro_Tool_Vault_Access', 'WP_MCP_AI_Pro_Tool_Vault_Manage' ) as $tool_class ) {
		$adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $tool_class() );
		try {
			$parent_registry->register( $adapter );
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Duplicate slug — non-fatal.
		}

		// Wrap into the nvoos/core registry so the agentic chat loop can
		// resolve and execute the tool (same path the AI addon uses for
		// graph tools).
		if ( class_exists( 'NvoosContentGraphAi\\CoreBridge' ) ) {
			$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
			try {
				$core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $adapter ) );
			} catch ( \RuntimeException $e ) {
				unset( $e ); // Duplicate slug — non-fatal.
			}
		}
	}
}

/**
 * Register REST API routes for vault
 *
 * @since 1.3.0
 */
function wp_mcp_ai_pro_register_vault_rest_routes() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/vault/class-wp-mcp-ai-vault-rest-controller.php';
	$controller = new WP_MCP_AI_Vault_REST_Controller();
	$controller->register_routes();
}

/**
 * Register vault tools with tool registry (byte-identical filter — the base
 * plugin consumes it monolith; standalone it is inert, documented).
 *
 * @since 1.3.0
 *
 * @param array $tools Existing tools array.
 * @return array Updated tools array.
 */
function wp_mcp_ai_pro_register_vault_tools( $tools ) {
	$vault_tools = array(
		// Vault Access tool (read-only).
		'WP_MCP_AI_Pro_Tool_Vault_Access'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vault/class-wp-mcp-ai-pro-tool-vault-access.php',
		// Vault Manage tool (CRUD operations).
		'WP_MCP_AI_Pro_Tool_Vault_Manage'      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/vault/class-wp-mcp-ai-pro-tool-vault-manage.php',
		// Generate Password tool (F2 orchestration toolkit — lands with its wave).
		'WP_MCP_AI_Pro_Tool_Generate_Password' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-password.php',
	);

	return array_merge( $tools, $vault_tools );
}

// Register vault tools.
add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_vault_tools', 10 );

// Initialize on init hook.
add_action( 'init', 'wp_mcp_ai_pro_init_password_vault', 20 );
