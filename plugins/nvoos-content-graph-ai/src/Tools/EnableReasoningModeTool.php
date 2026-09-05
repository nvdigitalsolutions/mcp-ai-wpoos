<?php
/**
 * Enable Reasoning Mode tool (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_Enable_Reasoning_Mode — byte-identical slug, schema,
 * error codes, and envelope; per-mode service seam).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\Services\ReasoningController;

/**
 * Activates enhanced reasoning configuration for complex multi-step tasks.
 */
class EnableReasoningModeTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'enable_reasoning_mode';
	}

	public function getName(): string {
		return __( 'Enable Reasoning Mode', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Activates enhanced reasoning mode for complex multi-step tasks. Analyzes task complexity across 5 indicators (multi-step, logical complexity, code generation, domain expertise, verification needs) and configures chain-of-thought prompting, lower temperature, and verification steps when reasoning score exceeds 0.7 threshold.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'task'         => array(
					'type'        => 'string',
					'description' => __( 'Complex task description that may benefit from enhanced reasoning.', 'nvoos-content-graph-ai' ),
				),
				'context'      => array(
					'type'        => 'object',
					'description' => __( 'Optional task context including task_type, multi_step flag, and other relevant metadata.', 'nvoos-content-graph-ai' ),
					'properties'  => array(
						'task_type'  => array(
							'type'        => 'string',
							'description' => __( 'Task type: code_generation, research, analysis, etc.', 'nvoos-content-graph-ai' ),
						),
						'multi_step' => array(
							'type'        => 'boolean',
							'description' => __( 'Explicitly mark task as multi-step.', 'nvoos-content-graph-ai' ),
						),
					),
				),
				'model_config' => array(
					'type'        => 'object',
					'description' => __( 'Current model configuration to enhance. Will be merged with reasoning enhancements.', 'nvoos-content-graph-ai' ),
				),
			),
			'required'   => array( 'task' ),
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'non-deterministic' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		// Validate required parameters.
		if ( empty( $arguments['task'] ) ) {
			return new \WP_Error(
				'missing_parameter',
				__( 'Task parameter is required.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		$task         = sanitize_textarea_field( $arguments['task'] );
		$task_context = $arguments['context'] ?? array();
		$model_config = $arguments['model_config'] ?? array();

		// Per-mode service seam: the ported controller standalone, the
		// base class in monolith installs.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Reasoning_Controller' ) ) {
			$reasoning_controller = new \WP_MCP_AI_Reasoning_Controller();
		} else {
			$reasoning_controller = new ReasoningController();
		}

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
				__( 'Enhanced reasoning mode activated for %s task. Configuration includes chain-of-thought prompting, adjusted temperature (0.3), and verification steps.', 'nvoos-content-graph-ai' ),
				$task_info['type']
			);
		} else {
			$result['reasoning_activated'] = false;
			$result['enhanced_config']     = $model_config;

			$message = __( 'Task complexity does not require enhanced reasoning mode. Standard configuration will be used.', 'nvoos-content-graph-ai' );
		}

		$result['message'] = $message;

		return $this->format_chat_response( $result, $message );
	}

	/**
	 * Compose the chat-response envelope (byte-identical to the base
	 * plugin's WP_MCP_AI_Tool_Chat_Response::format_chat_response for
	 * structured results: message at the top level, data nested).
	 *
	 * @param array  $data    Structured tool result.
	 * @param string $message Display message.
	 * @return array
	 */
	private function format_chat_response( array $data, string $message ): array {
		return array(
			'message' => trim( $message ),
			'data'    => $data,
		);
	}
}
