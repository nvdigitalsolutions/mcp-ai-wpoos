<?php
/**
 * Select Prompt Cue tool (D8 Cluster 3 port of the base plugin's
 * WP_MCP_AI_Tool_Select_Prompt_Cue — byte-identical slug, schema, and
 * envelope; serves the platform-ported PromptCueLibrary).
 *
 * @package NvoosContentGraphAiPlatform\Harness\Tools
 * @since   2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness\Tools;

use NvoosContentGraphAi\Tools\AbstractAiTool;
use NvoosContentGraphAiPlatform\Harness\PromptCueLibrary;

/**
 * Select a prompt cue for a task.
 */
class SelectPromptCueTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'select_prompt_cue';
	}

	public function getName(): string {
		return __( 'Select Prompt Cue', 'nvoos-content-graph-ai-platform' );
	}

	public function getDescription(): string {
		return __( 'Pick the best prompt cue for a task class. Returns the cue (slug, label, template, citation) so the caller can prepend it to the system prompt.', 'nvoos-content-graph-ai-platform' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'task_class'   => array(
					'type'        => 'string',
					'description' => 'Task class. Examples: "math", "code", "qa", "rag", "research", "agentic", "general".',
				),
				'assistant_id' => array(
					'type'        => 'integer',
					'description' => 'Assistant post ID, if any. Default 0.',
				),
				'model'        => array(
					'type'        => 'string',
					'description' => 'Optional model identifier the cue should suit.',
				),
			),
			'required'   => array( 'task_class' ),
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'idempotent' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$task_class = isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : 'general';
		if ( '' === $task_class ) {
			$task_class = 'general';
		}

		$assistant_id = isset( $arguments['assistant_id'] ) ? (int) $arguments['assistant_id'] : 0;
		$model        = isset( $arguments['model'] ) ? sanitize_text_field( (string) $arguments['model'] ) : '';

		$cue = PromptCueLibrary::get_instance()->select( $task_class, $assistant_id, $model );

		if ( null === $cue ) {
			return array(
				'success' => true,
				'cue'     => null,
				'message' => __( 'No cue applies to this task class.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		return array(
			'success' => true,
			'cue'     => $cue,
		);
	}
}
