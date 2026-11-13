<?php
/**
 * List Prompt Cues tool — the Layer A catalogue.
 *
 * Ported from `WP_MCP_AI_Tool_List_Prompt_Cues` under Proposal 090-D cluster 1.
 * Returns the registered prompt cues, optionally restricted to a task class.
 *
 * The catalogue itself lives in `Nvoos\Core\Domain\Service\Harness\PromptCueLibrary`,
 * which seeds the same eight default cues the WordPress library does. A caller
 * that registers extra cues passes its own library instance.
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
use Nvoos\Core\Domain\Service\Harness\PromptCueLibrary;
use Nvoos\Core\Support\Text;

class ListPromptCuesTool extends AbstractTool {

	/**
	 * Library to read. A per-instance default keeps the tool self-contained.
	 */
	private readonly PromptCueLibrary $library;

	public function __construct(
		ErrorFactoryInterface $errors,
		?PromptCueLibrary $library = null,
	) {
		parent::__construct( $errors );

		$this->library = $library ?? new PromptCueLibrary();
	}

	public function getSlug(): string {
		return 'list_prompt_cues';
	}

	public function getName(): string {
		return 'List Prompt Cues';
	}

	public function getDescription(): string {
		return 'List the registered prompt cues, optionally restricted to a task class. Each cue carries a slug, label, description, template, version, task classes and citation.';
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

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		unset( $context );

		$task_class = Text::sanitizeKey( $this->scalarString( $arguments, 'task_class' ) );
		$cues       = $this->library->listCues( $task_class );

		return $this->success(
			'Prompt cues listed.',
			array(
				'count' => count( $cues ),
				'cues'  => $cues,
			)
		);
	}

	/**
	 * Read a string argument with the WordPress tool's `(string)` cast.
	 *
	 * `stringParam()` deliberately rejects non-strings, but the original casts
	 * any scalar. Non-scalars fall back to the empty string.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string               $key       Argument name.
	 */
	private function scalarString( array $arguments, string $key ): string {
		$value = $arguments[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}
}
