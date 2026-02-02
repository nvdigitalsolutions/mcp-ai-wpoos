<?php
/**
 * Tool for activating enhanced reasoning mode for complex tasks.
 *
 * Part of Phase 3.3: Reasoning Tools implementation.
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enable Enhanced Reasoning Mode Tool
 *
 * Activates reasoning-enhanced configuration for complex multi-step tasks.
 * Uses the Reasoning Controller service to detect task complexity and
 * configure appropriate reasoning enhancements.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Tool_Enable_Reasoning_Mode implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'enable_reasoning_mode';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Enable Reasoning Mode', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Activates enhanced reasoning mode for complex multi-step tasks. Analyzes task complexity across 5 indicators (multi-step, logical complexity, code generation, domain expertise, verification needs) and configures chain-of-thought prompting, lower temperature, and verification steps when reasoning score exceeds 0.7 threshold.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'task'         => array(
					'type'        => 'string',
					'description' => __( 'Complex task description that may benefit from enhanced reasoning.', 'mcp-ai-wpoos' ),
				),
				'context'      => array(
					'type'        => 'object',
					'description' => __( 'Optional task context including task_type, multi_step flag, and other relevant metadata.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'task_type'  => array(
							'type'        => 'string',
							'description' => __( 'Task type: code_generation, research, analysis, etc.', 'mcp-ai-wpoos' ),
						),
						'multi_step' => array(
							'type'        => 'boolean',
							'description' => __( 'Explicitly mark task as multi-step.', 'mcp-ai-wpoos' ),
						),
					),
				),
				'model_config' => array(
					'type'        => 'object',
					'description' => __( 'Current model configuration to enhance. Will be merged with reasoning enhancements.', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'task' ),
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
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'experimentation' ),

			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'          => true,
			'modifies-wp'   => false,
			'deterministic' => false, // Can vary based on historical data.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['task'] ) ) {
			return new WP_Error(
				'missing_parameter',
				__( 'Task parameter is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$task         = sanitize_textarea_field( $arguments['task'] );
		$task_context = $arguments['context'] ?? array();
		$model_config = $arguments['model_config'] ?? array();

		// Get reasoning controller instance.
		if ( ! class_exists( 'WP_MCP_AI_Reasoning_Controller' ) ) {
			return new WP_Error(
				'service_unavailable',
				__( 'Reasoning Controller service is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$reasoning_controller = new WP_MCP_AI_Reasoning_Controller();

		// Check if enhanced reasoning is recommended.
		$requires_reasoning = $reasoning_controller->requires_enhanced_reasoning( $task, $task_context );

		// Activate reasoning mode if recommended.
		$result = array(
			'reasoning_recommended' => $requires_reasoning,
			'task'                  => $task,
		);

		if ( $requires_reasoning ) {
			$task_info = array(
				'type'       => $task_context['task_type'] ?? 'general',
				'complexity' => 'high',
			);

			$enhanced_config = $reasoning_controller->activate_reasoning_mode( $model_config, $task_info );

			$result['reasoning_activated'] = true;
			$result['enhanced_config']     = $enhanced_config;
			$result['enhancements']        = array(
				'chain_of_thought' => true,
				'temperature'      => $enhanced_config['temperature'] ?? 0.3,
				'verification'     => $enhanced_config['verify_steps'] ?? true,
			);

			$message = sprintf(
				/* translators: %s: task type */
				__( 'Enhanced reasoning mode activated for %s task. Configuration includes chain-of-thought prompting, adjusted temperature (0.3), and verification steps.', 'mcp-ai-wpoos' ),
				$task_info['type']
			);
		} else {
			$result['reasoning_activated'] = false;
			$result['enhanced_config']     = $model_config;

			$message = __( 'Task complexity does not require enhanced reasoning mode. Standard configuration will be used.', 'mcp-ai-wpoos' );
		}

		$result['message'] = $message;

		return $this->success( $result, $message );
	}
}
