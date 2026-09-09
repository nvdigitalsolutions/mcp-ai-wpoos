<?php
/**
 * WP_MCP_AI_Tool_Integrate_With_Architect (ecosystem port - Wave F2, site-creator tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/site-creator-toolkit/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
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
 * Integrate with Architect Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Integrate_With_Architect implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'integrate_with_architect';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Integrate with Architect', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Integrates Site Creator Toolkit with Architect Agent for automated development workflows, code generation, and self-editing capabilities.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'workflow_type'  => array(
					'type'        => 'string',
					'description' => __( 'Workflow type', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'generate', 'modify', 'optimize', 'test' ),
				),
				'target'         => array(
					'type'        => 'string',
					'description' => __( 'Target (theme, plugin, component)', 'nvoos-content-graph-pro' ),
				),
				'specifications' => array(
					'type'        => 'object',
					'description' => __( 'Detailed specifications for Architect', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'workflow_type', 'target' ),
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
	 * Execute the tool.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Integration result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize arguments.
		$workflow_type  = isset( $arguments['workflow_type'] ) ? sanitize_text_field( $arguments['workflow_type'] ) : 'generate';
		$target         = isset( $arguments['target'] ) ? sanitize_text_field( $arguments['target'] ) : '';
		$specifications = isset( $arguments['specifications'] ) ? $arguments['specifications'] : array();

		if ( empty( $target ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Target is required.', 'nvoos-content-graph-pro' ) );
		}

		// Prepare Architect workflow.
		$workflow = array(
			'type'           => $workflow_type,
			'target'         => $target,
			'specifications' => $specifications,
			'tools_to_use'   => $this->get_architect_tools( $workflow_type ),
			'workflow_steps' => $this->generate_workflow_steps( $workflow_type, $target ),
		);

		return array(
			'success'    => true,
			'workflow'   => $workflow,
			/* translators: 1: workflow type, 2: target */
			'summary'    => sprintf( __( 'Prepared %1$s workflow for %2$s.', 'nvoos-content-graph-pro' ), $workflow_type, $target ),
			'next_steps' => __( 'Workflow ready for Architect Agent execution.', 'nvoos-content-graph-pro' ),
			'timestamp'  => current_time( 'mysql' ),
		);
	}

	/**
	 * Get Architect tools for workflow.
	 *
	 * @since 1.2.0
	 *
	 * @param string $workflow_type Workflow type.
	 * @return array Tools list.
	 */
	private function get_architect_tools( $workflow_type ) {
		$base_tools = array( 'manage_files', 'search_codebase', 'execute_shell_command' );

		switch ( $workflow_type ) {
			case 'generate':
				$base_tools[] = 'git_operations';
				break;

			case 'test':
				$base_tools[] = 'execute_shell_command';
				break;
		}

		return $base_tools;
	}

	/**
	 * Generate workflow steps.
	 *
	 * @since 1.2.0
	 *
	 * @param string $workflow_type Workflow type.
	 * @param string $target        Target.
	 * @return array Workflow steps.
	 */
	private function generate_workflow_steps( $workflow_type, $target ) {
		return array(
			array(
				'step'        => 1,
				'description' => 'Analyze requirements',
				'tool'        => 'search_codebase',
			),
			array(
				'step'        => 2,
				'description' => 'Generate code structure',
				'tool'        => 'manage_files',
			),
			array(
				'step'        => 3,
				'description' => 'Implement functionality',
				'tool'        => 'manage_files',
			),
			array(
				'step'        => 4,
				'description' => 'Test and validate',
				'tool'        => 'execute_shell_command',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'orchestration', 'requires-capability', 'consumes-tokens', 'non-deterministic' );
	}
}
