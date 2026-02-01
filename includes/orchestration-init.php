<?php
/**
 * Orchestration System Initialization
 *
 * Core autonomous task orchestration functionality.
 * This provides the foundation for autonomous AI workflows.
 *
 * @package WP_MCP_AI
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register orchestration CPT (Task Plans)
 */
function wp_mcp_ai_register_orchestration_cpt() {
	$labels = array(
		'name'                  => _x( 'Task Plans', 'Post type general name', 'mcp-ai-wpoos' ),
		'singular_name'         => _x( 'Task Plan', 'Post type singular name', 'mcp-ai-wpoos' ),
		'menu_name'             => _x( 'Task Plans', 'Admin Menu text', 'mcp-ai-wpoos' ),
		'name_admin_bar'        => _x( 'Task Plan', 'Add New on Toolbar', 'mcp-ai-wpoos' ),
		'add_new'               => __( 'Add New', 'mcp-ai-wpoos' ),
		'add_new_item'          => __( 'Add New Task Plan', 'mcp-ai-wpoos' ),
		'new_item'              => __( 'New Task Plan', 'mcp-ai-wpoos' ),
		'edit_item'             => __( 'Edit Task Plan', 'mcp-ai-wpoos' ),
		'view_item'             => __( 'View Task Plan', 'mcp-ai-wpoos' ),
		'all_items'             => __( 'Task Plans', 'mcp-ai-wpoos' ),
		'search_items'          => __( 'Search Task Plans', 'mcp-ai-wpoos' ),
		'parent_item_colon'     => __( 'Parent Task Plans:', 'mcp-ai-wpoos' ),
		'not_found'             => __( 'No task plans found.', 'mcp-ai-wpoos' ),
		'not_found_in_trash'    => __( 'No task plans found in Trash.', 'mcp-ai-wpoos' ),
		'featured_image'        => _x( 'Task Plan Cover Image', 'Overrides the "Featured Image" phrase', 'mcp-ai-wpoos' ),
		'set_featured_image'    => _x( 'Set cover image', 'Overrides the "Set featured image" phrase', 'mcp-ai-wpoos' ),
		'remove_featured_image' => _x( 'Remove cover image', 'Overrides the "Remove featured image" phrase', 'mcp-ai-wpoos' ),
		'use_featured_image'    => _x( 'Use as cover image', 'Overrides the "Use as featured image" phrase', 'mcp-ai-wpoos' ),
		'archives'              => _x( 'Task Plan archives', 'The post type archive label', 'mcp-ai-wpoos' ),
		'insert_into_item'      => _x( 'Insert into task plan', 'Overrides the "Insert into post"/"Insert into page" phrase', 'mcp-ai-wpoos' ),
		'uploaded_to_this_item' => _x( 'Uploaded to this task plan', 'Overrides the "Uploaded to this post"/"Uploaded to this page" phrase', 'mcp-ai-wpoos' ),
		'filter_items_list'     => _x( 'Filter task plans list', 'Screen reader text for the filter links', 'mcp-ai-wpoos' ),
		'items_list_navigation' => _x( 'Task Plans list navigation', 'Screen reader text for the pagination', 'mcp-ai-wpoos' ),
		'items_list'            => _x( 'Task Plans list', 'Screen reader text for the items list', 'mcp-ai-wpoos' ),
	);

	$args = array(
		'labels'             => $labels,
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => true,
		'show_in_menu'       => 'wp-mcp-ai-dashboard',
		'show_in_rest'       => true,
		'query_var'          => true,
		'rewrite'            => array( 'slug' => 'task-plan' ),
		'capability_type'    => 'post',
		'has_archive'        => false,
		'hierarchical'       => false,
		'menu_position'      => null,
		'menu_icon'          => 'dashicons-list-view',
		'supports'           => array( 'title', 'editor', 'author', 'custom-fields' ),
	);

	register_post_type( 'mcp_task_plan', $args );
}
add_action( 'init', 'wp_mcp_ai_register_orchestration_cpt' );

/**
 * Register core orchestration tools
 */
function wp_mcp_ai_register_orchestration_tools() {
	if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
		return;
	}

	$registry = WP_MCP_AI_Tool_Registry::get_instance();

	// Base orchestration tools directory.
	$tools_dir = WP_MCP_AI_PATH . 'includes/tools/orchestration/';

	// Register 9 core orchestration tools.
	$core_tools = array(
		'create_task_plan'                 => 'class-wp-mcp-ai-tool-create-task-plan.php',
		'update_task_plan'                 => 'class-wp-mcp-ai-tool-update-task-plan.php',
		'get_task_plan'                    => 'class-wp-mcp-ai-tool-get-task-plan.php',
		'manage_autonomous_session'        => 'class-wp-mcp-ai-tool-manage-autonomous-session.php',
		'detect_completion_indicators'     => 'class-wp-mcp-ai-tool-detect-completion-indicators.php',
		'check_exit_conditions'            => 'class-wp-mcp-ai-tool-check-exit-conditions.php',
		'analyze_loop_health'              => 'class-wp-mcp-ai-tool-analyze-loop-health.php',
		'get_session_status'               => 'class-wp-mcp-ai-tool-get-session-status.php',
		'calculate_orchestration_capacity' => 'class-wp-mcp-ai-tool-calculate-orchestration-capacity.php',
	);

	foreach ( $core_tools as $slug => $file ) {
		$file_path = $tools_dir . $file;
		if ( file_exists( $file_path ) ) {
			require_once $file_path;

			// Convert slug to class name.
			$class_name = 'WP_MCP_AI_Tool_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $slug ) ) );

			if ( class_exists( $class_name ) ) {
				$registry->register( new $class_name() );
			}
		}
	}
}
add_action( 'wp_mcp_ai_tools_init', 'wp_mcp_ai_register_orchestration_tools' );

/**
 * Load orchestration dashboard
 *
 * Note: The base plugin orchestration dashboard is loaded from mcp-ai-wpoos.php.
 * The Pro version dashboard (class-wp-mcp-ai-orchestration-dashboard.php) should
 * only be loaded by the Pro addon to avoid conflicts and undefined constant errors.
 *
 * @deprecated This function should not load any dashboard - removed to prevent conflicts.
 */
function wp_mcp_ai_load_orchestration_dashboard() {
	// Base dashboard is loaded from mcp-ai-wpoos.php (class-wp-mcp-ai-admin-orchestration-dashboard.php).
	// Pro dashboard should be loaded by the Pro addon only.
	// Leaving this function empty for backwards compatibility.
}
// Note: Keeping the hook for backwards compatibility, but function does nothing now.
add_action( 'admin_init', 'wp_mcp_ai_load_orchestration_dashboard' );

/**
 * Initialize task plan seeder
 */
function wp_mcp_ai_init_task_plan_seeder() {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-task-plan-seeder.php';
	WP_MCP_AI_Task_Plan_Seeder::init();
}
add_action( 'init', 'wp_mcp_ai_init_task_plan_seeder', 25 );
