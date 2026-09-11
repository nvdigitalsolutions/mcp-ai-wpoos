<?php
/**
 * Toolkit MCP server (ecosystem port — Wave F2, mcp-servers batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-pro-scheduler-mcp-server.php` for the standalone
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
		return __( 'Pro Scheduler', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Managed scheduled tasks, workflows, assistant runs, and channel broadcasts with retry, failure notification, run history, and dry-run validation.',
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
				'page_slug'          => 'nvoos-pro-schedule-research',
				'entity_type'        => 'mcp_ai_schedule',
				'class_ref'          => 'WP_MCP_AI_Pro_Schedule_Research_Page',
				'bound_assistant_id' => 0,
				'label'              => __( 'Research & Add Schedule', 'nvoos-content-graph-pro' ),
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
