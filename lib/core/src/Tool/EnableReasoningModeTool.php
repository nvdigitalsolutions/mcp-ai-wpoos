<?php
/**
 * Enable Reasoning Mode tool — complexity scoring and config enhancement.
 *
 * Ported from `WP_MCP_AI_Tool_Enable_Reasoning_Mode` under Proposal 090-D
 * cluster 1. Scores a task across five indicators and, above the threshold,
 * returns a model configuration enhanced with chain-of-thought prompting, a
 * lowered temperature and a verification flag.
 *
 * The scoring lives in `Nvoos\Core\Domain\Service\Reasoning\ReasoningController`;
 * this tool is the argument plumbing and the response shape.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Service\Reasoning\ReasoningController;
use Nvoos\Core\Support\Text;

class EnableReasoningModeTool extends AbstractTool {

	private readonly ReasoningController $reasoning;

	public function __construct(
		ErrorFactoryInterface $errors,
		?ReasoningController $reasoning = null,
	) {
		parent::__construct( $errors );

		$this->reasoning = $reasoning ?? new ReasoningController();
	}

	public function getSlug(): string {
		return 'enable_reasoning_mode';
	}

	public function getName(): string {
		return 'Enable Reasoning Mode';
	}

	public function getDescription(): string {
		return 'Activates enhanced reasoning mode for complex multi-step tasks. Analyzes task complexity across 5 indicators (multi-step, logical complexity, code generation, domain expertise, verification needs) and configures chain-of-thought prompting, lower temperature, and verification steps when reasoning score exceeds 0.7 threshold.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'task'         => array(
					'type'        => 'string',
					'description' => 'Complex task description that may benefit from enhanced reasoning.',
				),
				'context'      => array(
					'type'        => 'object',
					'description' => 'Optional task context including task_type, multi_step flag, and other relevant metadata.',
					'properties'  => array(
						'task_type'  => array(
							'type'        => 'string',
							'description' => 'Task type: code_generation, research, analysis, etc.',
						),
						'multi_step' => array(
							'type'        => 'boolean',
							'description' => 'Explicitly mark task as multi-step.',
						),
					),
				),
				'model_config' => array(
					'type'        => 'object',
					'description' => 'Current model configuration to enhance. Will be merged with reasoning enhancements.',
				),
			),
			'required'   => array( 'task' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		unset( $context );

		$task = Text::multiline( $this->scalarString( $arguments, 'task' ) );

		if ( '' === $task ) {
			return $this->errors->validationFailed(
				'Task parameter is required.',
				array( 'task' => array( 'Provide the task description to score.' ) )
			);
		}

		$task_context = $this->objectParam( $arguments, 'context' );
		$model_config = $this->objectParam( $arguments, 'model_config' );

		$requires_reasoning = $this->reasoning->requiresEnhancedReasoning( $task, $task_context );

		$result = array(
			'reasoning_recommended' => $requires_reasoning,
			'task'                  => $task,
		);

		if ( $requires_reasoning ) {
			$task_info = array(
				'type'       => $task_context['task_type'] ?? 'general',
				'complexity' => 'high',
			);

			$enhanced_config = $this->reasoning->activateReasoningMode( $model_config, $task_info );

			$result['reasoning_activated'] = true;
			$result['enhanced_config']     = $enhanced_config;
			$result['enhancements']        = array(
				'chain_of_thought' => true,
				'temperature'      => $enhanced_config['temperature'] ?? 0.3,
				'verification'     => $enhanced_config['verify_steps'] ?? true,
			);

			$message = sprintf(
				/* translators: %s: task type */
				'Enhanced reasoning mode activated for %s task. Configuration includes chain-of-thought prompting, adjusted temperature (0.3), and verification steps.',
				(string) $task_info['type']
			);
		} else {
			$result['reasoning_activated'] = false;
			$result['enhanced_config']     = $model_config;

			$message = 'Task complexity does not require enhanced reasoning mode. Standard configuration will be used.';
		}

		$result['message'] = $message;

		return $this->success( $message, $result );
	}

	/**
	 * Read a string argument with the WordPress tool's `(string)` cast.
	 *
	 * `stringParam()` rejects non-strings; the original cast any scalar.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string               $key       Argument name.
	 */
	private function scalarString( array $arguments, string $key ): string {
		$value = $arguments[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}
}
