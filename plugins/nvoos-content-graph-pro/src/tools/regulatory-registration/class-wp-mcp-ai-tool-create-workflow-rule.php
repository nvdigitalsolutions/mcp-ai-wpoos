<?php
/**
 * WP_MCP_AI_Tool_Create_Workflow_Rule (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'Create Workflow Rule', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates automated workflow rule with trigger conditions, actions, and execution schedule for registration lifecycle management.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Rule name (required)', 'nvoos-content-graph-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Rule description (optional)', 'nvoos-content-graph-pro' ),
				),
				'trigger'     => array(
					'type'        => 'object',
					'description' => __( 'Trigger conditions (required)', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'event'      => array(
							'type' => 'string',
							'enum' => array( 'status_change', 'expiry_approaching', 'document_uploaded', 'submission_date' ),
						),
						'conditions' => array( 'type' => 'object' ),
					),
					'required'    => array( 'event' ),
				),
				'actions'     => array(
					'type'        => 'array',
					'description' => __( 'Actions to perform (required)', 'nvoos-content-graph-pro' ),
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
					'minItems'    => 1,
				),
				'enabled'     => array(
					'type'        => 'boolean',
					'description' => __( 'Enable rule immediately (optional, default: true)', 'nvoos-content-graph-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create workflow rules.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['name'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Rule name is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( empty( $arguments['trigger'] ) || ! is_array( $arguments['trigger'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Trigger conditions are required.', 'nvoos-content-graph-pro' ) );
		}

		if ( empty( $arguments['actions'] ) || ! is_array( $arguments['actions'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Actions are required.', 'nvoos-content-graph-pro' ) );
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
		$workflow_log   = get_option( 'wp_mcp_ai_workflow_log', array() );
		$workflow_log[] = array(
			'timestamp' => current_time( 'mysql' ),
			'user_id'   => $current_user_id,
			'action'    => 'create_rule',
			'rule_id'   => $rule_id,
			'rule_name' => $name,
		);
		update_option( 'wp_mcp_ai_workflow_log', array_slice( $workflow_log, -200 ), false );

		return array(
			'success'       => true,
			'rule_id'       => $rule_id,
			'name'          => $name,
			'enabled'       => $enabled,
			'trigger_event' => $trigger['event'],
			'action_count'  => count( $actions ),
			'created_at'    => current_time( 'mysql' ),
			'message'       => sprintf(
				/* translators: %s: rule name */
				__( 'Workflow rule "%s" created successfully.', 'nvoos-content-graph-pro' ),
				$name
			),
		);
	}
}
