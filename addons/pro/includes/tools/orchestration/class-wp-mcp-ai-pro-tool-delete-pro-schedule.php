<?php
/**
 * Pro tool: Delete a Pro Schedule.
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
 * Provides an AI tool for deleting a named pro schedule.
 */
class WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_pro_schedule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Pro Schedule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Permanently removes a named pro schedule, unschedules its WP cron event, and clears its execution history.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Unique ID of the schedule to delete.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
			),
			'required'             => array( 'schedule_id' ),
			'additionalProperties' => false,
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

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to delete schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_user_member_of_blog( $user_id ) ) {
			return new WP_Error(
				'not_blog_member',
				__( 'You must be a member of this site to delete schedules.', 'mcp-ai-wpoos-pro' )
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

		$name = $schedule['name'];

		WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $schedule_id );

		return array(
			'deleted'     => true,
			'schedule_id' => $schedule_id,
			'name'        => $name,
			'message'     => sprintf(
				/* translators: %s: schedule name */
				__( 'Schedule "%s" deleted successfully.', 'mcp-ai-wpoos-pro' ),
				$name
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'requires-capability',
			'state-changing',
			'destructive',
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.0.0
	 * @return array Extended tool definition.
	 */
	public function get_extended_definition() {
		return array(
			'name'              => $this->get_name(),
			'slug'              => $this->get_slug(),
			'description'       => $this->get_description(),
			'parameters_schema' => $this->get_parameters_schema(),
			'capability_flags'  => $this->get_capability_flags(),
			'toolkit'           => 'schedule-manager',
			'category'          => 'automation',
			'risk_level'        => 'destructive',
		);
	}
}
