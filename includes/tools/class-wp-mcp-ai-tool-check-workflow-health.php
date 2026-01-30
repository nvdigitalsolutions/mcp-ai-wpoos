<?php
/**
 * Tool for checking workflow health status.
 *
 * Allows AI assistants to check if workflows are stuck in initialized state
 * and get recommendations for fixing workflow issues.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check health status of team workflows.
 *
 * This tool helps diagnose workflows that are stuck in initialized state,
 * which is common in WordPress plugins where workflows may be waiting for
 * cron execution or async processing.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Check_Workflow_Health implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_workflow_health';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Workflow Health', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks the health status of workflows to detect if they are stuck in initialized state. Provides recommendations for fixing workflow issues. Important for WordPress plugins where workflows may wait for cron/async processing.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'workflow_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional workflow ID to check. If not provided, checks all active workflows.', 'mcp-ai-wpoos' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if orchestrator is available.
		if ( ! class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent orchestration system not available.', 'mcp-ai-wpoos' ),
			);
		}

		$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

		// If workflow_id is provided, check specific workflow.
		if ( ! empty( $arguments['workflow_id'] ) ) {
			$workflow_id = sanitize_text_field( $arguments['workflow_id'] );
			$health      = $orchestrator->check_workflow_health( $workflow_id );

			if ( 'error' === $health['status'] ) {
				return array(
					'success' => false,
					'message' => $health['message'],
				);
			}

			return array(
				'success' => true,
				'message' => __( 'Workflow health check completed.', 'mcp-ai-wpoos' ),
				'health'  => $health,
			);
		}

		// Check all workflows using enhanced coordinator if available.
		if ( class_exists( 'WP_MCP_AI_Enhanced_Workflow_Coordinator' ) ) {
			$coordinator = new WP_MCP_AI_Enhanced_Workflow_Coordinator();
			$health      = $coordinator->get_workflows_health();

			$message = 'healthy' === $health['status']
				? __( 'All workflows are healthy.', 'mcp-ai-wpoos' )
				: __( 'Some workflows require attention.', 'mcp-ai-wpoos' );

			return array(
				'success' => true,
				'message' => $message,
				'health'  => $health,
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Enhanced workflow coordinator not available.', 'mcp-ai-wpoos' ),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'workflow_automation',

			'pattern_compatibility' => array( 'hierarchical' ),

			'profession_tags'       => array( 'project_manager', 'devops_engineer' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => true,  // Read-only operation.
			'local-only'        => true,  // No external API calls.
			'read-only'         => true,  // Only reads data.
			'idempotent'        => true,  // Can be called multiple times safely.
			'cacheable'         => false, // Workflow state changes over time.
			'requires-auth'     => true,  // Needs user authentication.
			'blocking'          => false, // Fast operation.
			'uses-network'      => false, // No network calls.
			'modifies-wp'       => false, // Does not modify WordPress data.
			'expensive'         => false, // Low cost operation.
			'requires-approval' => false, // Auto-approved.
		);
	}
}
