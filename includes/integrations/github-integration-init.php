<?php
/**
 * GitHub Integration Initialization
 *
 * Loads GitHub OAuth handler and sets up integration hooks.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load GitHub OAuth handler.
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-github-oauth-handler.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-github-client.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-custom-tool-loader.php';

// Load GitHub tools.
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-github-repositories.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-manage-github-codespace.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-github-repository-operations.php';

/**
 * Initialize GitHub integration.
 */
function wp_mcp_ai_init_github_integration() {
	static $github_handler = null;

	if ( null === $github_handler ) {
		$github_handler = new WP_MCP_AI_Github_OAuth_Handler();
	}

	return $github_handler;
}

// Set up GitHub OAuth hooks.
$github_handler = wp_mcp_ai_init_github_integration();
add_action( 'admin_post_wp_mcp_ai_github_oauth_start', array( $github_handler, 'handle_github_oauth_start' ) );
add_action( 'admin_post_wp_mcp_ai_github_oauth_callback', array( $github_handler, 'handle_github_oauth_callback' ) );
add_filter( 'allowed_redirect_hosts', array( $github_handler, 'allow_github_oauth_redirect_host' ), 10, 2 );

// Register GitHub tools.
add_action(
	'wp_mcp_ai_register_tools',
	function ( $registry ) {
		$registry->register_tool( new WP_MCP_AI_Tool_List_Github_Repositories() );
		$registry->register_tool( new WP_MCP_AI_Tool_Manage_Github_Codespace() );
		$registry->register_tool( new WP_MCP_AI_Tool_Github_Repository_Operations() );
	},
	10
);

// Initialize custom tool loader and load custom tools.
add_action(
	'wp_mcp_ai_register_tools',
	function ( $registry ) {
		$custom_tool_loader = new WP_MCP_AI_Custom_Tool_Loader();
		$custom_tools       = $custom_tool_loader->load_custom_tools();

		// Register custom tools with the tool registry.
		foreach ( $custom_tools as $tool ) {
			if ( $tool && ! is_wp_error( $tool ) ) {
				$registry->register_tool( $tool );
			}
		}
	},
	15
);
