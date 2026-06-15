<?php // phpcs:ignore WordPress.Files.FileName -- Class name does not match filename; file included explicitly.
/**
 * ECA Management Toolkit MCP Server
 *
 * Phase 2 Tier-1 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

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
		return __( 'ECA Management', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Extra-curricular activity scheduling, attendance, and reporting for schools. Owns the ECA research surface and integrates with iSAMS / SOCS.',
			'mcp-ai-wpoos-pro'
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
				'label'              => __( 'Research & Add ECAs', 'mcp-ai-wpoos-pro' ),
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
