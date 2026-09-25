<?php
/**
 * Pro tool: Get Schedule Latest Result.
 *
 * Returns the structured result envelope produced by the most recent run of a
 * Pro Schedule. The envelope is the same one consumed by the Scheduled Result
 * block/Elementor widget.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Provides an AI tool for reading the latest result of a Pro schedule.
 */
class WP_MCP_AI_Pro_Tool_Get_Schedule_Latest_Result implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_schedule_latest_result';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Schedule Latest Result', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the structured result envelope (summary, data, render hint) produced by the most recent run of a Pro Schedule.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reading the most recent run result of a specific Pro Schedule, including its summary and data payload.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Needing full run history with failure details (use get_schedule_run_history) or display-ready HTML (use render_schedule_result).', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'render_schedule_result', 'get_schedule_run_history', 'list_pro_schedules' ),
			'notes'           => __( 'Returns a structured envelope; hand its result to render_schedule_result when you need HTML for chat.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schedule_id' => array(
					'type'        => 'string',
					'description' => __( 'ID of the schedule whose latest result to retrieve.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
			),
			'required'             => array( 'schedule_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'schedule_id' ),
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}


	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id     = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();
		$schedule_id = isset( $arguments['schedule_id'] ) ? sanitize_text_field( $arguments['schedule_id'] ) : '';

		if ( ! user_can( $user_id, 'read_private_posts' ) && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to read schedule results.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' === $schedule_id ) {
			return new WP_Error( 'missing_id', __( 'A schedule_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		if ( ! $schedule ) {
			return new WP_Error(
				'not_found',
				/* translators: %s: schedule ID */
				sprintf( __( 'Schedule "%s" not found.', 'mcp-ai-wpoos-pro' ), $schedule_id )
			);
		}

		$envelope = WP_MCP_AI_Pro_Schedule_Manager::get_latest_result( $schedule_id );
		if ( null === $envelope ) {
			return array(
				'schedule_id' => $schedule_id,
				'name'        => isset( $schedule['name'] ) ? $schedule['name'] : '',
				'envelope'    => null,
				'message'     => __( 'No runs recorded for this schedule yet.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return array(
			'schedule_id' => $schedule_id,
			'name'        => isset( $schedule['name'] ) ? $schedule['name'] : '',
			'envelope'    => $envelope,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'requires-capability', 'pro' );
	}
}
