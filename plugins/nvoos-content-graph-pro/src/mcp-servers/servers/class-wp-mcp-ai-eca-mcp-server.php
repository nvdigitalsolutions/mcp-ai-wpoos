<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-eca-mcp-server.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


// phpcs:ignore WordPress.Files.FileName -- Class name does not match filename; file included explicitly.


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ECA Management MCP server.
 */
class WP_MCP_AI_ECA_Management_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'eca';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'ECA Management', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Extra-curricular activity scheduling, attendance, and reporting for schools. Owns the ECA research surface and integrates with iSAMS / SOCS.',
			'nvoos-content-graph-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array(
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-eca',
				'entity_type'        => 'mcp_ai_eca',
				'class_ref'          => 'WP_MCP_AI_ECA_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add ECAs', 'nvoos-content-graph-pro' ),
			),
			array(
				'type'               => 'consolidate_add',
				'page_slug'          => 'consolidate-eca',
				'entity_type'        => 'mcp_ai_eca',
				'class_ref'          => 'WP_MCP_AI_ECA_Consolidate_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Consolidate & Add Records', 'nvoos-content-graph-pro' ),
			),
		);
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the ECA Management MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_eca_candidate_tools',
			array(
				'create_eca',
				'update_eca',
				'delete_eca',
				'get_eca',
				'list_ecas',
				'research_eca',
				'set_eca_schedule',
				'get_eca_timetable',
				'manage_eca_term',
				'manage_eca_waitlist',
				'check_eca_conflicts',
				'enroll_student_eca',
				'withdraw_student_eca',
				'mark_eca_attendance',
				'get_eca_attendance_report',
				'get_student_participation_summary',
				'create_student',
				'update_student',
				'delete_student',
				'get_student',
				'list_students',
				'bulk_enroll_students',
				'configure_eca_notifications',
				'send_eca_notification',
				'send_eca_parent_report',
				'generate_eca_analytics',
				'generate_eca_participation_report',
				'create_eca_workflow_rule',
				'export_eca_data',
				'import_ecas_csv',
				'sync_ecas_from_isams',
				'sync_ecas_to_isams',
				'sync_eca_enrollments_from_isams',
				'sync_students_from_isams',
				'sync_ecas_from_socs',
				'isams_query',
			)
		);
	}
}
