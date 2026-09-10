<?php
/**
 * tools/eca-management/init.php (ecosystem port — Wave F5, eca-management data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/eca-management/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the CPT/metabox/eca-DB requires resolve from the
 * addon's `src/` copies; the REST-controller require is file-gated until the ECA REST slice lands
 * and the four admin-page requires are file-gated until the ECA admin slice lands (all inside the
 * byte-identical enabled/base-version/pro-active gate); NEW standalone-only wiring (deviation,
 * same as the quiz init): a `wp_mcp_ai_pro_tools` filter plus
 * `wp_mcp_ai_pro_register_eca_ecosystem_tools()` — both carry the full thirty-six-entry map (35
 * monolith tools + the tree-only import tool); full-body `! defined( 'WP_MCP_AI_PATH' )` guard (the global enqueue + REST-route
 * helpers would collide compile-time with the base copy in the monorepo test matrix).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Load ECA CPT class.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-eca-cpt.php';

	// Register ECA and Student meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_eca' );
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_student' );
	}

	// Load ECA database tables (enrollments + attendance).
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/eca/init.php';

	// Load ECA REST API Controller (file-gated until the ECA REST slice lands).
	$nvoos_content_graph_pro_eca_rest = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/rest/class-wp-mcp-ai-eca-rest-controller.php';
	if ( file_exists( $nvoos_content_graph_pro_eca_rest ) ) {
		require_once $nvoos_content_graph_pro_eca_rest;
	}

	// Load ECA Research & Add page.
	if ( is_admin() ) {
		// Check if ECA management is enabled and not in base version (unless Pro addon is active).
		$settings      = get_option( 'wp_mcp_ai_settings', array() );
		$is_enabled    = ! empty( $settings['enable_eca_management'] );
		$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

		if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
			$nvoos_content_graph_pro_eca_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_eca_research ) ) {
				require_once $nvoos_content_graph_pro_eca_research;
			}
			$nvoos_content_graph_pro_eca_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_eca_settings ) ) {
				require_once $nvoos_content_graph_pro_eca_settings;
			}
			$nvoos_content_graph_pro_eca_dashboard = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-dashboard-page.php';
			if ( file_exists( $nvoos_content_graph_pro_eca_dashboard ) ) {
				require_once $nvoos_content_graph_pro_eca_dashboard;
			}
			$nvoos_content_graph_pro_eca_consolidate = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-eca-consolidate-page.php';
			if ( file_exists( $nvoos_content_graph_pro_eca_consolidate ) ) {
				require_once $nvoos_content_graph_pro_eca_consolidate;
			}
		}
	}

	/**
	 * Enqueue ECA management admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	function wp_mcp_ai_enqueue_eca_management_admin_styles( $hook ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter required by admin_enqueue_scripts action.
		// Only load on ECA management edit screens.
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_eca', 'mcp_ai_student' ), true ) ) {
			return;
		}

		// Check if ECA management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'assets/css/admin-eca-management.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-eca-management-admin',
				NVOOS_CONTENT_GRAPH_PRO_URL . 'assets/css/admin-eca-management.css',
				array(),
				NVOOS_CONTENT_GRAPH_PRO_VERSION
			);
		}
	}
	add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_eca_management_admin_styles' );

	/**
	 * Register ECA Management REST API routes.
	 */
	function wp_mcp_ai_register_eca_rest_routes() {
		// Check if ECA management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_eca_management'] ) ) {
			return;
		}

		// Check if not in Base Version or Pro addon is active.
		$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

		if ( $is_base && ! $is_pro_active ) {
			return;
		}

		$controller = new WP_MCP_AI_ECA_REST_Controller();
		$controller->register_routes();
	}
	add_action( 'rest_api_init', 'wp_mcp_ai_register_eca_rest_routes' );

	// ---- Standalone-only tool wiring (deviation). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_eca_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_eca_ecosystem_tools();
	}
} // End monolith guard (deviation).

