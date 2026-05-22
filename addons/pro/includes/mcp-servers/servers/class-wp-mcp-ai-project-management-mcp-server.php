<?php
/**
 * Project Management Toolkit MCP Server
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
 * Project Management MCP server.
 */
class WP_MCP_AI_Project_Management_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'project-management';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Project Management', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Projects, tasks, and events. Multi-page Research & Add (project, task, event) plus the Event consolidation surface. PARA-method capture and review tools.',
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
				'page_slug'          => 'research-project',
				'entity_type'        => 'mcp_ai_project',
				'class_ref'          => 'WP_MCP_AI_Project_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Projects', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-task',
				'entity_type'        => 'mcp_ai_task',
				'class_ref'          => 'WP_MCP_AI_Task_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Tasks', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'research_add',
				'page_slug'          => 'research-event',
				'entity_type'        => 'mcp_ai_event',
				'class_ref'          => 'WP_MCP_AI_Event_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Events', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'type'               => 'consolidate_add',
				'page_slug'          => 'event-consolidate',
				'entity_type'        => 'mcp_ai_event',
				'class_ref'          => 'WP_MCP_AI_Event_Consolidate_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Consolidate Events', 'mcp-ai-wpoos-pro' ),
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
		 * Filter the candidate tool slugs the Project Management MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_project_management_candidate_tools',
			array(
				'create_project',
				'update_project',
				'delete_project',
				'list_projects',
				'create_task',
				'update_task',
				'delete_task',
				'list_tasks',
				'add_task_dependency',
				'remove_task_dependency',
				'get_task_dependencies',
				'create_event',
				'update_event',
				'delete_event',
				'list_events',
				'para_classify_item',
				'para_create_area',
				'para_update_area',
				'para_list_areas',
				'para_promote_resource_to_project',
				'para_move_to_archives',
				'para_weekly_review',
				'pm_capture_decision',
			)
		);
	}
}
