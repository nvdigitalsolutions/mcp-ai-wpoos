<?php
/**
 * Tool for creating workflow automation rules.
 *
 * Allows AI assistants to define automated workflow rules
 * for registration lifecycle management.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates workflow automation rules.
 */
class WP_MCP_AI_Tool_Create_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_workflow_rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Workflow Rule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates automated workflow rule with trigger conditions, actions, and execution schedule for registration lifecycle management.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Rule name (required)', 'mcp-ai-wpoos-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Rule description (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'trigger'     => array(
					'type'        => 'object',
					'description' => __( 'Trigger conditions (required)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'event'      => array(
							'type' => 'string',
							'enum' => array( 'status_change', 'expiry_approaching', 'document_uploaded', 'submission_date' ),
						),
						'conditions' => array( 'type' => 'object' ),
					),
					'required' => array( 'event' ),
				),
				'actions'     => array(
					'type'        => 'array',
					'description' => __( 'Actions to perform (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type'   => array(
								'type' => 'string',
								'enum' => array( 'send_email', 'update_status', 'create_task', 'webhook' ),
							),
							'params' => array( 'type' => 'object' ),
						),
					),
					'minItems' => 1,
				),
				'enabled'     => array(
					'type'        => 'boolean',
					'description' => __( 'Enable rule immediately (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'name', 'trigger', 'actions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Creates workflow rules.
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
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create workflow rules.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['name'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Rule name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['trigger'] ) || ! is_array( $arguments['trigger'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Trigger conditions are required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['actions'] ) || ! is_array( $arguments['actions'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Actions are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$name        = sanitize_text_field( $arguments['name'] );
		$description = ! empty( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$trigger     = $arguments['trigger'];
		$actions     = $arguments['actions'];
		$enabled     = isset( $arguments['enabled'] ) ? (bool) $arguments['enabled'] : true;

		// Generate rule ID.
		$rule_id = 'rule_' . wp_generate_password( 12, false );

		// Create rule data.
		$rule_data = array(
			'id'          => $rule_id,
			'name'        => $name,
			'description' => $description,
			'trigger'     => $trigger,
			'actions'     => $actions,
			'enabled'     => $enabled,
			'created_at'  => current_time( 'mysql' ),
			'created_by'  => $current_user_id,
			'executions'  => 0,
		);

		// Get existing rules.
		$workflow_rules = get_option( 'wp_mcp_ai_workflow_rules', array() );

		// Add new rule.
		$workflow_rules[ $rule_id ] = $rule_data;

		// Save rules.
		update_option( 'wp_mcp_ai_workflow_rules', $workflow_rules );

		// Log creation.
		$workflow_log = get_option( 'wp_mcp_ai_workflow_log', array() );
		$workflow_log[] = array(
			'timestamp' => current_time( 'mysql' ),
			'user_id'   => $current_user_id,
			'action'    => 'create_rule',
			'rule_id'   => $rule_id,
			'rule_name' => $name,
		);
		update_option( 'wp_mcp_ai_workflow_log', array_slice( $workflow_log, -200 ) );

		return array(
			'success'      => true,
			'rule_id'      => $rule_id,
			'name'         => $name,
			'enabled'      => $enabled,
			'trigger_event' => $trigger['event'],
			'action_count' => count( $actions ),
			'created_at'   => current_time( 'mysql' ),
			'message'      => sprintf(
				/* translators: %s: rule name */
				__( 'Workflow rule "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$name
			),
		);
	}
}
