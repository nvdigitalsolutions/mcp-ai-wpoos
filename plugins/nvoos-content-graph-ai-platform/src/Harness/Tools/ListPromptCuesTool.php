<?php
/**
 * List Prompt Cues tool (D8 Cluster 3 port of the base plugin's
 * WP_MCP_AI_Tool_List_Prompt_Cues — byte-identical slug, schema, and
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
 * List registered prompt cues, optionally filtered by task class.
 */
class ListPromptCuesTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'list_prompt_cues';
	}

	public function getName(): string {
		return __( 'List Prompt Cues', 'nvoos-content-graph-ai-platform' );
	}

	public function getDescription(): string {
		return __( 'List registered prompt cues from the LLM-harness Prompt Cue Library, optionally filtered by task class. Returns slug, label, description, version, citation, and applicable task classes for each cue.', 'nvoos-content-graph-ai-platform' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'task_class' => array(
					'type'        => 'string',
					'description' => 'Optional: restrict to cues that declare this task class (e.g. "math", "code", "qa", "rag", "research", "agentic", "general").',
				),
			),
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'idempotent', 'cacheable' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$task_class = isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : '';
		$cues       = PromptCueLibrary::get_instance()->list_cues( $task_class );
		return array(
			'success' => true,
			'count'   => count( $cues ),
			'cues'    => $cues,
		);
	}
}
