<?php
/**
 * Tool for computing resource utilization.
 *
 * Analyzes per-assignee task allocation and identifies over- or
 * under-utilized team members using the PM Engine's utilization model.
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
 * Computes resource utilization per assignee.
 */
class WP_MCP_AI_Tool_Get_Resource_Utilization implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_resource_utilization';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Resource Utilization', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyze per-assignee task allocation and utilization percentage. Identifies over-allocated, under-allocated, and normally-loaded team members. Optionally filter by project.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional project ID to filter utilization to a single project.', 'mcp-ai-wpoos-pro' ),
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
			'profession_tags'       => array( 'project_manager', 'team_lead', 'resource_manager' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view resource utilization.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;

		// Delegate to the PM Engine for base utilization data.
		if ( ! class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Project Management Engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$utilization = WP_MCP_AI_PM_Engine::get_resource_utilization();

		// Filter by project if requested.
		if ( $project_id ) {
			$project = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Get task IDs for this project.
			$project_task_ids = get_posts(
				array(
					'post_type'      => 'mcp_ai_task',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_task_project_id',
					'meta_value'     => $project_id,
				)
			);

			// Recalculate utilization scoped to this project only.
			$assignee_tasks = array();
			foreach ( $project_task_ids as $task_id ) {
				$task_status = get_post_meta( $task_id, '_task_status', true );
				if ( in_array( $task_status, array( 'completed', 'cancelled' ), true ) ) {
					continue;
				}
				$assignee_id = absint( get_post_meta( $task_id, '_task_assigned_to', true ) );
				if ( ! $assignee_id ) {
					$assignee_id = absint( get_post( $task_id )->post_author );
				}
				if ( ! isset( $assignee_tasks[ $assignee_id ] ) ) {
					$assignee_tasks[ $assignee_id ] = 0;
				}
				$assignee_tasks[ $assignee_id ]++;
			}

			$total_tasks = count( $project_task_ids );
			$avg_tasks   = $total_tasks > 0
				? $total_tasks / max( 1, count( $assignee_tasks ) )
				: 0;

			$engine_settings = WP_MCP_AI_PM_Engine::get_toolkit_settings();
			$high_pct        = $engine_settings['risk_thresholds']['utilization_high_pct'];
			$low_pct         = $engine_settings['risk_thresholds']['utilization_low_pct'];

			$utilization = array();
			foreach ( $assignee_tasks as $user_id => $count ) {
				$user   = get_user_by( 'id', $user_id );
				$pct    = $avg_tasks > 0 ? round( ( $count / $avg_tasks ) * 100, 1 ) : 0;
				$status = 'normal';
				if ( $pct >= $high_pct ) {
					$status = 'over_allocated';
				} elseif ( $pct <= $low_pct ) {
					$status = 'under_allocated';
				}

				$utilization[] = array(
					'user_id'         => $user_id,
					'display_name'    => $user ? $user->display_name : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
					'task_count'      => $count,
					'utilization_pct' => $pct,
					'status'          => $status,
				);
			}
		}

		return array(
			'success'      => true,
			'project_id'   => $project_id ? $project_id : null,
			'utilization'  => $utilization,
		);
	}
}
