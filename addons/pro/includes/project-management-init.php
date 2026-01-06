<?php
/**
 * Project Management Custom Post Types Registration
 *
 * Registers custom post types for projects, tasks, and events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize project management admin interface.
 */
function wp_mcp_ai_init_project_management_admin() {
	// Only load in admin context.
	if ( ! is_admin() ) {
		return;
	}

	// Check if project management is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_project_management'] ) ) {
		return;
	}

	// Load metabox classes.
	require_once __DIR__ . '/admin/class-wp-mcp-ai-project-metabox.php';
	require_once __DIR__ . '/admin/class-wp-mcp-ai-task-metabox.php';
	require_once __DIR__ . '/admin/class-wp-mcp-ai-event-metabox.php';
	require_once __DIR__ . '/admin/class-wp-mcp-ai-project-management-admin-columns.php';

	// Load AI-enhanced features.
	require_once __DIR__ . '/admin/class-wp-mcp-ai-project-management-ai-actions.php';
	require_once __DIR__ . '/admin/class-wp-mcp-ai-project-management-bulk-ai.php';

	// Initialize metaboxes.
	WP_MCP_AI_Project_Metabox::init();
	WP_MCP_AI_Task_Metabox::init();
	WP_MCP_AI_Event_Metabox::init();

	// Initialize admin columns.
	WP_MCP_AI_Project_Management_Admin_Columns::init();

	// Initialize AI-enhanced features.
	// NOTE: AI Actions metabox registration is disabled - functionality consolidated into AI Assistant metabox.
	// However, AJAX handlers are still needed for the quick action buttons.
	WP_MCP_AI_Project_Management_AI_Actions::init();
	WP_MCP_AI_Project_Management_Bulk_AI::init();
}
add_action( 'admin_init', 'wp_mcp_ai_init_project_management_admin' );

/**
 * Enqueue project management admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_project_management_admin_styles( $hook ) {
	// Only load on project management edit screens.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_project', 'mcp_ai_task', 'mcp_ai_event' ), true ) ) {
		return;
	}

	// Check if project management is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_project_management'] ) ) {
		return;
	}

	// Enqueue admin styles.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-project-management.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-project-management-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-project-management.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_project_management_admin_styles' );

/**
 * Register project management custom post types.
 */
function wp_mcp_ai_register_project_management_post_types() {
	// Only register if project management is enabled and not base version.
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
		return;
	}

	// Check if project management is enabled in settings.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_project_management'] ) ) {
		return;
	}

	// Register Project CPT.
	register_post_type(
		'mcp_ai_project',
		array(
			'labels'             => array(
				'name'               => __( 'Projects', 'wp-mcp-ai' ),
				'singular_name'      => __( 'Project', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Project', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Project', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Project', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Project', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Projects', 'wp-mcp-ai' ),
				'not_found'          => __( 'No projects found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No projects found in trash', 'wp-mcp-ai' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'author' ),
			'menu_icon'          => 'dashicons-portfolio',
		)
	);

	// Register Task CPT.
	register_post_type(
		'mcp_ai_task',
		array(
			'labels'             => array(
				'name'               => __( 'Tasks', 'wp-mcp-ai' ),
				'singular_name'      => __( 'Task', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Task', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Task', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Task', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Task', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Tasks', 'wp-mcp-ai' ),
				'not_found'          => __( 'No tasks found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No tasks found in trash', 'wp-mcp-ai' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'author' ),
			'menu_icon'          => 'dashicons-list-view',
		)
	);

	// Register Event CPT.
	register_post_type(
		'mcp_ai_event',
		array(
			'labels'             => array(
				'name'               => __( 'Events', 'wp-mcp-ai' ),
				'singular_name'      => __( 'Event', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Event', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Event', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Event', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Event', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Events', 'wp-mcp-ai' ),
				'not_found'          => __( 'No events found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No events found in trash', 'wp-mcp-ai' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'author' ),
			'menu_icon'          => 'dashicons-calendar-alt',
		)
	);
}
add_action( 'init', 'wp_mcp_ai_register_project_management_post_types' );

/**
 * Initialize AI Assistant metabox for project management CPTs.
 */
function wp_mcp_ai_init_project_management_ai_assistant() {
	// Check if we're in admin context.
	if ( ! is_admin() ) {
		return;
	}

	// Check if project management is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_project_management'] ) ) {
		return;
	}

	// Load the metabox class.
	require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-project-management-ai-assistant-metabox.php';

	// Initialize the metabox.
	new WP_MCP_AI_Project_Management_AI_Assistant_Metabox();
}
add_action( 'admin_init', 'wp_mcp_ai_init_project_management_ai_assistant' );
