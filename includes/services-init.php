<?php
/**
 * Service Layer Initialization
 *
 * Loads and initializes service layer classes.
 * Part of Phase 4 refactoring (Milestone 8).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load service classes.
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-chat-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-assistant-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-tool-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-file-service.php';

/**
 * Initialize services
 *
 * Creates and returns service instances with proper dependencies.
 *
 * @return array Array of service instances keyed by service name.
 */
function wp_mcp_ai_init_services() {
	static $services = null;

	if ( null !== $services ) {
		return $services; // Return cached instances.
	}

	// Initialize dependencies that services need.
	$router               = wp_mcp_ai_get_language_model_router();
	$rate_limiter         = wp_mcp_ai_get_rate_limit_manager();
	$token_budget_manager = wp_mcp_ai_get_token_budget_manager();
	$tool_registry        = wp_mcp_ai_get_tool_registry();

	// Create service instances.
	$services = array(
		'chat'      => new WP_MCP_AI_Chat_Service(
			$router,
			$rate_limiter,
			$token_budget_manager,
			$tool_registry
		),
		'assistant' => new WP_MCP_AI_Assistant_Service(),
		'tool'      => new WP_MCP_AI_Tool_Service( $tool_registry ),
		'file'      => new WP_MCP_AI_File_Service(),
	);

	return $services;
}

/**
 * Get chat service instance
 *
 * @return WP_MCP_AI_Chat_Service Chat service instance.
 */
function wp_mcp_ai_get_chat_service() {
	$services = wp_mcp_ai_init_services();
	return $services['chat'];
}

/**
 * Get assistant service instance
 *
 * @return WP_MCP_AI_Assistant_Service Assistant service instance.
 */
function wp_mcp_ai_get_assistant_service() {
	$services = wp_mcp_ai_init_services();
	return $services['assistant'];
}

/**
 * Get tool service instance
 *
 * @return WP_MCP_AI_Tool_Service Tool service instance.
 */
function wp_mcp_ai_get_tool_service() {
	$services = wp_mcp_ai_init_services();
	return $services['tool'];
}

/**
 * Get file service instance
 *
 * @return WP_MCP_AI_File_Service File service instance.
 */
function wp_mcp_ai_get_file_service() {
	$services = wp_mcp_ai_init_services();
	return $services['file'];
}

/**
 * Get language model router instance
 *
 * Helper function to get or create router instance.
 *
 * @return WP_MCP_AI_Language_Model_Router Router instance.
 */
function wp_mcp_ai_get_language_model_router() {
	static $router = null;

	if ( null === $router ) {
		$router = new WP_MCP_AI_Language_Model_Router();
	}

	return $router;
}

/**
 * Get rate limit manager instance
 *
 * Helper function to get or create rate limiter instance.
 *
 * @return WP_MCP_AI_Rate_Limit_Manager Rate limiter instance.
 */
function wp_mcp_ai_get_rate_limit_manager() {
	static $rate_limiter = null;

	if ( null === $rate_limiter ) {
		$rate_limiter = new WP_MCP_AI_Rate_Limit_Manager();
	}

	return $rate_limiter;
}

/**
 * Get token budget manager instance
 *
 * Helper function to get or create token budget manager instance.
 *
 * @return WP_MCP_AI_Token_Budget_Manager Token budget manager instance.
 */
function wp_mcp_ai_get_token_budget_manager() {
	static $token_budget_manager = null;

	if ( null === $token_budget_manager ) {
		$token_budget_manager = new WP_MCP_AI_Token_Budget_Manager();
	}

	return $token_budget_manager;
}

/**
 * Get tool registry instance
 *
 * Helper function to get or create tool registry instance.
 *
 * @return WP_MCP_AI_Tool_Registry Tool registry instance.
 */
function wp_mcp_ai_get_tool_registry() {
	static $tool_registry = null;

	if ( null === $tool_registry ) {
		$tool_registry = new WP_MCP_AI_Tool_Registry();
	}

	return $tool_registry;
}
