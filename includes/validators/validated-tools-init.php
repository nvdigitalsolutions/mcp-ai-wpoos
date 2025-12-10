<?php
/**
 * Symfony Validator Tools Initialization
 *
 * Registers validated tools that use Symfony Validator for argument validation.
 * Part of Symfony Phase 2 implementation.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register validated tools using Symfony Validator.
 *
 * This function is hooked to 'wp_mcp_ai_register_tools' to add
 * validated versions of tools that use Symfony Validator attributes
 * for type-safe argument validation.
 *
 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
 */
function wp_mcp_ai_register_validated_tools( $registry ) {
	// Check PHP version - Symfony Validator attributes require PHP 8.0+.
	if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
		// Validated tools not available on PHP < 8.0.
		return;
	}

	// Define validated tools to register.
	$validated_tools = array(
		'WP_MCP_AI_Tool_Save_Post_Validated'          => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-save-post-validated.php',
		'WP_MCP_AI_Tool_Create_Cron_Job_Validated'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-cron-job-validated.php',
		'WP_MCP_AI_Tool_Search_Content_Validated'    => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-content-validated.php',
		'WP_MCP_AI_Tool_Create_Assistant_Validated'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-assistant-validated.php',
		'WP_MCP_AI_Tool_Get_Recent_Posts_Validated'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-recent-posts-validated.php',
		'WP_MCP_AI_Tool_Get_System_Logs_Validated'   => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-system-logs-validated.php',
		'WP_MCP_AI_Tool_Create_Chart_Validated'      => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-chart-validated.php',
		'WP_MCP_AI_Tool_Send_Group_Email_Validated'  => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-group-email-validated.php',
		'WP_MCP_AI_Tool_Create_Woo_Product_Validated' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-woo-product-validated.php',
		// Batch 2 - December 9, 2025.
		'WP_MCP_AI_Tool_Get_User_Info_Validated'     => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-user-info-validated.php',
		// Batch 3 - December 10, 2025.
		'WP_MCP_AI_Tool_Create_Post_Validated'       => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-post-validated.php',
		// Batch 4 - December 10, 2025.
		'WP_MCP_AI_Tool_Transcribe_OpenAI_Audio_Validated' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio-validated.php',
		'WP_MCP_AI_Tool_Generate_Image_Alt_Text_Validated' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-image-alt-text-validated.php',
		'WP_MCP_AI_Tool_Generate_Image_Caption_Validated' => WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-image-caption-validated.php',
	);

	// Register each validated tool.
	foreach ( $validated_tools as $class => $file ) {
		if ( file_exists( $file ) ) {
			require_once $file;
		}

		if ( class_exists( $class ) ) {
			$registry->register_tool( new $class() );
		}
	}
}

// Hook into tool registration process.
add_action( 'wp_mcp_ai_register_tools', 'wp_mcp_ai_register_validated_tools', 15 );
