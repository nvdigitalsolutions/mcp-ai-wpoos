<?php
/**
 * Integrate with Architect Tool
 *
 * Integrates Site Creator Toolkit with Architect Agent for automated
 * development workflows and code generation.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Integrate with Architect', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Integrates Site Creator Toolkit with Architect Agent for automated development workflows, code generation, and self-editing capabilities.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Workflow type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'generate', 'modify', 'optimize', 'test' ),
				),
				'target'         => array(
					'type'        => 'string',
					'description' => __( 'Target (theme, plugin, component)', 'mcp-ai-wpoos-pro' ),
				),
				'specifications' => array(
					'type'        => 'object',
					'description' => __( 'Detailed specifications for Architect', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'workflow_type', 'target' ),
			'additionalProperties' => false,
		);
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
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$workflow_type  = isset( $arguments['workflow_type'] ) ? sanitize_text_field( $arguments['workflow_type'] ) : 'generate';
		$target         = isset( $arguments['target'] ) ? sanitize_text_field( $arguments['target'] ) : '';
		$specifications = isset( $arguments['specifications'] ) ? $arguments['specifications'] : array();

		if ( empty( $target ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Target is required.', 'mcp-ai-wpoos-pro' ) );
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
			'summary'    => sprintf( __( 'Prepared %1$s workflow for %2$s.', 'mcp-ai-wpoos-pro' ), $workflow_type, $target ),
			'next_steps' => __( 'Workflow ready for Architect Agent execution.', 'mcp-ai-wpoos-pro' ),
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
