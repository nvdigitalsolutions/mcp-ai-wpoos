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
