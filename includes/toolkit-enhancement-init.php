<?php
/**
 * Toolkit Enhancement System Initialization
 *
 * Loads and initializes all toolkit enhancement components.
 * This file should be included from the main plugin file.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize the Toolkit Enhancement System
 *
 * Loads all required classes and initializes the integration layer.
 *
 * @since 1.2.0
 */
function wp_mcp_ai_init_toolkit_enhancement() {
	// Define base path.
	$base_path = plugin_dir_path( __FILE__ );

	// Load constants first (no dependencies).
	require_once $base_path . 'includes/class-wp-mcp-ai-toolkit-constants.php';
	require_once $base_path . 'includes/class-wp-mcp-ai-pattern-constants.php';
	require_once $base_path . 'includes/class-wp-mcp-ai-risk-level-constants.php';

	// Load registries (depend on constants).
	require_once $base_path . 'includes/class-wp-mcp-ai-toolkit-registry.php';
	require_once $base_path . 'includes/class-wp-mcp-ai-pattern-registry.php';

	// Load workflow templates (depends on pattern registry).
	require_once $base_path . 'includes/class-wp-mcp-ai-pattern-workflow-templates.php';

	// Load integration layer (depends on all above).
	require_once $base_path . 'includes/class-wp-mcp-ai-toolkit-enhancement-integration.php';

	// Load admin components.
	if ( is_admin() ) {
		require_once $base_path . 'includes/admin/class-wp-mcp-ai-toolkit-enhancement-dashboard-widget.php';
	}

	// Initialize the integration singleton.
	WP_MCP_AI_Toolkit_Enhancement_Integration::get_instance();

	// Initialize admin dashboard widget.
	if ( is_admin() ) {
		new WP_MCP_AI_Toolkit_Enhancement_Dashboard_Widget();
	}

	// Fire action to allow other components to hook in.
	do_action( 'wp_mcp_ai_toolkit_enhancement_loaded' );
}

// Hook into WordPress initialization.
add_action( 'plugins_loaded', 'wp_mcp_ai_init_toolkit_enhancement', 20 );

/**
 * Get toolkit enhancement integration instance
 *
 * Convenience function for accessing the integration layer.
 *
 * @since 1.2.0
 * @return WP_MCP_AI_Toolkit_Enhancement_Integration Integration instance.
 */
function wp_mcp_ai_get_toolkit_integration() {
	return WP_MCP_AI_Toolkit_Enhancement_Integration::get_instance();
}

/**
 * Get toolkit registry instance
 *
 * Convenience function for accessing the toolkit registry.
 *
 * @since 1.2.0
 * @return WP_MCP_AI_Toolkit_Registry Toolkit registry instance.
 */
function wp_mcp_ai_get_toolkit_registry() {
	static $registry = null;

	if ( null === $registry ) {
		$registry = new WP_MCP_AI_Toolkit_Registry();
	}

	return $registry;
}

/**
 * Get pattern registry instance
 *
 * Convenience function for accessing the pattern registry.
 *
 * @since 1.2.0
 * @return WP_MCP_AI_Pattern_Registry Pattern registry instance.
 */
function wp_mcp_ai_get_pattern_registry() {
	static $registry = null;

	if ( null === $registry ) {
		$toolkit_registry = wp_mcp_ai_get_toolkit_registry();
		$registry         = new WP_MCP_AI_Pattern_Registry( $toolkit_registry );
	}

	return $registry;
}

/**
 * Get workflow templates instance
 *
 * Convenience function for accessing workflow templates.
 *
 * @since 1.2.0
 * @return WP_MCP_AI_Pattern_Workflow_Templates Workflow templates instance.
 */
function wp_mcp_ai_get_workflow_templates() {
	static $templates = null;

	if ( null === $templates ) {
		$pattern_registry = wp_mcp_ai_get_pattern_registry();
		$templates        = new WP_MCP_AI_Pattern_Workflow_Templates( $pattern_registry );
	}

	return $templates;
}

/**
 * Get comprehensive task recommendation
 *
 * High-level API for getting toolkit, pattern, and tool recommendations.
 *
 * @since 1.2.0
 * @param array $task_requirements Task requirements.
 * @return array Comprehensive recommendation.
 */
function wp_mcp_ai_get_task_recommendation( $task_requirements ) {
	$integration = wp_mcp_ai_get_toolkit_integration();
	return $integration->get_task_recommendation( $task_requirements );
}

/**
 * Get tools for a toolkit
 *
 * Convenience function for getting all tools in a toolkit.
 *
 * @since 1.2.0
 * @param string $toolkit_slug Toolkit slug.
 * @return array Array of tool slugs.
 */
function wp_mcp_ai_get_toolkit_tools( $toolkit_slug ) {
	$registry = wp_mcp_ai_get_toolkit_registry();
	return $registry->get_toolkit_tools( $toolkit_slug );
}

/**
 * Get pattern for toolkit
 *
 * Convenience function for getting recommended pattern for a toolkit.
 *
 * @since 1.2.0
 * @param string $toolkit_slug Toolkit slug.
 * @return string|null Primary pattern slug or null.
 */
function wp_mcp_ai_get_toolkit_pattern( $toolkit_slug ) {
	$integration = wp_mcp_ai_get_toolkit_integration();
	return $integration->get_recommended_pattern( $toolkit_slug );
}

/**
 * Get enhancement statistics
 *
 * Get system-wide statistics about toolkit enhancement.
 *
 * @since 1.2.0
 * @return array Statistics array.
 */
function wp_mcp_ai_get_enhancement_stats() {
	$integration = wp_mcp_ai_get_toolkit_integration();
	return $integration->get_statistics();
}
