<?php
/**
 * WP_MCP_AI_Tool_Delete_Workflow_Rule (ecosystem port - Wave F2, regulatory-registration tool batch).
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
 * Deletes workflow automation rules.
 */
class WP_MCP_AI_Tool_Delete_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_workflow_rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Workflow Rule', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Permanently deletes a workflow automation rule from the system. This action cannot be undone.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'rule_id' => array(
					'type'        => 'string',
					'description' => __( 'Rule ID to delete (required)', 'nvoos-content-graph-pro' ),
				),
				'confirm' => array(
					'type'        => 'boolean',
					'description' => __( 'Confirmation flag (required, must be true)', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'rule_id', 'confirm' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Deletes workflow rules.
			'destructive',          // Irreversible action.
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete workflow rules.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['rule_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Rule ID is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( empty( $arguments['confirm'] ) ) {
			return new WP_Error( 'wp_mcp_ai_confirmation_required', __( 'Confirmation is required to delete workflow rule.', 'nvoos-content-graph-pro' ) );
		}

		$rule_id = sanitize_text_field( $arguments['rule_id'] );

		// Get existing rules.
		$workflow_rules = get_option( 'wp_mcp_ai_workflow_rules', array() );

		// Verify rule exists.
		if ( ! isset( $workflow_rules[ $rule_id ] ) ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Workflow rule not found.', 'nvoos-content-graph-pro' ) );
		}

		$rule_name = $workflow_rules[ $rule_id ]['name'];

		// Delete rule.
		unset( $workflow_rules[ $rule_id ] );
		update_option( 'wp_mcp_ai_workflow_rules', $workflow_rules );

		// Log deletion.
		$workflow_log   = get_option( 'wp_mcp_ai_workflow_log', array() );
		$workflow_log[] = array(
			'timestamp' => current_time( 'mysql' ),
			'user_id'   => $current_user_id,
			'action'    => 'delete_rule',
			'rule_id'   => $rule_id,
			'rule_name' => $rule_name,
		);
		update_option( 'wp_mcp_ai_workflow_log', array_slice( $workflow_log, -200 ), false );

		return array(
			'success'    => true,
			'rule_id'    => $rule_id,
			'rule_name'  => $rule_name,
			'deleted_at' => current_time( 'mysql' ),
			'message'    => sprintf(
				/* translators: %s: rule name */
				__( 'Workflow rule "%s" deleted successfully.', 'nvoos-content-graph-pro' ),
				$rule_name
			),
		);
	}
}
