<?php
/**
 * Tool for updating existing workflow automation rules.
 *
 * Allows AI assistants to modify workflow rule configurations
 * including triggers, actions, and enabled status.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates workflow automation rules.
 */
class WP_MCP_AI_Tool_Update_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_workflow_rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Workflow Rule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates existing workflow automation rule configuration including name, trigger conditions, actions, and enabled status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'rule_id'     => array(
					'type'        => 'string',
					'description' => __( 'Rule ID to update (required)', 'mcp-ai-wpoos-pro' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'New rule name (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'New rule description (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'trigger'     => array(
					'type'        => 'object',
					'description' => __( 'New trigger conditions (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'actions'     => array(
					'type'        => 'array',
					'description' => __( 'New actions (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'enabled'     => array(
					'type'        => 'boolean',
					'description' => __( 'Enable/disable rule (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'rule_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Updates workflow rules.
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update workflow rules.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['rule_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Rule ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$rule_id = sanitize_text_field( $arguments['rule_id'] );

		// Get existing rules.
		$workflow_rules = get_option( 'wp_mcp_ai_workflow_rules', array() );

		// Verify rule exists.
		if ( ! isset( $workflow_rules[ $rule_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Workflow rule not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$rule = $workflow_rules[ $rule_id ];

		// Update fields if provided.
		$updated_fields = array();

		if ( ! empty( $arguments['name'] ) ) {
			$rule['name']     = sanitize_text_field( $arguments['name'] );
			$updated_fields[] = 'name';
		}

		if ( isset( $arguments['description'] ) ) {
			$rule['description'] = sanitize_textarea_field( $arguments['description'] );
			$updated_fields[]    = 'description';
		}

		if ( ! empty( $arguments['trigger'] ) && is_array( $arguments['trigger'] ) ) {
			$rule['trigger']  = $arguments['trigger'];
			$updated_fields[] = 'trigger';
		}

		if ( ! empty( $arguments['actions'] ) && is_array( $arguments['actions'] ) ) {
			$rule['actions']  = $arguments['actions'];
			$updated_fields[] = 'actions';
		}

		if ( isset( $arguments['enabled'] ) ) {
			$rule['enabled']  = (bool) $arguments['enabled'];
			$updated_fields[] = 'enabled';
		}

		// Update metadata.
		$rule['updated_at'] = current_time( 'mysql' );
		$rule['updated_by'] = $current_user_id;

		// Save updated rule.
		$workflow_rules[ $rule_id ] = $rule;
		update_option( 'wp_mcp_ai_workflow_rules', $workflow_rules );

		// Log update.
		$workflow_log   = get_option( 'wp_mcp_ai_workflow_log', array() );
		$workflow_log[] = array(
			'timestamp'      => current_time( 'mysql' ),
			'user_id'        => $current_user_id,
			'action'         => 'update_rule',
			'rule_id'        => $rule_id,
			'rule_name'      => $rule['name'],
			'updated_fields' => $updated_fields,
		);
		update_option( 'wp_mcp_ai_workflow_log', array_slice( $workflow_log, -200 ) );

		return array(
			'success'        => true,
			'rule_id'        => $rule_id,
			'name'           => $rule['name'],
			'updated_fields' => $updated_fields,
			'updated_at'     => current_time( 'mysql' ),
			'message'        => sprintf(
				/* translators: %s: rule name */
				__( 'Workflow rule "%s" updated successfully.', 'mcp-ai-wpoos-pro' ),
				$rule['name']
			),
		);
	}
}