/**
 * Standalone-only tool filter — mirrors the monolith's inline ECA map (the
 * `enable_eca_management` gate in `mcp-ai-wpoos-pro.php`). Carries the full
 * thirty-six-entry map (35 monolith tools + the tree-only import tool).
 *
 * @param array $tools Existing tool map.
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_eca_tools( $tools ) {
	$nvoos_content_graph_pro_eca_tools = array(
		'WP_MCP_AI_Tool_Create_ECA'                        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-create-eca.php',
		'WP_MCP_AI_Tool_List_ECAs'                         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-list-ecas.php',
		'WP_MCP_AI_Tool_Get_ECA'                           => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-get-eca.php',
		'WP_MCP_AI_Tool_Update_ECA'                        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-update-eca.php',
		'WP_MCP_AI_Tool_Delete_ECA'                        => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-delete-eca.php',
		'WP_MCP_AI_Tool_Create_Student'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-create-student.php',
		'WP_MCP_AI_Tool_List_Students'                     => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-list-students.php',
		'WP_MCP_AI_Tool_Get_Student'                       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-get-student.php',
		'WP_MCP_AI_Tool_Update_Student'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-update-student.php',
		'WP_MCP_AI_Tool_Delete_Student'                    => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-delete-student.php',
		'WP_MCP_AI_Tool_Enroll_Student_ECA'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-enroll-student-eca.php',
		'WP_MCP_AI_Tool_Sync_Students_From_ISAMS'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-sync-students-from-isams.php',
		'WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-sync-ecas-from-isams.php',
		'WP_MCP_AI_Tool_Research_ECA'                      => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-research-eca.php',
		'WP_MCP_AI_Tool_Mark_ECA_Attendance'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-mark-eca-attendance.php',
		'WP_MCP_AI_Tool_Get_ECA_Attendance_Report'         => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-get-eca-attendance-report.php',
		'WP_MCP_AI_Tool_Get_Student_Participation_Summary' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-get-student-participation-summary.php',
		'WP_MCP_AI_Tool_Manage_ECA_Waitlist'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-manage-eca-waitlist.php',
		'WP_MCP_AI_Tool_Withdraw_Student_ECA'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-withdraw-student-eca.php',
		'WP_MCP_AI_Tool_Bulk_Enroll_Students'              => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-bulk-enroll-students.php',
		'WP_MCP_AI_Tool_Check_ECA_Conflicts'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-check-eca-conflicts.php',
		'WP_MCP_AI_Tool_Set_ECA_Schedule'                  => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-set-eca-schedule.php',
		'WP_MCP_AI_Tool_Get_ECA_Timetable'                 => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-get-eca-timetable.php',
		'WP_MCP_AI_Tool_Send_ECA_Notification'             => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-send-eca-notification.php',
		'WP_MCP_AI_Tool_Configure_ECA_Notifications'       => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-configure-eca-notifications.php',
		'WP_MCP_AI_Tool_Send_ECA_Parent_Report'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-send-eca-parent-report.php',
		'WP_MCP_AI_Tool_Generate_ECA_Analytics'            => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-generate-eca-analytics.php',
		'WP_MCP_AI_Tool_Generate_ECA_Participation_Report' => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-generate-eca-participation-report.php',
		'WP_MCP_AI_Tool_Export_ECA_Data'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-export-eca-data.php',
		'WP_MCP_AI_Tool_Sync_ECA_Enrollments_From_ISAMS'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-sync-eca-enrollments-from-isams.php',
		'WP_MCP_AI_Tool_Sync_ECAs_To_ISAMS'                => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-sync-ecas-to-isams.php',
		'WP_MCP_AI_Tool_Sync_ECAs_From_SOCS'               => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-sync-ecas-from-socs.php',
		'WP_MCP_AI_Tool_Manage_ECA_Term'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-manage-eca-term.php',
		'WP_MCP_AI_Tool_Create_ECA_Workflow_Rule'          => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-create-eca-workflow-rule.php',
		'WP_MCP_AI_Tool_Import_ECAs_CSV'                   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/class-wp-mcp-ai-tool-import-ecas-csv.php',
		// Tree-only (not in the monolith map — CRM CC-extras precedent).
		'WP_MCP_AI_Tool_Import_ECA_Management_Blueprint'   => NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/eca-management/examples/class-wp-mcp-ai-tool-import-eca-management-blueprint.php',
	);

	return array_merge( $tools, $nvoos_content_graph_pro_eca_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported ECA tools
 * into the ecosystem graph ToolRegistry and the nvoos/core registry via
 * `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the quiz inits). The list
 * carries the full thirty-six-entry map.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_eca_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array(
			'WP_MCP_AI_Tool_Create_ECA',
			'WP_MCP_AI_Tool_List_ECAs',
			'WP_MCP_AI_Tool_Get_ECA',
			'WP_MCP_AI_Tool_Update_ECA',
			'WP_MCP_AI_Tool_Delete_ECA',
			'WP_MCP_AI_Tool_Create_Student',
			'WP_MCP_AI_Tool_List_Students',
			'WP_MCP_AI_Tool_Get_Student',
			'WP_MCP_AI_Tool_Update_Student',
			'WP_MCP_AI_Tool_Delete_Student',
			'WP_MCP_AI_Tool_Enroll_Student_ECA',
			'WP_MCP_AI_Tool_Sync_Students_From_ISAMS',
			'WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS',
			'WP_MCP_AI_Tool_Research_ECA',
			'WP_MCP_AI_Tool_Mark_ECA_Attendance',
			'WP_MCP_AI_Tool_Get_ECA_Attendance_Report',
			'WP_MCP_AI_Tool_Get_Student_Participation_Summary',
			'WP_MCP_AI_Tool_Manage_ECA_Waitlist',
			'WP_MCP_AI_Tool_Withdraw_Student_ECA',
			'WP_MCP_AI_Tool_Bulk_Enroll_Students',
			'WP_MCP_AI_Tool_Check_ECA_Conflicts',
			'WP_MCP_AI_Tool_Set_ECA_Schedule',
			'WP_MCP_AI_Tool_Get_ECA_Timetable',
			'WP_MCP_AI_Tool_Send_ECA_Notification',
			'WP_MCP_AI_Tool_Configure_ECA_Notifications',
			'WP_MCP_AI_Tool_Send_ECA_Parent_Report',
			'WP_MCP_AI_Tool_Generate_ECA_Analytics',
			'WP_MCP_AI_Tool_Generate_ECA_Participation_Report',
			'WP_MCP_AI_Tool_Export_ECA_Data',
			'WP_MCP_AI_Tool_Sync_ECA_Enrollments_From_ISAMS',
			'WP_MCP_AI_Tool_Sync_ECAs_To_ISAMS',
			'WP_MCP_AI_Tool_Sync_ECAs_From_SOCS',
			'WP_MCP_AI_Tool_Manage_ECA_Term',
			'WP_MCP_AI_Tool_Create_ECA_Workflow_Rule',
			'WP_MCP_AI_Tool_Import_ECAs_CSV',
			'WP_MCP_AI_Tool_Import_ECA_Management_Blueprint',
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
