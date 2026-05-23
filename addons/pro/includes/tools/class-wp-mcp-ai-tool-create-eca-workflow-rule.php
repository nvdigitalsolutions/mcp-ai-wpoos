<?php
/**
 * Tool for creating automated workflow rules for ECA management.
 *
 * Rules trigger actions based on events like enrollment changes, capacity
 * thresholds, attendance patterns, and schedule conflicts.
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
 * Creates automated workflow rules for ECA management.
 */
class WP_MCP_AI_Tool_Create_ECA_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_eca_workflow_rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create ECA Workflow Rule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates automated workflow rules for ECA management. Rules trigger actions based on events like enrollment changes, capacity thresholds, attendance patterns, and schedule conflicts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'       => array(
					'type'        => 'string',
					'description' => __( 'Workflow rule name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'trigger'    => array(
					'type'        => 'string',
					'description' => __( 'Event that triggers the workflow rule (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'enrollment_full', 'enrollment_empty', 'low_attendance', 'high_attendance', 'schedule_conflict', 'term_end', 'payment_overdue' ),
				),
				'conditions' => array(
					'type'        => 'object',
					'description' => __( 'Optional conditions to refine when the rule fires', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'eca_type'   => array(
							'type'        => 'string',
							'description' => __( 'Filter by ECA type (e.g. club, sport_squad)', 'mcp-ai-wpoos-pro' ),
						),
						'year_group' => array(
							'type'        => 'string',
							'description' => __( 'Filter by year group', 'mcp-ai-wpoos-pro' ),
						),
						'threshold'  => array(
							'type'        => 'integer',
							'description' => __( 'Numeric threshold for attendance or capacity rules', 'mcp-ai-wpoos-pro' ),
							'minimum'     => 0,
						),
					),
				),
				'actions'    => array(
					'type'        => 'array',
					'description' => __( 'Actions to execute when the rule triggers (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'type'   => array(
								'type'        => 'string',
								'description' => __( 'Action type to execute', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'email_admin', 'email_teacher', 'email_parent', 'change_status', 'add_waitlist', 'send_notification' ),
							),
							'config' => array(
								'type'        => 'object',
								'description' => __( 'Action-specific configuration', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'             => array( 'type' ),
						'additionalProperties' => false,
					),
				),
				'enabled'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the rule is enabled', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'eca_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Scope rule to a specific ECA post ID, or omit for all ECAs', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'name', 'trigger', 'actions' ),
			'additionalProperties' => false,
		);
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
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin', 'activities_coordinator' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		return ! empty( $settings['enable_eca_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create ECA workflow rules.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required fields.
		$name = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error(
				'wp_mcp_ai_missing_name',
				__( 'Rule name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$trigger        = isset( $arguments['trigger'] ) ? sanitize_key( $arguments['trigger'] ) : '';
		$valid_triggers = array( 'enrollment_full', 'enrollment_empty', 'low_attendance', 'high_attendance', 'schedule_conflict', 'term_end', 'payment_overdue' );

		if ( ! in_array( $trigger, $valid_triggers, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_trigger',
				__( 'Invalid trigger. Must be one of: enrollment_full, enrollment_empty, low_attendance, high_attendance, schedule_conflict, term_end, payment_overdue.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate actions array.
		$actions = isset( $arguments['actions'] ) && is_array( $arguments['actions'] ) ? $arguments['actions'] : array();
		if ( empty( $actions ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_actions',
				__( 'At least one action is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$valid_action_types = array( 'email_admin', 'email_teacher', 'email_parent', 'change_status', 'add_waitlist', 'send_notification' );
		$sanitized_actions  = array();

		foreach ( $actions as $index => $action_item ) {
			$action_type = isset( $action_item['type'] ) ? sanitize_key( $action_item['type'] ) : '';

			if ( ! in_array( $action_type, $valid_action_types, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_action_type',
					sprintf(
						/* translators: %d: action index */
						__( 'Invalid action type at index %d.', 'mcp-ai-wpoos-pro' ),
						$index
					)
				);
			}

			$config = isset( $action_item['config'] ) && is_array( $action_item['config'] )
				? array_map( 'sanitize_text_field', $action_item['config'] )
				: array();

			$sanitized_actions[] = array(
				'type'   => $action_type,
				'config' => $config,
			);
		}

		// Sanitize optional conditions.
		$conditions     = array();
		$conditions_raw = isset( $arguments['conditions'] ) && is_array( $arguments['conditions'] ) ? $arguments['conditions'] : array();
		if ( ! empty( $conditions_raw ) ) {
			if ( isset( $conditions_raw['eca_type'] ) ) {
				$conditions['eca_type'] = sanitize_key( $conditions_raw['eca_type'] );
			}
			if ( isset( $conditions_raw['year_group'] ) ) {
				$conditions['year_group'] = sanitize_text_field( $conditions_raw['year_group'] );
			}
			if ( isset( $conditions_raw['threshold'] ) ) {
				$conditions['threshold'] = absint( $conditions_raw['threshold'] );
			}
		}

		$enabled = isset( $arguments['enabled'] ) ? (bool) $arguments['enabled'] : true;
		$eca_id  = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		// If eca_id is provided, verify ECA exists.
		if ( $eca_id ) {
			$eca = get_post( $eca_id );
			if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_eca',
					__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Build rule object.
		$rule_id = uniqid( 'rule_', true );
		$rule    = array(
			'id'         => $rule_id,
			'name'       => $name,
			'trigger'    => $trigger,
			'conditions' => $conditions,
			'actions'    => $sanitized_actions,
			'enabled'    => $enabled,
			'eca_id'     => $eca_id ? $eca_id : null,
			'created_at' => current_time( 'mysql' ),
			'created_by' => $current_user_id,
		);

		// Store in options.
		$rules = get_option( 'wp_mcp_ai_eca_workflow_rules', array() );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		$rules[] = $rule;
		update_option( 'wp_mcp_ai_eca_workflow_rules', $rules );

		return array(
			'success' => true,
			'rule_id' => $rule_id,
			'rule'    => $rule,
			'message' => sprintf(
				/* translators: %s: rule name */
				__( 'Workflow rule "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$name
			),
		);
	}
}
