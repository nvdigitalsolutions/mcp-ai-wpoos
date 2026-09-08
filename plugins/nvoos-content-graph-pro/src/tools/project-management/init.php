<?php
/**
 * Project Management Toolkit Initialization (ecosystem port — Wave F2, PM
 * data layer).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/project-management/init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Loads the PM data layer: the shared
 * engine classes and the PM CPT set.
 *
 * Documented deviations from the monolith copy:
 *
 * 1. `declare(strict_types=1)` added.
 * 2. File paths resolve from `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`.
 * 3. Slimmed wiring — the blueprint installer (orchestration dir), the PM
 *    tool files, the admin menu/command-center/settings/blueprints pages,
 *    the research-add integration, and the metabox/admin-columns/AI-actions
 *    admin slices land with their F2 sub-clusters; every deferred require
 *    stays file-gated so this init degrades gracefully until each file
 *    exists (same wave-proof pattern as the CRM init).
 * 4. The JetEngine meta-field registration stays byte-identical
 *    (`function_exists( 'jet_engine' )` + `class_exists(
 *    'WP_MCP_AI_JetEngine_Meta_Helper' )` guard — dormant standalone).
 * 5. New standalone-only tool wiring (no monolith counterpart — the
 *    monolith builds the PM tool map inline inside
 *    `wp_mcp_ai_pro_register_tools()`): a `wp_mcp_ai_pro_tools` filter
 *    carrying the ported tool subset (inert standalone — the base plugin
 *    consumes it monolith) plus `wp_mcp_ai_pro_register_pm_ecosystem_tools()`
 *    registering the ported tools into the ecosystem graph ToolRegistry via
 *    `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the CRM/e-commerce inits).
 *    The tool maps fill as the PM tool batches land.
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if Project Management toolkit is enabled (byte-identical gate).
$nvoos_content_graph_pro_pm_settings   = get_option( 'wp_mcp_ai_settings', array() );
$nvoos_content_graph_pro_pm_is_enabled = ! empty( $nvoos_content_graph_pro_pm_settings['enable_project_management'] );
$nvoos_content_graph_pro_pm_is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $nvoos_content_graph_pro_pm_is_enabled && ! $nvoos_content_graph_pro_pm_is_base ) {

	// ---- Phase A: Shared PM engine (loaded before any tool) ----
	$nvoos_content_graph_pro_pm_engine_dir = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/';

	// Shared engine classes (mirrors CRM toolkit architecture).
	$nvoos_content_graph_pro_pm_files = array(
		'class-wp-mcp-ai-pm-engine.php',
		'class-wp-mcp-ai-pm-codes.php',
		'class-wp-mcp-ai-pm-pipeline-stages.php',
		'class-wp-mcp-ai-pm-capabilities.php',
		'class-wp-mcp-ai-pm-workflow-engine.php',
	);
	foreach ( $nvoos_content_graph_pro_pm_files as $nvoos_content_graph_pro_pm_file ) {
		$nvoos_content_graph_pro_pm_path = $nvoos_content_graph_pro_pm_engine_dir . $nvoos_content_graph_pro_pm_file;
		if ( file_exists( $nvoos_content_graph_pro_pm_path ) ) {
			require_once $nvoos_content_graph_pro_pm_path;
		}
	}

	// Load shared blueprint installer (used by import_pm_blueprint) — lands
	// with the orchestration slice; the require stays file-gated.
	$nvoos_content_graph_pro_pm_installer = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
	if ( file_exists( $nvoos_content_graph_pro_pm_installer ) ) {
		require_once $nvoos_content_graph_pro_pm_installer;
	}

	// Load CPT classes (self-boot at file load, byte-identical with the base).
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-project-cpt.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-task-cpt.php';
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-event-cpt.php';

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
					'name'               => __( 'Sprints', 'nvoos-content-graph-pro' ),
					'singular_name'      => __( 'Sprint', 'nvoos-content-graph-pro' ),
					'add_new'            => __( 'Add New', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Sprint', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Sprint', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Sprint', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Sprint', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Sprints', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No sprints found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No sprints found in trash', 'nvoos-content-graph-pro' ),
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
					'name'               => __( 'PM Workflow Rules', 'nvoos-content-graph-pro' ),
					'singular_name'      => __( 'PM Workflow Rule', 'nvoos-content-graph-pro' ),
					'add_new'            => __( 'Add New', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New PM Workflow Rule', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit PM Workflow Rule', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New PM Workflow Rule', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View PM Workflow Rule', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search PM Workflow Rules', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No PM workflow rules found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No PM workflow rules found in trash', 'nvoos-content-graph-pro' ),
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

	// Load admin pages (deferred — file-gated requires land with the F2 PM
	// admin slice).
	if ( is_admin() ) {
		// Load PM Admin Menu registry (top-level "NV Projects" menu).
		$nvoos_content_graph_pro_pm_admin_menu = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pm-admin-menu.php';
		if ( file_exists( $nvoos_content_graph_pro_pm_admin_menu ) ) {
			require_once $nvoos_content_graph_pro_pm_admin_menu;
			WP_MCP_AI_PM_Admin_Menu::init();
		}

		// Load PM Command Center page.
		$nvoos_content_graph_pro_pm_command_center = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pm-command-center-page.php';
		if ( file_exists( $nvoos_content_graph_pro_pm_command_center ) ) {
			require_once $nvoos_content_graph_pro_pm_command_center;
			WP_MCP_AI_PM_Command_Center_Page::init();
		}

		// Load PM Toolkit Settings page (existing).
		$nvoos_content_graph_pro_pm_settings_path = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-project-management-toolkit-settings-page.php';
		if ( file_exists( $nvoos_content_graph_pro_pm_settings_path ) ) {
			require_once $nvoos_content_graph_pro_pm_settings_path;
			new WP_MCP_AI_Project_Management_Toolkit_Settings_Page();
		}

		// Load PM Blueprints page.
		$nvoos_content_graph_pro_pm_blueprints = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-pm-blueprints-page.php';
		if ( file_exists( $nvoos_content_graph_pro_pm_blueprints ) ) {
			require_once $nvoos_content_graph_pro_pm_blueprints;
			WP_MCP_AI_PM_Blueprints_Page::init();
		}

		// Load Research & Add for CCT/CPT integration.
		$nvoos_content_graph_pro_pm_research_add_path = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/research-add/class-wp-mcp-ai-project-management-research-add.php';
		if ( file_exists( $nvoos_content_graph_pro_pm_research_add_path ) ) {
			require_once $nvoos_content_graph_pro_pm_research_add_path;
			new WP_MCP_AI_Project_Management_Research_Add();
		}

		// Load Project Research & Add and Settings pages (under Projects menu).
		$nvoos_content_graph_pro_project_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-project-research-page.php';
		if ( file_exists( $nvoos_content_graph_pro_project_research ) ) {
			require_once $nvoos_content_graph_pro_project_research;
		}
		$nvoos_content_graph_pro_project_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-project-settings-page.php';
		if ( file_exists( $nvoos_content_graph_pro_project_settings ) ) {
			require_once $nvoos_content_graph_pro_project_settings;
		}

		// Load Event Research & Add and Settings pages (under Events menu).
		$nvoos_content_graph_pro_event_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-event-research-page.php';
		if ( file_exists( $nvoos_content_graph_pro_event_research ) ) {
			require_once $nvoos_content_graph_pro_event_research;
		}
		$nvoos_content_graph_pro_event_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-event-settings-page.php';
		if ( file_exists( $nvoos_content_graph_pro_event_settings ) ) {
			require_once $nvoos_content_graph_pro_event_settings;
		}

		// Load Event Consolidate & Add page (under Events menu).
		$nvoos_content_graph_pro_event_consolidate = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-event-consolidate-page.php';
		if ( file_exists( $nvoos_content_graph_pro_event_consolidate ) ) {
			require_once $nvoos_content_graph_pro_event_consolidate;
			WP_MCP_AI_Event_Consolidate_Page::init();
		}

		// Load Task Research & Add page (under Tasks menu).
		$nvoos_content_graph_pro_task_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-task-research-page.php';
		if ( file_exists( $nvoos_content_graph_pro_task_research ) ) {
			require_once $nvoos_content_graph_pro_task_research;
			WP_MCP_AI_Task_Research_Page::init();
		}
	}
}

// ---- Backward-compatible CPT registration (runs regardless of admin context) ----

// Load CPT classes.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-project-cpt.php';
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-task-cpt.php';
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-event-cpt.php';

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

	// Load metabox classes (deferred — file-gated until the PM admin slice lands).
	$nvoos_content_graph_pro_pm_metaboxes = array(
		'class-wp-mcp-ai-project-metabox.php',
		'class-wp-mcp-ai-task-metabox.php',
		'class-wp-mcp-ai-event-metabox.php',
		'class-wp-mcp-ai-project-management-admin-columns.php',
		'class-wp-mcp-ai-project-management-ai-actions.php',
		'class-wp-mcp-ai-project-management-bulk-ai.php',
	);
	foreach ( $nvoos_content_graph_pro_pm_metaboxes as $nvoos_content_graph_pro_pm_metabox ) {
		$nvoos_content_graph_pro_pm_metabox_path = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/' . $nvoos_content_graph_pro_pm_metabox;
		if ( file_exists( $nvoos_content_graph_pro_pm_metabox_path ) ) {
			require_once $nvoos_content_graph_pro_pm_metabox_path;
		}
	}

	// Initialize metaboxes (file-gated class hooks).
	if ( class_exists( 'WP_MCP_AI_Project_Metabox' ) ) {
		WP_MCP_AI_Project_Metabox::init();
	}
	if ( class_exists( 'WP_MCP_AI_Task_Metabox' ) ) {
		WP_MCP_AI_Task_Metabox::init();
	}
	if ( class_exists( 'WP_MCP_AI_Event_Metabox' ) ) {
		WP_MCP_AI_Event_Metabox::init();
	}

	// Initialize admin columns.
	if ( class_exists( 'WP_MCP_AI_Project_Management_Admin_Columns' ) ) {
		WP_MCP_AI_Project_Management_Admin_Columns::init();
	}

	// Initialize AI-enhanced features.
	// NOTE: AI Actions metabox registration is disabled - functionality consolidated into AI Assistant metabox.
	// However, AJAX handlers are still needed for the quick action buttons.
	if ( class_exists( 'WP_MCP_AI_Project_Management_AI_Actions' ) ) {
		WP_MCP_AI_Project_Management_AI_Actions::init();
	}
	if ( class_exists( 'WP_MCP_AI_Project_Management_Bulk_AI' ) ) {
		WP_MCP_AI_Project_Management_Bulk_AI::init();
	}
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
	$nvoos_content_graph_pro_pm_css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-project-management.css';
	if ( file_exists( $nvoos_content_graph_pro_pm_css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-project-management-admin',
			NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-project-management.css',
			array(),
			NVOOS_CONTENT_GRAPH_PRO_VERSION
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
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' ) ) {
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
				'name'               => __( 'Task Plans', 'nvoos-content-graph-pro' ),
				'singular_name'      => __( 'Task Plan', 'nvoos-content-graph-pro' ),
				'add_new'            => __( 'Add New', 'nvoos-content-graph-pro' ),
				'add_new_item'       => __( 'Add New Task Plan', 'nvoos-content-graph-pro' ),
				'edit_item'          => __( 'Edit Task Plan', 'nvoos-content-graph-pro' ),
				'new_item'           => __( 'New Task Plan', 'nvoos-content-graph-pro' ),
				'view_item'          => __( 'View Task Plan', 'nvoos-content-graph-pro' ),
				'search_items'       => __( 'Search Task Plans', 'nvoos-content-graph-pro' ),
				'not_found'          => __( 'No task plans found', 'nvoos-content-graph-pro' ),
				'not_found_in_trash' => __( 'No task plans found in trash', 'nvoos-content-graph-pro' ),
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
				'name'               => __( 'Task Templates', 'nvoos-content-graph-pro' ),
				'singular_name'      => __( 'Task Template', 'nvoos-content-graph-pro' ),
				'add_new'            => __( 'Add New', 'nvoos-content-graph-pro' ),
				'add_new_item'       => __( 'Add New Task Template', 'nvoos-content-graph-pro' ),
				'edit_item'          => __( 'Edit Task Template', 'nvoos-content-graph-pro' ),
				'new_item'           => __( 'New Task Template', 'nvoos-content-graph-pro' ),
				'view_item'          => __( 'View Task Template', 'nvoos-content-graph-pro' ),
				'search_items'       => __( 'Search Task Templates', 'nvoos-content-graph-pro' ),
				'not_found'          => __( 'No task templates found', 'nvoos-content-graph-pro' ),
				'not_found_in_trash' => __( 'No task templates found in trash', 'nvoos-content-graph-pro' ),
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
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' ) ) {
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
				'name'          => __( 'Project Categories', 'nvoos-content-graph-pro' ),
				'singular_name' => __( 'Project Category', 'nvoos-content-graph-pro' ),
				'search_items'  => __( 'Search Project Categories', 'nvoos-content-graph-pro' ),
				'all_items'     => __( 'All Project Categories', 'nvoos-content-graph-pro' ),
				'edit_item'     => __( 'Edit Project Category', 'nvoos-content-graph-pro' ),
				'update_item'   => __( 'Update Project Category', 'nvoos-content-graph-pro' ),
				'add_new_item'  => __( 'Add New Project Category', 'nvoos-content-graph-pro' ),
				'new_item_name' => __( 'New Project Category Name', 'nvoos-content-graph-pro' ),
				'menu_name'     => __( 'Categories', 'nvoos-content-graph-pro' ),
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
		'development'     => __( 'Development', 'nvoos-content-graph-pro' ),
		'design'          => __( 'Design', 'nvoos-content-graph-pro' ),
		'marketing'       => __( 'Marketing', 'nvoos-content-graph-pro' ),
		'research'        => __( 'Research', 'nvoos-content-graph-pro' ),
		'health-wellness' => __( 'Health & Wellness', 'nvoos-content-graph-pro' ),
		'infrastructure'  => __( 'Infrastructure', 'nvoos-content-graph-pro' ),
		'content'         => __( 'Content', 'nvoos-content-graph-pro' ),
		'other'           => __( 'Other', 'nvoos-content-graph-pro' ),
	);

	foreach ( $default_project_categories as $nvoos_content_graph_pro_cat_slug => $nvoos_content_graph_pro_cat_name ) {
		if ( ! term_exists( $nvoos_content_graph_pro_cat_slug, 'mcp_ai_project_category' ) ) {
			wp_insert_term( $nvoos_content_graph_pro_cat_name, 'mcp_ai_project_category', array( 'slug' => $nvoos_content_graph_pro_cat_slug ) );
		}
	}

	// Register Task Category taxonomy.
	register_taxonomy(
		'mcp_ai_task_category',
		'mcp_ai_task',
		array(
			'labels'            => array(
				'name'          => __( 'Task Categories', 'nvoos-content-graph-pro' ),
				'singular_name' => __( 'Task Category', 'nvoos-content-graph-pro' ),
				'search_items'  => __( 'Search Task Categories', 'nvoos-content-graph-pro' ),
				'all_items'     => __( 'All Task Categories', 'nvoos-content-graph-pro' ),
				'edit_item'     => __( 'Edit Task Category', 'nvoos-content-graph-pro' ),
				'update_item'   => __( 'Update Task Category', 'nvoos-content-graph-pro' ),
				'add_new_item'  => __( 'Add New Task Category', 'nvoos-content-graph-pro' ),
				'new_item_name' => __( 'New Task Category Name', 'nvoos-content-graph-pro' ),
				'menu_name'     => __( 'Categories', 'nvoos-content-graph-pro' ),
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
		'development'     => __( 'Development', 'nvoos-content-graph-pro' ),
		'design'          => __( 'Design', 'nvoos-content-graph-pro' ),
		'documentation'   => __( 'Documentation', 'nvoos-content-graph-pro' ),
		'testing'         => __( 'Testing', 'nvoos-content-graph-pro' ),
		'review'          => __( 'Review', 'nvoos-content-graph-pro' ),
		'health-wellness' => __( 'Health & Wellness', 'nvoos-content-graph-pro' ),
		'meeting'         => __( 'Meeting', 'nvoos-content-graph-pro' ),
		'administrative'  => __( 'Administrative', 'nvoos-content-graph-pro' ),
		'other'           => __( 'Other', 'nvoos-content-graph-pro' ),
	);

	foreach ( $default_task_categories as $nvoos_content_graph_pro_task_slug => $nvoos_content_graph_pro_task_name ) {
		if ( ! term_exists( $nvoos_content_graph_pro_task_slug, 'mcp_ai_task_category' ) ) {
			wp_insert_term( $nvoos_content_graph_pro_task_name, 'mcp_ai_task_category', array( 'slug' => $nvoos_content_graph_pro_task_slug ) );
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

	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' ) ) {
		return;
	}

	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pm-notification-manager.php';
	WP_MCP_AI_PM_Notification_Manager::init();
}
add_action( 'init', 'wp_mcp_ai_init_pm_notifications', 20 );

/**
 * Standalone-only tool filter — carries the ported PM tool subset (inert
 * standalone, consumed by the base plugin monolith). The map fills as the
 * PM tool batches land.
 *
 * @param array $tools Existing tool map (class => file).
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_pm_tools( $tools ) {
	$nvoos_content_graph_pro_pm_tools = array(
		'WP_MCP_AI_Tool_Create_Project'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-create-project.php',
		'WP_MCP_AI_Tool_Update_Project'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-update-project.php',
		'WP_MCP_AI_Tool_Delete_Project'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-delete-project.php',
		'WP_MCP_AI_Tool_List_Projects'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-list-projects.php',
		'WP_MCP_AI_Tool_Create_Task'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-create-task.php',
		'WP_MCP_AI_Tool_Update_Task'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-update-task.php',
		'WP_MCP_AI_Tool_Delete_Task'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-delete-task.php',
		'WP_MCP_AI_Tool_List_Tasks'                       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-list-tasks.php',
		'WP_MCP_AI_Tool_Add_Task_Dependency'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-add-task-dependency.php',
		'WP_MCP_AI_Tool_Remove_Task_Dependency'           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-remove-task-dependency.php',
		'WP_MCP_AI_Tool_Get_Task_Dependencies'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-get-task-dependencies.php',
		'WP_MCP_AI_Tool_PARA_Classify_Item'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-para-classify-item.php',
		'WP_MCP_AI_Tool_PARA_Create_Area'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-para-create-area.php',
		'WP_MCP_AI_Tool_PARA_List_Areas'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-para-list-areas.php',
		'WP_MCP_AI_Tool_PARA_Move_To_Archives'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-para-move-to-archives.php',
		'WP_MCP_AI_Tool_PARA_Promote_Resource_To_Project' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-para-promote-resource-to-project.php',
		'WP_MCP_AI_Tool_PARA_Update_Area'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-para-update-area.php',
		'WP_MCP_AI_Tool_PARA_Weekly_Review'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-para-weekly-review.php',
		'WP_MCP_AI_Tool_PM_Capture_Decision'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/class-wp-mcp-ai-tool-pm-capture-decision.php',
		'WP_MCP_AI_Tool_Get_Burndown_Chart'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/analytics/class-wp-mcp-ai-tool-get-burndown-chart.php',
		'WP_MCP_AI_Tool_Get_Team_Velocity'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/analytics/class-wp-mcp-ai-tool-get-team-velocity.php',
		'WP_MCP_AI_Tool_Get_Portfolio_Health'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/analytics/class-wp-mcp-ai-tool-get-portfolio-health.php',
		'WP_MCP_AI_Tool_Get_Resource_Utilization'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/analytics/class-wp-mcp-ai-tool-get-resource-utilization.php',
		'WP_MCP_AI_Tool_Get_Project_Timeline'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/analytics/class-wp-mcp-ai-tool-get-project-timeline.php',
		'WP_MCP_AI_Tool_Forecast_Completion'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/analytics/class-wp-mcp-ai-tool-forecast-completion.php',
		'WP_MCP_AI_Tool_Assess_Project_Risk'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/risk/class-wp-mcp-ai-tool-assess-project-risk.php',
		'WP_MCP_AI_Tool_Detect_Stale_Tasks'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/risk/class-wp-mcp-ai-tool-detect-stale-tasks.php',
		'WP_MCP_AI_Tool_Identify_Blockers'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/risk/class-wp-mcp-ai-tool-identify-blockers.php',
	);

	return array_merge( $tools, $nvoos_content_graph_pro_pm_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported PM tools
 * into the ecosystem graph ToolRegistry and the nvoos/core registry via
 * `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the CRM/e-commerce inits).
 * The list fills as the PM tool batches land.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_pm_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_Create_Project',
			'WP_MCP_AI_Tool_Update_Project',
			'WP_MCP_AI_Tool_Delete_Project',
			'WP_MCP_AI_Tool_List_Projects',
			'WP_MCP_AI_Tool_Create_Task',
			'WP_MCP_AI_Tool_Update_Task',
			'WP_MCP_AI_Tool_Delete_Task',
			'WP_MCP_AI_Tool_List_Tasks',
			'WP_MCP_AI_Tool_Add_Task_Dependency',
			'WP_MCP_AI_Tool_Remove_Task_Dependency',
			'WP_MCP_AI_Tool_Get_Task_Dependencies',
			'WP_MCP_AI_Tool_PARA_Classify_Item',
			'WP_MCP_AI_Tool_PARA_Create_Area',
			'WP_MCP_AI_Tool_PARA_List_Areas',
			'WP_MCP_AI_Tool_PARA_Move_To_Archives',
			'WP_MCP_AI_Tool_PARA_Promote_Resource_To_Project',
			'WP_MCP_AI_Tool_PARA_Update_Area',
			'WP_MCP_AI_Tool_PARA_Weekly_Review',
			'WP_MCP_AI_Tool_PM_Capture_Decision',
			'WP_MCP_AI_Tool_Get_Burndown_Chart',
			'WP_MCP_AI_Tool_Get_Team_Velocity',
			'WP_MCP_AI_Tool_Get_Portfolio_Health',
			'WP_MCP_AI_Tool_Get_Resource_Utilization',
			'WP_MCP_AI_Tool_Get_Project_Timeline',
			'WP_MCP_AI_Tool_Forecast_Completion',
			'WP_MCP_AI_Tool_Assess_Project_Risk',
			'WP_MCP_AI_Tool_Detect_Stale_Tasks',
			'WP_MCP_AI_Tool_Identify_Blockers',
		) as $nvoos_content_graph_pro_tool_class
	) {
		$nvoos_content_graph_pro_adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $nvoos_content_graph_pro_tool_class() );
		try {
			$nvoos_content_graph_pro_parent_registry->register( $nvoos_content_graph_pro_adapter );
		} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
			unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
		}

		// Wrap into the nvoos/core registry so the agentic chat loop can
		// resolve and execute the tool (same path the AI addon uses).
		if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
			$nvoos_content_graph_pro_core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
			try {
				$nvoos_content_graph_pro_core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $nvoos_content_graph_pro_adapter ) );
			} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
				unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
			}
		}
	}
}

// ---- Standalone-only tool wiring (documented deviation, same pattern as
// the CRM/e-commerce inits): a `wp_mcp_ai_pro_tools` filter carrying the
// ported PM tool subset (inert standalone — the base plugin consumes it
// monolith) plus the ecosystem registration below. ----
add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_pm_tools', 10 );

if ( ! defined( 'WP_MCP_AI_PATH' ) && function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
	wp_mcp_ai_pro_register_pm_ecosystem_tools();
}
