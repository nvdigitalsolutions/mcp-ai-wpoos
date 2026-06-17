<?php
/**
 * Pro tool: Get Schedule Run History.
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
 * Provides an AI tool for retrieving execution history of a pro schedule.
 */
class WP_MCP_AI_Pro_Tool_Get_Schedule_Run_History implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_schedule_run_history';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Schedule Run History', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves execution history for a named pro schedule, including run times, durations, and any failure messages.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Unique ID of the schedule whose history to retrieve.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of history entries to return (1-50). Defaults to 20.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
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
		$user_id     = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
		$schedule_id = isset( $arguments['schedule_id'] ) ? sanitize_text_field( $arguments['schedule_id'] ) : '';
		$limit       = isset( $arguments['limit'] ) ? max( 1, min( 50, (int) $arguments['limit'] ) ) : 20;

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to view schedule history.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_user_member_of_blog( $user_id ) ) {
			return new WP_Error(
				'not_blog_member',
				__( 'You must be a member of this site to view schedule history.', 'mcp-ai-wpoos-pro' )
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

		$history = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $schedule_id, $limit );

		// Format entries for readability.
		$formatted = array_map(
			function ( $entry ) {
				return array(
					'status'     => $entry['status'],
					'start_time' => wp_date( DATE_ATOM, $entry['start_time'] ),
					'duration'   => $entry['duration'],
					'error'      => $entry['error'],
				);
			},
			$history
		);

		return array(
			'schedule_id'   => $schedule_id,
			'name'          => $schedule['name'],
			'hook'          => $schedule['hook'],
			'run_count'     => (int) $schedule['run_count'],
			'last_status'   => $schedule['last_run_status'],
			'last_run_time' => $schedule['last_run_time'] ? wp_date( DATE_ATOM, $schedule['last_run_time'] ) : null,
			'history'       => $formatted,
			'total_entries' => count( $formatted ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'requires-capability',
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
			'risk_level'        => 'info',
		);
	}
}
