<?php
/**
 * Tool for computing portfolio health score.
 *
 * Calculates a composite 0-100 health score across all active projects
 * using the WP_MCP_AI_PM_Engine weighted-dimension model.
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
 * Computes portfolio health score with dimension breakdown.
 */
class WP_MCP_AI_Tool_Get_Portfolio_Health implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_portfolio_health';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Portfolio Health', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate the overall portfolio health score (0-100) across all active projects. Returns a composite score with dimension breakdown including schedule variance, task completion rate, blocker count, overdue task ratio, and resource utilization.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
			'post_type'             => 'mcp_ai_project',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'portfolio_manager', 'executive' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
		);
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view portfolio health.', 'mcp-ai-wpoos-pro' ) );
		}

		// Delegate to the PM Engine.
		if ( ! class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Project Management Engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$health = WP_MCP_AI_PM_Engine::calculate_portfolio_health();

		// Build the dimension breakdown in the requested structure.
		$dimension_breakdown = array(
			'schedule_variance'    => isset( $health['schedule_variance'] ) ? $health['schedule_variance'] : 100,
			'task_completion_rate' => isset( $health['completion_rate'] ) ? $health['completion_rate'] : 100,
			'blocker_count'        => isset( $health['total_blocked_tasks'] ) ? $health['total_blocked_tasks'] : 0,
			'overdue_task_ratio'   => isset( $health['total_overdue_tasks'] ) && isset( $health['total_open_tasks'] ) && $health['total_open_tasks'] > 0
				? round( ( $health['total_overdue_tasks'] / $health['total_open_tasks'] ) * 100, 1 )
				: 0,
			'resource_utilization' => isset( $health['at_risk_count'] ) && isset( $health['total_projects'] ) && $health['total_projects'] > 0
				? round( ( 1 - ( $health['at_risk_count'] / $health['total_projects'] ) ) * 100, 1 )
				: 100,
		);

		return array(
			'success'             => true,
			'score'               => isset( $health['score'] ) ? $health['score'] : 100,
			'dimension_breakdown' => $dimension_breakdown,
			'total_projects'      => isset( $health['total_projects'] ) ? $health['total_projects'] : 0,
			'at_risk_count'       => isset( $health['at_risk_count'] ) ? $health['at_risk_count'] : 0,
			'total_open_tasks'    => isset( $health['total_open_tasks'] ) ? $health['total_open_tasks'] : 0,
			'total_overdue_tasks' => isset( $health['total_overdue_tasks'] ) ? $health['total_overdue_tasks'] : 0,
			'total_blocked_tasks' => isset( $health['total_blocked_tasks'] ) ? $health['total_blocked_tasks'] : 0,
		);
	}
}
