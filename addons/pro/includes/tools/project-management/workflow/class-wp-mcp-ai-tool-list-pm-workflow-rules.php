<?php
/**
 * Tool for listing PM workflow automation rules.
 *
 * Allows AI assistants to list and filter workflow automation rules
 * by trigger type and active status.
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
 * Lists PM workflow rules with optional filtering.
 */
class WP_MCP_AI_Tool_List_PM_Workflow_Rules implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_pm_workflow_rules';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List PM Workflow Rules', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List workflow automation rules with optional filtering by trigger type and active status. Useful for reviewing and auditing automation configurations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'trigger_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by trigger type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'task.status_changed',
						'task.assigned',
						'project.status_changed',
						'task.due_date_reached',
						'event.date_reached',
					),
				),
				'active'       => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by active status (optional). If omitted, all rules are returned.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of rules to return (default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
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
			'post_type'             => 'mcp_ai_pm_wf_rule',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead' ),
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
		return ! empty( $settings['enable_project_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list workflow rules.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build query args.
		$limit = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 100 ) : 20;

		$query_args = array(
			'post_type'      => 'mcp_ai_pm_wf_rule',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array();

		// Filter by trigger type.
		if ( ! empty( $arguments['trigger_type'] ) ) {
			$meta_query[] = array(
				'key'     => '_pm_wf_trigger_type',
				'value'   => sanitize_key( $arguments['trigger_type'] ),
				'compare' => '=',
			);
		}

		// Filter by active status.
		if ( isset( $arguments['active'] ) && is_bool( $arguments['active'] ) ) {
			$meta_query[] = array(
				'key'     => '_pm_wf_active',
				'value'   => $arguments['active'] ? '1' : '0',
				'compare' => '=',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );
		$rules = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$rule_id = get_the_ID();

				$conditions_raw = get_post_meta( $rule_id, '_pm_wf_conditions', true );
				$conditions     = json_decode( $conditions_raw ? $conditions_raw : '[]', true );
				$actions_raw    = get_post_meta( $rule_id, '_pm_wf_actions', true );
				$actions        = json_decode( $actions_raw ? $actions_raw : '[]', true );
				$active_raw     = get_post_meta( $rule_id, '_pm_wf_active', true );

				$rules[] = array(
					'id'           => $rule_id,
					'title'        => get_the_title(),
					'trigger_type' => get_post_meta( $rule_id, '_pm_wf_trigger_type', true ),
					'conditions'   => is_array( $conditions ) ? $conditions : array(),
					'actions'      => is_array( $actions ) ? $actions : array(),
					'active'       => '0' !== $active_raw,
					'created_at'   => get_the_date( 'c' ),
					'updated_at'   => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'count'   => count( $rules ),
			'total'   => $query->found_posts,
			'rules'   => $rules,
		);
	}
}
