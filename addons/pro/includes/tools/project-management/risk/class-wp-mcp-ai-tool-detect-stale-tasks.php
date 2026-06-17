<?php
/**
 * Tool for detecting stale tasks.
 *
 * Identifies tasks that have not been modified within a configurable
 * number of days, using the PM Engine's stale-detection logic.
 * Optionally auto-flags them with _pm_stale meta.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects stale tasks with optional auto-flagging.
 */
class WP_MCP_AI_Tool_Detect_Stale_Tasks implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'detect_stale_tasks';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Detect Stale Tasks', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detect tasks that have not been modified within a configurable number of days. Returns stale task details and optionally auto-flags them with stale metadata for dashboards and reporting.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'days'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of days without modification to consider a task stale (default: 14, min: 3, max: 90).', 'mcp-ai-wpoos-pro' ),
					'default'     => 14,
					'minimum'     => 3,
					'maximum'     => 90,
				),
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Optional project ID to scope detection to a single project.', 'mcp-ai-wpoos-pro' ),
				),
				'auto_flag'   => array(
					'type'        => 'boolean',
					'description' => __( 'If true, automatically sets _pm_stale meta on detected stale tasks (default: false).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'project_management',
			'post_type'             => 'mcp_ai_task',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'scrum_master', 'team_lead' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		$flags = array(
			'pro',
			'read-only',
		);

		// Note: auto_flag mode adds write capability dynamically, but we declare
		// the tool as read-only by default. The execute method handles the
		// conditional write.

		return $flags;
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to detect stale tasks.', 'mcp-ai-wpoos-pro' ) );
		}

		$days       = isset( $arguments['days'] ) ? min( absint( $arguments['days'] ), 90 ) : 14;
		$days       = max( 3, $days );
		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$auto_flag  = isset( $arguments['auto_flag'] ) ? (bool) $arguments['auto_flag'] : false;

		// Delegate to the PM Engine for base stale detection.
		if ( ! class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Project Management Engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$stale_tasks = WP_MCP_AI_PM_Engine::detect_stale_tasks( $days );

		// Filter by project if requested.
		if ( $project_id ) {
			$project = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
			}

			$stale_tasks = array_filter(
				$stale_tasks,
				function ( $task ) use ( $project_id ) {
					return absint( $task['project_id'] ) === $project_id;
				}
			);
			$stale_tasks = array_values( $stale_tasks );
		}

		// Enrich with project title.
		foreach ( $stale_tasks as &$task ) {
			if ( ! empty( $task['project_id'] ) ) {
				$project_title = get_the_title( $task['project_id'] );
				$task['project_title'] = $project_title ? $project_title : '';
			} else {
				$task['project_title'] = '';
			}
		}
		unset( $task );

		// Auto-flag if requested.
		$flagged_count = 0;
		if ( $auto_flag ) {
			foreach ( $stale_tasks as $task ) {
				update_post_meta( $task['id'], '_pm_stale', true );
				$flagged_count++;
			}
		}

		return array(
			'success'       => true,
			'days'          => $days,
			'project_id'    => $project_id ? $project_id : null,
			'auto_flagged'  => $auto_flag,
			'flagged_count' => $auto_flag ? $flagged_count : null,
			'stale_tasks'   => array_values( $stale_tasks ),
		);
	}
}
