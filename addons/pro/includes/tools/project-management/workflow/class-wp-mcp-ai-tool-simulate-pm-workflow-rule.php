<?php
/**
 * Tool for simulating a PM workflow rule (dry-run).
 *
 * Allows AI assistants to test workflow rules against existing entities
 * before activation, without executing any state-changing actions.
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
 * Simulates a PM workflow rule against historical entities.
 *
 * Uses {@see WP_MCP_AI_PM_Workflow_Engine::simulate_rule()} to evaluate
 * conditions without executing actions.
 */
class WP_MCP_AI_Tool_Simulate_PM_Workflow_Rule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'simulate_pm_workflow_rule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Simulate PM Workflow Rule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Simulate a workflow rule as a dry-run to see which existing entities would match its conditions. Useful for testing automation rules before activating them. No actions are executed during simulation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'rule_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the workflow rule to simulate (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of entities to scan (default: 50, max: 200)', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
			),
			'required'             => array( 'rule_id' ),
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
			'post_type'             => 'mcp_ai_pm_workflow_rule',
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to simulate workflow rules.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$rule_id = isset( $arguments['rule_id'] ) ? absint( $arguments['rule_id'] ) : 0;
		$limit   = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50;

		if ( $rule_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_rule_id', __( 'A valid workflow rule ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Ensure the workflow engine is available.
		if ( ! class_exists( 'WP_MCP_AI_PM_Workflow_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_engine', __( 'Workflow engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = WP_MCP_AI_PM_Workflow_Engine::simulate_rule( $rule_id, $limit );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: total scanned, 2: total matches */
				__( 'Simulation complete: %1$d entities scanned, %2$d matched.', 'mcp-ai-wpoos-pro' ),
				$result['total_scanned'],
				$result['total_matches']
			),
			'simulation' => $result,
		);
	}
}
