<?php
/**
 * Project Management Toolkit Initialization
 *
 * Loads the Project Management toolkit system for project tracking,
 * task management, sprint planning, event coordination, resource
 * allocation, and PARA organization.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Project Management toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_project_management'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// ---- Phase A: Shared PM engine (loaded before any tool) ----
	$pm_engine_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/project-management/';

	// Shared engine classes (mirrors CRM toolkit architecture).
	$_pm_files = array(
		'class-wp-mcp-ai-pm-engine.php',
		'class-wp-mcp-ai-pm-codes.php',
		'class-wp-mcp-ai-pm-pipeline-stages.php',
		'class-wp-mcp-ai-pm-capabilities.php',
		'class-wp-mcp-ai-pm-workflow-engine.php',
	);
	foreach ( $_pm_files as $_file ) {
		$_path = $pm_engine_dir . $_file;
		if ( file_exists( $_path ) ) {
			require_once $_path;
		}
	}

	// Load shared blueprint installer (used by import_pm_blueprint).
	$_installer = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
	if ( file_exists( $_installer ) ) {
		require_once $_installer;
	}

	// Load CPT classes.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-project-cpt.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-cpt.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-event-cpt.php';

	// Register meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_project' );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_task' );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_event' );
	}

	// Register Sprint CPT if not already registered.
	if ( ! post_type_exists( 'mcp_ai_sprint' ) ) {
		register_post_type(
			'mcp_ai_sprint',
			array(
				'labels'             => array(
					'name'               => __( 'Sprints', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => __( 'Sprint', 'mcp-ai-wpoos-pro' ),
					'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Sprint', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Sprint', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Sprint', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Sprint', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Sprints', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No sprints found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No sprints found in trash', 'mcp-ai-wpoos-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'nvoos-pm-dashboard',
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author' ),
				'menu_icon'          => 'dashicons-chart-line',
			)
		);
	}

	// Register PM Workflow Rule CPT if not already registered.
	if ( ! post_type_exists( 'mcp_ai_pm_wf_rule' ) ) {
		register_post_type(
			'mcp_ai_pm_wf_rule',
			array(
				'labels'             => array(
					'name'               => __( 'PM Workflow Rules', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => __( 'PM Workflow Rule', 'mcp-ai-wpoos-pro' ),
					'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New PM Workflow Rule', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit PM Workflow Rule', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New PM Workflow Rule', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View PM Workflow Rule', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search PM Workflow Rules', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No PM workflow rules found', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No PM workflow rules found in trash', 'mcp-ai-wpoos-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'nvoos-pm-dashboard',
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author' ),
				'menu_icon'          => 'dashicons-randomize',
			)
		);
	}

	// Load admin pages.
	if ( is_admin() ) {
		// Load PM Admin Menu registry (top-level "NV Projects" menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pm-admin-menu.php';
		WP_MCP_AI_PM_Admin_Menu::init();

		// Load PM Command Center page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pm-command-center-page.php';
		WP_MCP_AI_PM_Command_Center_Page::init();

		// Load PM Toolkit Settings page (existing).
		$_pm_settings_path = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-project-management-toolkit-settings-page.php';
		if ( file_exists( $_pm_settings_path ) ) {
			require_once $_pm_settings_path;
			new WP_MCP_AI_Project_Management_Toolkit_Settings_Page();
		}

		// Load PM Blueprints page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-pm-blueprints-page.php';
		WP_MCP_AI_PM_Blueprints_Page::init();

		// Load Research & Add for CCT/CPT integration.
		$_pm_research_add_path = WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-project-management-research-add.php';
		if ( file_exists( $_pm_research_add_path ) ) {
			require_once $_pm_research_add_path;
			new WP_MCP_AI_Project_Management_Research_Add();
		}

		// Load Project Research & Add and Settings pages (under Projects menu).
		$_project_research = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-project-research-page.php';
		if ( file_exists( $_project_research ) ) {
			require_once $_project_research;
		}
		$_project_settings = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-project-settings-page.php';
		if ( file_exists( $_project_settings ) ) {
			require_once $_project_settings;
		}

		// Load Event Research & Add and Settings pages (under Events menu).
		$_event_research = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-event-research-page.php';
		if ( file_exists( $_event_research ) ) {
			require_once $_event_research;
		}
		$_event_settings = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-event-settings-page.php';
		if ( file_exists( $_event_settings ) ) {
			require_once $_event_settings;
		}

		// Load Event Consolidate & Add page (under Events menu).
		$_event_consolidate = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-event-consolidate-page.php';
		if ( file_exists( $_event_consolidate ) ) {
			require_once $_event_consolidate;
			WP_MCP_AI_Event_Consolidate_Page::init();
		}

		// Load Task Research & Add page (under Tasks menu).
		$_task_research = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-task-research-page.php';
		if ( file_exists( $_task_research ) ) {
			require_once $_task_research;
			WP_MCP_AI_Task_Research_Page::init();
		}
	}
}

// ---- Backward-compatible CPT registration (runs regardless of admin context) ----

// Load CPT classes.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-project-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-task-cpt.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-event-cpt.php';

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
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-project-metabox.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-task-metabox.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-event-metabox.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-project-management-admin-columns.php';

	// Load AI-enhanced features.
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-project-management-ai-actions.php';
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-project-management-bulk-ai.php';

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
 * Register auxiliary project management custom post types.
 *
 * Task Plans and Task Templates are registered here for autonomous orchestration.
 * Main CPTs (Project, Task, Event) are registered in their respective CPT classes.
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

/**
 * Register project management taxonomies.
 */
