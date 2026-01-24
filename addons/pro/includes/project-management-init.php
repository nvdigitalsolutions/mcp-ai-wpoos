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

// Load Research & Add and Settings pages for admin.
if ( is_admin() ) {
	// Check if project management is enabled and not in base version (unless Pro addon is active).
	$settings      = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled    = ! empty( $settings['enable_project_management'] );
	$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
		// Load toolkit settings page (under Pro Dashboard).
		require_once __DIR__ . '/admin/class-wp-mcp-ai-project-management-toolkit-settings-page.php';

		// Load Research & Add for CCT/CPT integration.
		require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-project-management-research-add.php';
		new WP_MCP_AI_Project_Management_Research_Add();

		// Load Project Research & Add and Settings pages (under Projects menu).
		require_once __DIR__ . '/admin/class-wp-mcp-ai-project-research-page.php';
		require_once __DIR__ . '/admin/class-wp-mcp-ai-project-settings-page.php';

		// Load Event Research & Add and Settings pages (under Events menu).
		require_once __DIR__ . '/admin/class-wp-mcp-ai-event-research-page.php';
		require_once __DIR__ . '/admin/class-wp-mcp-ai-event-settings-page.php';
	}
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
	// Only register if project management is enabled and not base version, unless Pro addon is active.
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
				'name'               => __( 'Projects', 'mcp-ai-wpoos-pro' ),
				'singular_name'      => __( 'Project', 'mcp-ai-wpoos-pro' ),
				'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'       => __( 'Add New Project', 'mcp-ai-wpoos-pro' ),
				'edit_item'          => __( 'Edit Project', 'mcp-ai-wpoos-pro' ),
				'new_item'           => __( 'New Project', 'mcp-ai-wpoos-pro' ),
				'view_item'          => __( 'View Project', 'mcp-ai-wpoos-pro' ),
				'search_items'       => __( 'Search Projects', 'mcp-ai-wpoos-pro' ),
				'not_found'          => __( 'No projects found', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash' => __( 'No projects found in trash', 'mcp-ai-wpoos-pro' ),
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
				'name'               => __( 'Tasks', 'mcp-ai-wpoos-pro' ),
				'singular_name'      => __( 'Task', 'mcp-ai-wpoos-pro' ),
				'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'       => __( 'Add New Task', 'mcp-ai-wpoos-pro' ),
				'edit_item'          => __( 'Edit Task', 'mcp-ai-wpoos-pro' ),
				'new_item'           => __( 'New Task', 'mcp-ai-wpoos-pro' ),
				'view_item'          => __( 'View Task', 'mcp-ai-wpoos-pro' ),
				'search_items'       => __( 'Search Tasks', 'mcp-ai-wpoos-pro' ),
				'not_found'          => __( 'No tasks found', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash' => __( 'No tasks found in trash', 'mcp-ai-wpoos-pro' ),
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
				'name'               => __( 'Events', 'mcp-ai-wpoos-pro' ),
				'singular_name'      => __( 'Event', 'mcp-ai-wpoos-pro' ),
				'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'       => __( 'Add New Event', 'mcp-ai-wpoos-pro' ),
				'edit_item'          => __( 'Edit Event', 'mcp-ai-wpoos-pro' ),
				'new_item'           => __( 'New Event', 'mcp-ai-wpoos-pro' ),
				'view_item'          => __( 'View Event', 'mcp-ai-wpoos-pro' ),
				'search_items'       => __( 'Search Events', 'mcp-ai-wpoos-pro' ),
				'not_found'          => __( 'No events found', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash' => __( 'No events found in trash', 'mcp-ai-wpoos-pro' ),
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

	// Register Task Plan CPT (for autonomous orchestration).
	register_post_type(
		'mcp_task_plan',
		array(
			'labels'             => array(
				'name'               => __( 'Task Plans', 'mcp-ai-wpoos-pro' ),
				'singular_name'      => __( 'Task Plan', 'mcp-ai-wpoos-pro' ),
				'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'       => __( 'Add New Task Plan', 'mcp-ai-wpoos-pro' ),
				'edit_item'          => __( 'Edit Task Plan', 'mcp-ai-wpoos-pro' ),
				'new_item'           => __( 'New Task Plan', 'mcp-ai-wpoos-pro' ),
				'view_item'          => __( 'View Task Plan', 'mcp-ai-wpoos-pro' ),
				'search_items'       => __( 'Search Task Plans', 'mcp-ai-wpoos-pro' ),
				'not_found'          => __( 'No task plans found', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash' => __( 'No task plans found in trash', 'mcp-ai-wpoos-pro' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=mcp_ai_task',
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'author' ),
			'menu_icon'          => 'dashicons-list-view',
		)
	);

	// Register Task Template CPT (for reusable task plan templates).
	register_post_type(
		'mcp_task_template',
		array(
			'labels'             => array(
				'name'               => __( 'Task Templates', 'mcp-ai-wpoos-pro' ),
				'singular_name'      => __( 'Task Template', 'mcp-ai-wpoos-pro' ),
				'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'       => __( 'Add New Task Template', 'mcp-ai-wpoos-pro' ),
				'edit_item'          => __( 'Edit Task Template', 'mcp-ai-wpoos-pro' ),
				'new_item'           => __( 'New Task Template', 'mcp-ai-wpoos-pro' ),
				'view_item'          => __( 'View Task Template', 'mcp-ai-wpoos-pro' ),
				'search_items'       => __( 'Search Task Templates', 'mcp-ai-wpoos-pro' ),
				'not_found'          => __( 'No task templates found', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash' => __( 'No task templates found in trash', 'mcp-ai-wpoos-pro' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=mcp_ai_task',
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'excerpt', 'author' ),
			'menu_icon'          => 'dashicons-clipboard',
		)
	);
}
add_action( 'init', 'wp_mcp_ai_register_project_management_post_types' );
