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

/**
 * Check if Pro addon is available
 *
 * Determines whether Pro addon features should be loaded by checking:
 * 1. If Pro version constant is defined (separate Pro plugin scenario)
 * 2. If Pro addon file exists (combined plugin scenario)
 *
 * The result is cached statically to avoid repeated filesystem checks.
 *
 * @since 1.1.0
 *
 * @return bool True if Pro addon is available, false otherwise.
 */
function wp_mcp_ai_is_pro_addon_available() {
	static $is_available = null;

	if ( null !== $is_available ) {
		return $is_available;
	}

	// Check if Pro version constant is defined (separate Pro plugin).
	if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
		$is_available = true;
		return $is_available;
	}

	// Check if Pro addon file exists (combined plugin).
	if ( defined( 'WP_MCP_AI_PATH' ) ) {
		$pro_addon_file = WP_MCP_AI_PATH . 'addons/pro/mcp-ai-wpoos-pro.php';
		$is_available   = file_exists( $pro_addon_file );
		return $is_available;
	}

	$is_available = false;
	return $is_available;
}

// Load service classes.
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-chat-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-assistant-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-tool-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-file-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-file-service-factory.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-orchestration-preset-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-orchestration-health-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-orchestration-budget-enforcement-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-error-tracking-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-cost-tracking-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-token-budget-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-performance-reporting-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-token-usage-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-token-performance-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-timeout-detection-service.php';

// Load video-related services.
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-video-analysis-service.php';
// Note: Video frame extractor service moved to Pro addon (requires shell execution via ffmpeg).
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-gemini-video-generation-service.php';

// Load async tool orchestration services.
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-async-tool-orchestrator.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-tool-async-executor.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-async-health-monitor.php';

// Initialize video generation service (registers async polling hooks).
WP_MCP_AI_Gemini_Video_Generation_Service::init();

// Initialize orchestration budget enforcement (applies settings via filters).
WP_MCP_AI_Orchestration_Budget_Enforcement_Service::init();

// Load performance monitor service only when Pro addon is available.
if ( wp_mcp_ai_is_pro_addon_available() ) {
	require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-performance-monitor-service.php';
}


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

/**
 * Get performance monitor service instance
 *
 * Helper function to get performance monitor service instance.
 * Returns null if not available (e.g., when Pro addon is not present).
 *
 * Note: Pro addon availability check is performed at service loading time (line 55).
 * This function only verifies the class was successfully loaded.
 *
 * @return WP_MCP_AI_Performance_Monitor_CCT|null Performance monitor service instance or null.
 */
function wp_mcp_ai_get_performance_monitor_service() {
	if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
		return null;
	}

	// Performance monitor is a static class, return the class name
	// for static method access (e.g., WP_MCP_AI_Performance_Monitor_CCT::store_test_result).
	return 'WP_MCP_AI_Performance_Monitor_CCT';
}

/**
 * Get performance reporting service instance
 *
 * Helper function to access performance reporting service.
 * This service provides business logic for performance analysis and reporting.
 *
 * @return string Class name for static method access.
 */
function wp_mcp_ai_get_performance_reporting_service() {
	return 'WP_MCP_AI_Performance_Reporting_Service';
}

/**
 * Get token usage service instance
 *
 * Helper function to access token usage service.
 * This service provides business logic for token usage calculations and statistics.
 *
 * @return string Class name for static method access.
 */
function wp_mcp_ai_get_token_usage_service() {
	return 'WP_MCP_AI_Token_Usage_Service';
}

/**
 * Get cost tracking service instance
 *
 * Helper function to access cost tracking service.
 * This service integrates cost calculation with token usage tracking.
 *
 * @return string Class name for static method access.
 */
function wp_mcp_ai_get_cost_tracking_service() {
	return 'WP_MCP_AI_Cost_Tracking_Service';
}

/**
 * Get token performance service instance
 *
 * Helper function to access token performance service.
 * This service provides performance metrics and optimization statistics.
 *
 * @return string Class name for static method access.
 */
function wp_mcp_ai_get_token_performance_service() {
	return 'WP_MCP_AI_Token_Performance_Service';
}

/**
 * Get async tool orchestrator instance
 *
 * Helper function to get async tool orchestrator for routing tools to async/sync execution.
 *
 * @return WP_MCP_AI_Async_Tool_Orchestrator Orchestrator instance.
 */
function wp_mcp_ai_get_async_tool_orchestrator() {
	static $orchestrator = null;

	if ( null === $orchestrator ) {
		$orchestrator = new WP_MCP_AI_Async_Tool_Orchestrator();
	}

	return $orchestrator;
}

