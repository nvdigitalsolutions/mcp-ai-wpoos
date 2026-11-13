<?php
/**
 * Scope Memory tool — task-class memory scoping tags.
 *
 * Ported from `WP_MCP_AI_Tool_Scope_Memory` under Proposal 090-D cluster 1.
 * Given an assistant and a task class, returns the canonical bucket plus the
 * tag set callers attach to memory writes (reflections) and filter on reads.
 *
 * The scoping itself is the caller's job — this tool only computes the tags.
 *
 * ## The one store read
 *
 * `pii_filter` comes from the assistant's harness profile. Runtimes without
 * harness storage supply no `HarnessProfileProviderInterface` and get the
 * documented default (`true`); supply one and its resolved profile is
 * authoritative. Everything else is pure.
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
use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Support\Text;

class ScopeMemoryTool extends AbstractTool {

	/**
	 * Task-class buckets the harness recognises.
	 *
	 * Other values are accepted but reported as not reserved, so callers can
	 * decide whether to fall back to "general".
	 *
	 * @var array<int, string>
	 */
	public const RESERVED_BUCKETS = array(
		'general',
		'math',
		'code',
		'qa',
		'rag',
		'research',
		'reasoning',
		'agentic',
		'this-site',
		'this-user',
	);

	/**
	 * `memory.pii_filter` from `WP_MCP_AI_Harness_Profile::defaults()`.
	 *
	 * Used only when no provider is bound.
	 */
	private const DEFAULT_PII_FILTER = true;

	/**
	 * Optional profile provider. Null means this runtime has no harness storage.
	 */
	private readonly ?HarnessProfileProviderInterface $profiles;

	public function __construct(
		ErrorFactoryInterface $errors,
		?HarnessProfileProviderInterface $profiles = null,
	) {
		parent::__construct( $errors );

		$this->profiles = $profiles;
	}

	public function getSlug(): string {
		return 'scope_memory';
	}

	public function getName(): string {
		return 'Scope Memory';
	}

	public function getDescription(): string {
		return 'Compute the memory scope tags for an assistant and task class. Returns the canonical task_class bucket plus the tag set callers should attach to memory writes (e.g. reflections) so reads can filter accurately.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'assistant_id' => array(
					'type'        => 'integer',
					'maximum'     => 9007199254740991,
					'description' => 'Assistant post ID (0 = global).',
				),
				'task_class'   => array(
					'type'        => 'string',
					'description' => 'Task class. Reserved values: ' . implode( ', ', self::RESERVED_BUCKETS ),
				),
				'wing'         => array(
					'type'        => 'string',
					'description' => 'Optional MemPalace wing name to add as a scope tag.',
				),
			),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		unset( $context );

		$assistant_id = $this->intParam( $arguments, 'assistant_id' );
		$task_class   = Text::sanitizeKey( $this->scalarString( $arguments, 'task_class' ) );
		$wing         = Text::singleLine( $this->scalarString( $arguments, 'wing' ) );

		if ( '' === $task_class ) {
			$task_class = 'general';
		}

		$tags = array( 'task_class:' . $task_class );

		if ( $assistant_id > 0 ) {
			$tags[] = 'assistant:' . $assistant_id;
		}

		if ( '' !== $wing ) {
			$tags[] = 'wing:' . $wing;
		}

		return $this->success(
			'Memory scope computed.',
			array(
				'task_class' => $task_class,
				'reserved'   => in_array( $task_class, self::RESERVED_BUCKETS, true ),
				'tags'       => $tags,
				'pii_filter' => $this->piiFilter( $assistant_id ),
			)
		);
	}

	/**
	 * Resolve `memory.pii_filter` for an assistant.
	 *
	 * Mirrors the WordPress tool's `! empty( $profile['memory']['pii_filter'] )`
	 * read: a profile without the key reports the filter as off.
	 */
	private function piiFilter( int $assistantId ): bool {
		if ( null === $this->profiles ) {
			return self::DEFAULT_PII_FILTER;
		}

		$profile = $this->profiles->getProfile( $assistantId );

		return ! empty( $profile['memory']['pii_filter'] );
	}

	/**
	 * Read a string argument with the WordPress tool's `(string)` cast.
	 *
	 * `stringParam()` deliberately rejects non-strings, but the original casts
	 * any scalar, so `task_class => 123` was the bucket "123". Preserve that;
	 * non-scalars fall back to the empty string.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string               $key       Argument name.
	 */
	private function scalarString( array $arguments, string $key ): string {
		$value = $arguments[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}
}
