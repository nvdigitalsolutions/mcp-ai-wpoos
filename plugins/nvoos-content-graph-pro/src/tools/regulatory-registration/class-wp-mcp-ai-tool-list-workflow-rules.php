<?php
/**
 * WP_MCP_AI_Tool_List_Workflow_Rules (ecosystem port - Wave F2, regulatory-registration tool batch).
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
		return __( 'List Workflow Rules', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all configured workflow automation rules with execution statistics and status information.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'filter_enabled'   => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by enabled status (optional)', 'nvoos-content-graph-pro' ),
				),
				'include_disabled' => array(
					'type'        => 'boolean',
					'description' => __( 'Include disabled rules (optional, default: true)', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'include_stats'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include execution statistics (optional, default: true)', 'nvoos-content-graph-pro' ),
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list workflow rules.', 'nvoos-content-graph-pro' ) );
		}

		$filter_enabled   = isset( $arguments['filter_enabled'] ) ? (bool) $arguments['filter_enabled'] : null;
		$include_disabled = isset( $arguments['include_disabled'] ) ? (bool) $arguments['include_disabled'] : true;
		$include_stats    = isset( $arguments['include_stats'] ) ? (bool) $arguments['include_stats'] : true;

		// Get workflow rules.
		$workflow_rules = get_option( 'wp_mcp_ai_workflow_rules', array() );

		$rules_list = array();
		$stats      = array(
			'total'            => 0,
			'enabled'          => 0,
			'disabled'         => 0,
			'total_executions' => 0,
		);

		foreach ( $workflow_rules as $rule_id => $rule ) {
			++$stats['total'];

			// Apply filters.
			if ( null !== $filter_enabled && $rule['enabled'] !== $filter_enabled ) {
				continue;
			}

			if ( ! $include_disabled && ! $rule['enabled'] ) {
				continue;
			}

			// Count statistics.
			if ( $rule['enabled'] ) {
				++$stats['enabled'];
			} else {
				++$stats['disabled'];
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
				$rule_summary['executions']    = absint( $rule['executions'] );
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
