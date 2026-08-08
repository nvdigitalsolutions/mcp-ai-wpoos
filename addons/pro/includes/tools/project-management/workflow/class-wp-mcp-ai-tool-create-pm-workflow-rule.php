<?php
/**
 * Tool for creating PM workflow automation rules.
 *
 * Allows AI assistants to create workflow automation rules that trigger
 * on project management events and execute actions when conditions are met.
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
 * Creates a new PM workflow automation rule.
 *
 * Saves as mcp_ai_pm_wf_rule CPT with trigger type, conditions,
 * actions, and active flag stored as post meta.
 */
class WP_MCP_AI_Tool_Create_PM_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Valid trigger types.
	 *
	 * @var string[]
	 */
	const VALID_TRIGGER_TYPES = array(
		'task.status_changed',
		'task.assigned',
		'project.status_changed',
		'task.due_date_reached',
		'event.date_reached',
	);

	/**
	 * Valid condition operators.
	 *
	 * @var string[]
	 */
	const VALID_OPERATORS = array( 'equals', 'not_equals', 'in' );

	/**
	 * Valid action types.
	 *
	 * @var string[]
	 */
	const VALID_ACTION_TYPES = array(
		'update_task_status',
		'update_project_status',
		'send_notification',
		'assign_task',
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_pm_workflow_rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create PM Workflow Rule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create an automation workflow rule for project management. Rules trigger on events like task status changes, assignments, or project status updates. When conditions match, actions such as updating statuses or sending notifications are executed.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'        => array(
					'type'        => 'string',
					'description' => __( 'Workflow rule name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'trigger_type' => array(
					'type'        => 'string',
					'description' => __( 'Trigger event type (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::VALID_TRIGGER_TYPES,
				),
				'conditions'   => array(
					'type'        => 'array',
					'description' => __( 'List of conditions that must all be met for the rule to fire. Each condition has: field (project_category, task_priority, task_status, project_status, assignee), operator (equals, not_equals, in), and value.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'field'    => array(
								'type'        => 'string',
								'description' => __( 'Field to evaluate', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'project_category', 'task_priority', 'task_status', 'project_status', 'assignee' ),
							),
							'operator' => array(
								'type'        => 'string',
								'description' => __( 'Comparison operator', 'mcp-ai-wpoos-pro' ),
								'enum'        => self::VALID_OPERATORS,
							),
							'value'    => array(
								'type'        => array( 'string', 'number', 'array' ),
								'description' => __( 'Value to compare against', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'field', 'operator', 'value' ),
					),
				),
				'actions'      => array(
					'type'        => 'array',
					'description' => __( 'List of actions to execute when conditions are met. Each action has: type (update_task_status, update_project_status, send_notification, assign_task), params (context-dependent object).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type'   => array(
								'type'        => 'string',
								'description' => __( 'Action type', 'mcp-ai-wpoos-pro' ),
								'enum'        => self::VALID_ACTION_TYPES,
							),
							'params' => array(
								'type'        => 'object',
								'description' => __( 'Action parameters (e.g. {"status": "completed"} for update_task_status)', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'type' ),
					),
				),
				'active'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the rule is active (default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'title', 'trigger_type' ),
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
			'risk_level'            => 'standard',
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
			'database-write',
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create workflow rules.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$title        = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$trigger_type = isset( $arguments['trigger_type'] ) ? sanitize_key( $arguments['trigger_type'] ) : '';
		$active       = isset( $arguments['active'] ) ? (bool) $arguments['active'] : true;

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Workflow rule title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! in_array( $trigger_type, self::VALID_TRIGGER_TYPES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_trigger',
				sprintf(
					/* translators: %s: trigger type */
					__( 'Invalid trigger type: %s', 'mcp-ai-wpoos-pro' ),
					esc_html( $trigger_type )
				)
			);
		}

		// Sanitize conditions.
		$conditions = array();
		if ( isset( $arguments['conditions'] ) && is_array( $arguments['conditions'] ) ) {
			foreach ( $arguments['conditions'] as $cond ) {
				if ( ! isset( $cond['field'], $cond['operator'], $cond['value'] ) ) {
					continue;
				}
				$field    = sanitize_key( $cond['field'] );
				$operator = sanitize_key( $cond['operator'] );

				if ( ! in_array( $operator, self::VALID_OPERATORS, true ) ) {
					continue;
				}

				$value = $cond['value'];
				if ( is_array( $value ) ) {
					$value = array_map( 'sanitize_text_field', $value );
				} elseif ( is_string( $value ) ) {
					$value = sanitize_text_field( $value );
				}

				$conditions[] = array(
					'field'    => $field,
					'operator' => $operator,
					'value'    => $value,
				);
			}
		}

		// Sanitize actions.
		$actions = array();
		if ( isset( $arguments['actions'] ) && is_array( $arguments['actions'] ) ) {
			foreach ( $arguments['actions'] as $action ) {
				if ( ! isset( $action['type'] ) ) {
					continue;
				}
				$type = sanitize_key( $action['type'] );

				if ( ! in_array( $type, self::VALID_ACTION_TYPES, true ) ) {
					continue;
				}

				$params = array();
				if ( isset( $action['params'] ) && is_array( $action['params'] ) ) {
					foreach ( $action['params'] as $key => $val ) {
						$params[ sanitize_key( $key ) ] = is_string( $val ) ? sanitize_text_field( $val ) : $val;
					}
				}

				$actions[] = array(
					'type'   => $type,
					'params' => $params,
				);
			}
		}

		// Create workflow rule post.
		$post_data = array(
			'post_type'   => 'mcp_ai_pm_wf_rule',
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_author' => $current_user_id,
		);

		$rule_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $rule_id ) ) {
			return $rule_id;
		}

		// Save rule metadata.
		update_post_meta( $rule_id, '_pm_wf_trigger_type', $trigger_type );
		update_post_meta( $rule_id, '_pm_wf_conditions', wp_json_encode( $conditions ) );
		update_post_meta( $rule_id, '_pm_wf_actions', wp_json_encode( $actions ) );
		update_post_meta( $rule_id, '_pm_wf_active', $active ? '1' : '0' );

		/**
		 * Fires after a workflow rule is created.
		 *
		 * @param int   $rule_id      The workflow rule post ID.
		 * @param array $arguments    Original sanitized arguments.
		 */
		do_action( 'wp_mcp_ai_pm_workflow_rule_created', $rule_id, $arguments );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: rule title */
				__( 'Workflow rule created: %s', 'mcp-ai-wpoos-pro' ),
				$title
			),
			'rule_id' => $rule_id,
			'rule'    => array(
				'id'           => $rule_id,
				'title'        => $title,
				'trigger_type' => $trigger_type,
				'conditions'   => $conditions,
				'actions'      => $actions,
				'active'       => $active,
				'created_at'   => current_time( 'mysql' ),
			),
		);
	}
}