function wp_mcp_ai_register_project_management_taxonomies() {
	// Only register if project management is enabled and not base version, unless Pro addon is active.
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
		return;
	}

	// Check if project management is enabled in settings.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_project_management'] ) ) {
		return;
	}

	// Register Project Category taxonomy.
	register_taxonomy(
		'mcp_ai_project_category',
		'mcp_ai_project',
		array(
			'labels'            => array(
				'name'          => __( 'Project Categories', 'mcp-ai-wpoos-pro' ),
				'singular_name' => __( 'Project Category', 'mcp-ai-wpoos-pro' ),
				'search_items'  => __( 'Search Project Categories', 'mcp-ai-wpoos-pro' ),
				'all_items'     => __( 'All Project Categories', 'mcp-ai-wpoos-pro' ),
				'edit_item'     => __( 'Edit Project Category', 'mcp-ai-wpoos-pro' ),
				'update_item'   => __( 'Update Project Category', 'mcp-ai-wpoos-pro' ),
				'add_new_item'  => __( 'Add New Project Category', 'mcp-ai-wpoos-pro' ),
				'new_item_name' => __( 'New Project Category Name', 'mcp-ai-wpoos-pro' ),
				'menu_name'     => __( 'Categories', 'mcp-ai-wpoos-pro' ),
			),
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'project-category' ),
		)
	);

	// Register default Project categories.
	$default_project_categories = array(
		'development'     => __( 'Development', 'mcp-ai-wpoos-pro' ),
		'design'          => __( 'Design', 'mcp-ai-wpoos-pro' ),
		'marketing'       => __( 'Marketing', 'mcp-ai-wpoos-pro' ),
		'research'        => __( 'Research', 'mcp-ai-wpoos-pro' ),
		'health-wellness' => __( 'Health & Wellness', 'mcp-ai-wpoos-pro' ),
		'infrastructure'  => __( 'Infrastructure', 'mcp-ai-wpoos-pro' ),
		'content'         => __( 'Content', 'mcp-ai-wpoos-pro' ),
		'other'           => __( 'Other', 'mcp-ai-wpoos-pro' ),
	);

	foreach ( $default_project_categories as $slug => $name ) {
		if ( ! term_exists( $slug, 'mcp_ai_project_category' ) ) {
			wp_insert_term( $name, 'mcp_ai_project_category', array( 'slug' => $slug ) );
		}
	}

	// Register Task Category taxonomy.
	register_taxonomy(
		'mcp_ai_task_category',
		'mcp_ai_task',
		array(
			'labels'            => array(
				'name'          => __( 'Task Categories', 'mcp-ai-wpoos-pro' ),
				'singular_name' => __( 'Task Category', 'mcp-ai-wpoos-pro' ),
				'search_items'  => __( 'Search Task Categories', 'mcp-ai-wpoos-pro' ),
				'all_items'     => __( 'All Task Categories', 'mcp-ai-wpoos-pro' ),
				'edit_item'     => __( 'Edit Task Category', 'mcp-ai-wpoos-pro' ),
				'update_item'   => __( 'Update Task Category', 'mcp-ai-wpoos-pro' ),
				'add_new_item'  => __( 'Add New Task Category', 'mcp-ai-wpoos-pro' ),
				'new_item_name' => __( 'New Task Category Name', 'mcp-ai-wpoos-pro' ),
				'menu_name'     => __( 'Categories', 'mcp-ai-wpoos-pro' ),
			),
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'task-category' ),
		)
	);

	// Register default Task categories.
	$default_task_categories = array(
		'development'     => __( 'Development', 'mcp-ai-wpoos-pro' ),
		'design'          => __( 'Design', 'mcp-ai-wpoos-pro' ),
		'documentation'   => __( 'Documentation', 'mcp-ai-wpoos-pro' ),
		'testing'         => __( 'Testing', 'mcp-ai-wpoos-pro' ),
		'review'          => __( 'Review', 'mcp-ai-wpoos-pro' ),
		'health-wellness' => __( 'Health & Wellness', 'mcp-ai-wpoos-pro' ),
		'meeting'         => __( 'Meeting', 'mcp-ai-wpoos-pro' ),
		'administrative'  => __( 'Administrative', 'mcp-ai-wpoos-pro' ),
		'other'           => __( 'Other', 'mcp-ai-wpoos-pro' ),
	);

	foreach ( $default_task_categories as $slug => $name ) {
		if ( ! term_exists( $slug, 'mcp_ai_task_category' ) ) {
			wp_insert_term( $name, 'mcp_ai_task_category', array( 'slug' => $slug ) );
		}
	}
}
add_action( 'init', 'wp_mcp_ai_register_project_management_taxonomies' );

/**
 * Initialize the PM Notification Manager when project management is enabled.
 *
 * This registers assignment/status-change email hooks and schedules the
 * daily due-date digest cron event.
 */
function wp_mcp_ai_init_pm_notifications() {
	// Only load when project management is enabled and the Pro addon is active.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_project_management'] ) ) {
		return;
	}

	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
		return;
	}

	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pm-notification-manager.php';
	WP_MCP_AI_PM_Notification_Manager::init();
}
add_action( 'init', 'wp_mcp_ai_init_pm_notifications', 20 );
