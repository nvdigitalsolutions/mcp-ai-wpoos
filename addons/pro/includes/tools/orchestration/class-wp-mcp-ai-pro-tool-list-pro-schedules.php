<?php
/**
 * Pro tool: List Pro Schedules.
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
 * Provides an AI tool for listing all pro managed schedules.
 */
class WP_MCP_AI_Pro_Tool_List_Pro_Schedules implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_pro_schedules';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Pro Schedules', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all pro managed scheduled tasks, workflows, and assistant runs with their status and next run times.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'enabled_only'  => array(
					'type'        => 'boolean',
					'description' => __( 'When true, returns only enabled schedules.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'schedule_type' => array(
					'type'        => 'string',
					'enum'        => array( 'task', 'workflow', 'assistant_run' ),
					'description' => __( 'Filter by schedule type.', 'mcp-ai-wpoos-pro' ),
				),
				'tag'           => array(
					'type'        => 'string',
					'description' => __( 'Filter schedules that have this tag.', 'mcp-ai-wpoos-pro' ),
				),
			),
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

	 * @param array $arguments Tool arguments.

	 *  * @param array $context   Execution context.
	 *
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to list schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_user_member_of_blog( $user_id ) ) {
			return new WP_Error(
				'not_blog_member',
				__( 'You must be a member of this site to list schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		$filters = array();

		if ( ! empty( $arguments['enabled_only'] ) ) {
			$filters['enabled'] = true;
		}

		if ( ! empty( $arguments['tag'] ) ) {
			$filters['tag'] = sanitize_text_field( $arguments['tag'] );
		}

		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules( $filters );

		// Optional schedule_type filter (post-load since get_schedules doesn't have this filter natively).
		if ( ! empty( $arguments['schedule_type'] ) ) {
			$type_filter = sanitize_text_field( $arguments['schedule_type'] );
			$schedules   = array_filter(
				$schedules,
				function ( $s ) use ( $type_filter ) {
					return ( isset( $s['schedule_type'] ) ? $s['schedule_type'] : 'task' ) === $type_filter;
				}
			);
		}

		$results = array();

		foreach ( $schedules as $schedule ) {
			$next_run = WP_MCP_AI_Pro_Schedule_Manager::get_next_run_time( $schedule['id'] );

			$results[] = array(
				'schedule_id'     => $schedule['id'],
				'name'            => $schedule['name'],
				'description'     => $schedule['description'],
				'schedule_type'   => isset( $schedule['schedule_type'] ) ? $schedule['schedule_type'] : 'task',
				'hook'            => $schedule['hook'],
				'schedule'        => $schedule['schedule'],
				'enabled'         => $schedule['enabled'],
				'priority'        => $schedule['priority'],
				'tags'            => $schedule['tags'],
				'last_run_status' => $schedule['last_run_status'],
				'last_run_time'   => $schedule['last_run_time'] ? wp_date( DATE_ATOM, $schedule['last_run_time'] ) : null,
				'run_count'       => (int) $schedule['run_count'],
				'next_run'        => $next_run ? wp_date( DATE_ATOM, $next_run ) : null,
				'created_at'      => wp_date( DATE_ATOM, $schedule['created_at'] ),
			);
		}

		return array(
			'schedules' => array_values( $results ),
			'total'     => count( $results ),
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
