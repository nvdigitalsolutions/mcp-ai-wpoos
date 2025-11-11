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
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-orchestration-preset-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-orchestration-health-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-error-tracking-service.php';

/**
 * Initialize services
 *
 * Creates and returns service instances with proper dependencies.
 * Uses DI container for dependency management.
 *
 * @return array Array of service instances keyed by service name.
 */
function wp_mcp_ai_init_services() {
	$container = WP_MCP_AI_Container::get_instance();

	return array(
		'chat'      => $container->get( 'service.chat' ),
		'assistant' => $container->get( 'service.assistant' ),
		'tool'      => $container->get( 'service.tool' ),
		'file'      => $container->get( 'service.file' ),
	);
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
 * Helper function to get router instance from container.
 *
 * @return WP_MCP_AI_Language_Model_Router Router instance.
 */
function wp_mcp_ai_get_language_model_router() {
	$container = WP_MCP_AI_Container::get_instance();
	return $container->get( 'router' );
}

/**
 * Get rate limit manager instance
 *
 * Helper function to get rate limiter instance from container.
 *
 * @return WP_MCP_AI_Rate_Limit_Manager Rate limiter instance.
 */
function wp_mcp_ai_get_rate_limit_manager() {
	$container = WP_MCP_AI_Container::get_instance();
	return $container->get( 'rate_limiter' );
}

/**
 * Get token budget manager instance
 *
 * Helper function to get token budget manager instance from container.
 *
 * @return WP_MCP_AI_Token_Budget_Manager Token budget manager instance.
 */
function wp_mcp_ai_get_token_budget_manager() {
	$container = WP_MCP_AI_Container::get_instance();
	return $container->get( 'token_budget_manager' );
}

/**
 * Get tool registry instance
 *
 * Helper function to get tool registry instance from container.
 *
 * @return WP_MCP_AI_Tool_Registry Tool registry instance.
 */
function wp_mcp_ai_get_tool_registry() {
	$container = WP_MCP_AI_Container::get_instance();
	return $container->get( 'tool_registry' );
}

/**
 * Get error tracking service instance
 *
 * Helper function to get error tracking service instance.
 *
 * @return WP_MCP_AI_Error_Tracking_Service Error tracking service instance.
 */
function wp_mcp_ai_get_error_tracking_service() {
	return WP_MCP_AI_Error_Tracking_Service::get_instance();
}
