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
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-video-frame-extractor-service.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-gemini-video-generation-service.php';

// Load async tool orchestration services.
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-async-tool-orchestrator.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-tool-async-executor.php';
require_once plugin_dir_path( __FILE__ ) . 'services/class-wp-mcp-ai-async-health-monitor.php';

// Initialize video generation service (registers async polling hooks).
WP_MCP_AI_Gemini_Video_Generation_Service::init();

// Initialize orchestration budget enforcement (applies settings via filters).
WP_MCP_AI_Orchestration_Budget_Enforcement_Service::init();

// Load performance monitor service only when not in base version mode.
if ( ! wp_mcp_ai_is_base_version() ) {
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
 * Returns null if not available (e.g., in base version mode).
 *
 * @return WP_MCP_AI_Performance_Monitor_CCT|null Performance monitor service instance or null.
 */
function wp_mcp_ai_get_performance_monitor_service() {
	if ( wp_mcp_ai_is_base_version() ) {
		return null;
	}

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
 * Add fallback text for Gemini image tool results when using OpenAI provider.
 *
 * When OpenAI is the chat provider and calls generate_gemini_image, the tool result
 * may lack a 'text' field that OpenAI's agentic loop requires. This filter adds
 * a fallback text response only when the provider is OpenAI.
 *
 * @since 1.0.0
 */
add_filter(
	'wp_mcp_ai_sanitize_tool_result_llm_generate_gemini_image',
	/**
	 * Generate fallback text for Gemini image results when OpenAI is the provider.
	 *
	 * @param mixed $content          The sanitized tool result content.
	 * @param array $assistant_config Assistant configuration containing provider info.
	 * @return mixed Modified content with fallback text if needed.
	 */
	function ( $content, $assistant_config ) {
		// Only apply when the chat provider is OpenAI.
		$provider = isset( $assistant_config['provider'] ) ? $assistant_config['provider'] : '';
		if ( 'openai' !== $provider ) {
			return $content;
		}

		// If content is not an array, nothing to modify.
		if ( ! is_array( $content ) ) {
			return $content;
		}

		// If text is already present and non-empty, no need for fallback.
		if ( ! empty( $content['text'] ) ) {
			return $content;
		}

		// Generate fallback text based on available data.
		$content['text'] = wp_mcp_ai_generate_gemini_image_fallback_text( $content );

		return $content;
	},
	10,
	2
);

/**
 * Generate fallback text response for Gemini image tool results.
 *
 * Creates a descriptive text message based on available fields in the tool result.
 * Used when the result lacks a 'text' field but OpenAI's agentic loop requires one.
 *
 * @since 1.0.0
 *
 * @param array $result Tool result with available fields.
 * @return string Generated text response.
 */
function wp_mcp_ai_generate_gemini_image_fallback_text( array $result ) {
	// Success with full data (attachment_id + url).
	if ( ! empty( $result['attachment_id'] ) && ! empty( $result['url'] ) ) {
		$title = isset( $result['title'] ) ? $result['title'] : __( 'Generated Image', 'wp-mcp-ai' );
		return sprintf(
			/* translators: 1: image title, 2: attachment ID */
			__( 'Successfully generated image "%1$s" (ID: %2$d).', 'wp-mcp-ai' ),
			$title,
			$result['attachment_id']
		);
	}

	// URL only (no attachment).
	if ( ! empty( $result['url'] ) ) {
		return __( 'Image generated successfully. The image URL is available for viewing.', 'wp-mcp-ai' );
	}

	// Check for error information.
	if ( isset( $result['error'] ) || isset( $result['error_message'] ) ) {
		$error_msg = isset( $result['error_message'] ) ? $result['error_message'] : $result['error'];
		return sprintf(
			/* translators: %s: error message */
			__( 'Image generation encountered an issue: %s', 'wp-mcp-ai' ),
			$error_msg
		);
	}

	// Metadata only (model/format but no URL) - incomplete result.
	$has_only_metadata = ! empty( $result['model'] ) || ! empty( $result['format'] ) || ! empty( $result['aspect_ratio'] );
	$missing_url       = empty( $result['url'] ) && empty( $result['download_url'] );

	if ( $has_only_metadata && $missing_url ) {
		$model = isset( $result['model'] ) ? $result['model'] : 'Gemini';
		return sprintf(
			/* translators: %s: model name */
			__( 'Image generation was attempted with %s but the result is incomplete. Please try again or check the Gemini API configuration.', 'wp-mcp-ai' ),
			$model
		);
	}

	// Default fallback message.
	return __( 'Image generation completed. Please check the Media Library for the result.', 'wp-mcp-ai' );
}

/**
 * Add fallback text for Gemini edit image tool results when using OpenAI provider.
 *
 * Similar to generate_gemini_image, when OpenAI is the chat provider and calls
 * edit_gemini_image, the tool result may lack a 'text' field. This filter adds
 * a fallback text response only when the provider is OpenAI.
 *
 * @since 1.0.0
 */
add_filter(
	'wp_mcp_ai_sanitize_tool_result_llm_edit_gemini_image',
	/**
	 * Generate fallback text for Gemini edit image results when OpenAI is the provider.
	 *
	 * @param mixed $content          The sanitized tool result content.
	 * @param array $assistant_config Assistant configuration containing provider info.
	 * @return mixed Modified content with fallback text if needed.
	 */
	function ( $content, $assistant_config ) {
		// Only apply when the chat provider is OpenAI.
		$provider = isset( $assistant_config['provider'] ) ? $assistant_config['provider'] : '';
		if ( 'openai' !== $provider ) {
			return $content;
		}

		// If content is not an array, nothing to modify.
		if ( ! is_array( $content ) ) {
			return $content;
		}

		// If text is already present and non-empty, no need for fallback.
		if ( ! empty( $content['text'] ) ) {
			return $content;
		}

		// Reuse the same fallback text generator as generate_gemini_image.
		$content['text'] = wp_mcp_ai_generate_gemini_image_fallback_text( $content );

		return $content;
	},
	10,
	2
);