/**
 * Get async tool executor instance
 *
 * Helper function to get async tool executor for managing async job execution.
 *
 * @return WP_MCP_AI_Tool_Async_Executor Executor instance.
 */
function wp_mcp_ai_get_async_tool_executor() {
	static $executor = null;

	if ( null === $executor ) {
		$executor = new WP_MCP_AI_Tool_Async_Executor();
		$executor->init();
	}

	return $executor;
}

/**
 * Get async health monitor instance
 *
 * Helper function to check async task system health and detect issues.
 *
 * @return string Class name for static method access.
 */
function wp_mcp_ai_get_async_health_monitor() {
	return 'WP_MCP_AI_Async_Health_Monitor';
}

/**
 * Generate fallback text for Gemini image tool results.
 *
 * Creates a contextual text message based on the result fields when the tool
 * result lacks a text field. This is required for OpenAI's agentic loop
 * which expects a text response from tool calls.
 *
 * @since 1.0.0
 *
 * @param array $content Tool result content array.
 * @return string Generated fallback text describing the result.
 */
function wp_mcp_ai_generate_gemini_image_fallback_text( $content ) {
	// Check for error conditions first.
	if ( isset( $content['error'] ) ) {
		return isset( $content['message'] ) ? $content['message'] : __( 'Image generation failed.', 'wp-mcp-ai' );
	}

	// Success with attachment ID - most complete result.
	if ( ! empty( $content['attachment_id'] ) ) {
		$title = isset( $content['title'] ) ? $content['title'] : __( 'Gemini Image', 'wp-mcp-ai' );
		$text  = sprintf(
			/* translators: 1: image title, 2: attachment ID */
			__( 'Successfully generated image "%1$s" (ID: %2$d).', 'wp-mcp-ai' ),
			$title,
			$content['attachment_id']
		);

		// Add revised prompt if available.
		if ( ! empty( $content['revised_prompt'] ) ) {
			$text .= ' ' . sprintf(
				/* translators: %s: revised prompt from Gemini */
				__( 'Description: %s', 'wp-mcp-ai' ),
				$content['revised_prompt']
			);
		}

		// Add format info if available.
		$format_parts = array();
		if ( ! empty( $content['aspect_ratio'] ) ) {
			$format_parts[] = $content['aspect_ratio'];
		}
		if ( ! empty( $content['format'] ) ) {
			$format_parts[] = strtoupper( $content['format'] );
		}
		if ( ! empty( $format_parts ) ) {
			$text .= ' ' . sprintf(
				/* translators: %s: format details */
				__( 'Format: %s', 'wp-mcp-ai' ),
				implode( ', ', $format_parts )
			);
		}

		return $text;
	}

	// URL-only result (no attachment stored).
	if ( ! empty( $content['url'] ) || ! empty( $content['download_url'] ) ) {
		$url = ! empty( $content['download_url'] ) ? $content['download_url'] : $content['url'];
		return sprintf(
			/* translators: %s: image URL */
			__( 'Image generated successfully. URL: %s', 'wp-mcp-ai' ),
			$url
		);
	}

	// Incomplete metadata - provide generic success message.
	return __( 'Image generation completed.', 'wp-mcp-ai' );
}

/**
 * Add fallback text to generate_gemini_image tool result when OpenAI is the provider.
 *
 * OpenAI's agentic loop requires a text field in tool results. This filter
 * conditionally adds fallback text only when the chat provider is OpenAI.
 *
 * @since 1.0.0
 *
 * @param array $content          Tool result content array.
 * @param array $assistant_config Assistant configuration including provider.
 * @return array Modified content with text field if needed.
 */
function wp_mcp_ai_add_gemini_image_fallback_text( $content, $assistant_config ) {
	// Only apply when provider is OpenAI.
	$provider = isset( $assistant_config['provider'] ) ? $assistant_config['provider'] : '';
	if ( 'openai' !== $provider ) {
		return $content;
	}

	// Only add text if not already present and non-empty.
	if ( ! is_array( $content ) || ( isset( $content['text'] ) && '' !== $content['text'] ) ) {
		return $content;
	}

	// Generate and add fallback text.
	$content['text'] = wp_mcp_ai_generate_gemini_image_fallback_text( $content );

	return $content;
}
add_filter( 'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image', 'wp_mcp_ai_add_gemini_image_fallback_text', 10, 2 );
add_filter( 'wp_mcp_ai_sanitize_tool_result_llm_edit_gemini_image', 'wp_mcp_ai_add_gemini_image_fallback_text', 10, 2 );
