<?php
/**
 * Tool for listing active workflow automation rules.
 *
 * Allows AI assistants to view configured workflow rules
 * and their execution statistics.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists workflow automation rules.
 */
class WP_MCP_AI_Tool_List_Workflow_Rules implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_workflow_rules';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Workflow Rules', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all configured workflow automation rules with execution statistics and status information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'filter_enabled'    => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by enabled status (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'include_disabled'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include disabled rules (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_stats'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include execution statistics (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list workflow rules.', 'mcp-ai-wpoos-pro' ) );
		}

		$filter_enabled   = isset( $arguments['filter_enabled'] ) ? (bool) $arguments['filter_enabled'] : null;
		$include_disabled = isset( $arguments['include_disabled'] ) ? (bool) $arguments['include_disabled'] : true;
		$include_stats    = isset( $arguments['include_stats'] ) ? (bool) $arguments['include_stats'] : true;

		// Get workflow rules.
		$workflow_rules = get_option( 'wp_mcp_ai_workflow_rules', array() );

		$rules_list = array();
		$stats = array(
			'total'            => 0,
			'enabled'          => 0,
			'disabled'         => 0,
			'total_executions' => 0,
		);

		foreach ( $workflow_rules as $rule_id => $rule ) {
			$stats['total']++;

			// Apply filters.
			if ( null !== $filter_enabled && $rule['enabled'] !== $filter_enabled ) {
				continue;
			}

			if ( ! $include_disabled && ! $rule['enabled'] ) {
				continue;
			}

			// Count statistics.
			if ( $rule['enabled'] ) {
				$stats['enabled']++;
			} else {
				$stats['disabled']++;
			}

			if ( isset( $rule['executions'] ) ) {
				$stats['total_executions'] += absint( $rule['executions'] );
			}

			// Build rule summary.
			$rule_summary = array(
				'id'            => $rule_id,
				'name'          => $rule['name'],
				'enabled'       => $rule['enabled'],
				'trigger_event' => $rule['trigger']['event'],
				'action_count'  => count( $rule['actions'] ),
				'created_at'    => $rule['created_at'],
			);

			if ( $include_stats && isset( $rule['executions'] ) ) {
				$rule_summary['executions'] = absint( $rule['executions'] );
				$rule_summary['last_executed'] = isset( $rule['last_executed'] ) ? $rule['last_executed'] : null;
			}

			if ( ! empty( $rule['description'] ) ) {
				$rule_summary['description'] = $rule['description'];
			}

			$rules_list[] = $rule_summary;
		}

		return array(
			'success'      => true,
			'total'        => count( $rules_list ),
			'statistics'   => $stats,
			'rules'        => $rules_list,
			'retrieved_at' => current_time( 'mysql' ),
		);
	}
}
