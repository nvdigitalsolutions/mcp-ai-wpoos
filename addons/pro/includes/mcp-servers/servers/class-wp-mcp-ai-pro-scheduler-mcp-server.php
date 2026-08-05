<?php
/**
 * Pro Scheduler Toolkit MCP Server
 *
 * Phase 8 Tier-1 promotion.  Exposes managed scheduled tasks, workflows,
 * assistant runs, and channel broadcasts via the per-toolkit MCP JSON-RPC
 * endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Scheduler MCP server.
 *
 * Owns the `research-schedule` ingestion surface (R&A Schedule page under
 * NV oOS Pro Dashboard) and exposes 14 orchestration tools for schedule
 * CRUD, dry-run validation, run history, and channel broadcast scheduling.
 */
class WP_MCP_AI_Pro_Scheduler_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'pro-scheduler';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Pro Scheduler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Managed scheduled tasks, workflows, assistant runs, and channel broadcasts with retry, failure notification, run history, and dry-run validation.',
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
				'page_slug'          => 'nvoos-pro-schedule-research',
				'entity_type'        => 'mcp_ai_schedule',
				'class_ref'          => 'WP_MCP_AI_Pro_Schedule_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Schedule', 'mcp-ai-wpoos-pro' ),
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
		 * Filter the candidate tool slugs the Pro Scheduler MCP server exposes.
		 *
		 * @since 1.5.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_pro_scheduler_candidate_tools',
			array(
				'create_pro_schedule',
				'list_pro_schedules',
				'update_pro_schedule',
				'delete_pro_schedule',
				'get_schedule_run_history',
				'get_schedule_latest_result',
				'dry_run_pro_schedule',
				'render_schedule_result',
				'schedule_channel_broadcast',
				'plan_schedules_from_workflow',
				'configure_schedule_widget_defaults',
				'get_session_status',
				'manage_autonomous_session',
				'create_execution_prompt',
			)
		);
	}

	/**
	 * Compute tool scope annotations specific to the Pro Scheduler.
	 *
	 * Read tools: list, get, dry-run, render, history, status, prompt.
	 * Write tools: create, update, delete, broadcast, plan, configure, manage.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, string>
	 */
	public function compute_tool_scopes() {
		return array(
			'create_pro_schedule'                => 'read_write',
			'list_pro_schedules'                 => 'read_only',
			'update_pro_schedule'                => 'read_write',
			'delete_pro_schedule'                => 'read_write',
			'get_schedule_run_history'           => 'read_only',
			'get_schedule_latest_result'         => 'read_only',
			'dry_run_pro_schedule'               => 'read_only',
			'render_schedule_result'             => 'read_only',
			'schedule_channel_broadcast'         => 'read_write',
			'plan_schedules_from_workflow'       => 'read_write',
			'configure_schedule_widget_defaults' => 'read_write',
			'get_session_status'                 => 'read_only',
			'manage_autonomous_session'          => 'read_write',
			'create_execution_prompt'            => 'read_only',
		);
	}

	/**
	 * Default limits for the Pro Scheduler server.
	 *
	 * Scheduling is low-frequency; 30 RPM with 64 KB payload is sufficient.
	 *
	 * @since 1.5.0
	 *
	 * @return array{requests_per_minute: int, max_payload_bytes: int, max_iterations: int}
	 */
	public function get_default_limits() {
		return array(
			'requests_per_minute' => 30,
			'max_payload_bytes'   => 65536,  // 64 KB.
			'max_iterations'      => 5,
		);
	}
}
