<?php
/**
 * Automate Development Workflow Tool
 *
 * Automates end-to-end development workflows from planning to deployment
 * using integrated Site Creator and Architect Agent tools.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automate Development Workflow Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Automate_Development_Workflow implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'automate_development_workflow';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Automate Development Workflow', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Automates end-to-end development workflows from planning to deployment using integrated Site Creator and Architect Agent tools.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_type'    => array(
					'type'        => 'string',
					'description' => __( 'Project type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'site', 'theme', 'plugin', 'component' ),
				),
				'requirements'    => array(
					'type'        => 'string',
					'description' => __( 'Project requirements', 'mcp-ai-wpoos-pro' ),
				),
				'workflow_stages' => array(
					'type'        => 'array',
					'description' => __( 'Stages to include in workflow', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'research', 'plan', 'design', 'develop', 'test', 'deploy' ),
					),
					'default'     => array( 'research', 'plan', 'develop', 'test' ),
				),
			),
			'required'             => array( 'project_type', 'requirements' ),
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
	 * @return array|WP_Error Workflow plan or error.
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
		$project_type    = isset( $arguments['project_type'] ) ? sanitize_text_field( $arguments['project_type'] ) : 'site';
		$requirements    = isset( $arguments['requirements'] ) ? sanitize_textarea_field( $arguments['requirements'] ) : '';
		$workflow_stages = isset( $arguments['workflow_stages'] ) && is_array( $arguments['workflow_stages'] ) ?
			array_map( 'sanitize_text_field', $arguments['workflow_stages'] ) :
			array( 'research', 'plan', 'develop', 'test' );

		if ( empty( $requirements ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Requirements are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Generate automated workflow.
		$workflow = array(
			'project_type' => $project_type,
			'stages'       => array(),
		);

		foreach ( $workflow_stages as $stage ) {
			$workflow['stages'][] = $this->generate_stage_plan( $stage, $project_type );
		}

		return array(
			'success'   => true,
			'workflow'  => $workflow,
			/* translators: 1: project type, 2: number of workflow stages */
			'summary'   => sprintf( __( 'Generated automated workflow for %1$s project with %2$d stages.', 'mcp-ai-wpoos-pro' ), $project_type, count( $workflow_stages ) ),
			'timestamp' => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate stage plan.
	 *
	 * @since 1.2.0
	 *
	 * @param string $stage        Stage name.
	 * @param string $project_type Project type.
	 * @return array Stage plan.
	 */
	private function generate_stage_plan( $stage, $project_type ) {
		$stage_plans = array(
			'research' => array(
				'name'   => 'Research',
				'tools'  => array( 'research_site_best_practices', 'analyze_competitor_sites' ),
				'output' => 'Best practices and competitive analysis',
			),
			'plan'     => array(
				'name'   => 'Planning',
				'tools'  => array( 'generate_site_plan' ),
				'output' => 'Comprehensive project plan',
			),
			'design'   => array(
				'name'   => 'Design',
				'tools'  => array( 'create_hero_section', 'generate_feature_section' ),
				'output' => 'Design components and layouts',
			),
			'develop'  => array(
				'name'   => 'Development',
				'tools'  => array( 'integrate_with_architect', 'scaffold_theme_structure' ),
				'output' => 'Code implementation',
			),
			'test'     => array(
				'name'   => 'Testing',
				'tools'  => array( 'execute_shell_command' ),
				'output' => 'Test results and validation',
			),
			'deploy'   => array(
				'name'   => 'Deployment',
				'tools'  => array( 'save_site_template' ),
				'output' => 'Deployed project',
			),
		);

		return isset( $stage_plans[ $stage ] ) ? $stage_plans[ $stage ] : array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'orchestration', 'requires-capability', 'consumes-tokens', 'non-deterministic' );
	}
}
