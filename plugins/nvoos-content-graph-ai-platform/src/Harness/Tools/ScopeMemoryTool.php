<?php
/**
 * Scope Memory tool (D8 Cluster 3 port of the base plugin's
 * WP_MCP_AI_Tool_Scope_Memory — byte-identical slug, schema, reserved
 * buckets, and envelope; serves the platform-ported HarnessProfile).
 *
 * @package NvoosContentGraphAiPlatform\Harness\Tools
 * @since   2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness\Tools;

use NvoosContentGraphAi\Tools\AbstractAiTool;
use NvoosContentGraphAiPlatform\Harness\HarnessProfile;

/**
 * Compute the task-class memory scope for an assistant.
 */
class ScopeMemoryTool extends AbstractAiTool {

	/**
	 * Reserved task-class buckets recognised by the harness (base-identical).
	 *
	 * @var array<int,string>
	 */
	private const RESERVED_BUCKETS = array(
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

	public function getSlug(): string {
		return 'scope_memory';
	}

	public function getName(): string {
		return __( 'Scope Memory', 'nvoos-content-graph-ai-platform' );
	}

	public function getDescription(): string {
		return __( 'Compute the memory scope tags for an assistant and task class. Returns the canonical task_class bucket plus the tag set callers should attach to memory writes (e.g. reflections) so reads can filter accurately.', 'nvoos-content-graph-ai-platform' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'assistant_id' => array(
					'type'        => 'integer',
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

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'idempotent' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$assistant_id = isset( $arguments['assistant_id'] ) ? (int) $arguments['assistant_id'] : 0;
		$task_class   = isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : 'general';
		if ( '' === $task_class ) {
			$task_class = 'general';
		}
		$wing = isset( $arguments['wing'] ) ? sanitize_text_field( (string) $arguments['wing'] ) : '';

		$reserved = in_array( $task_class, self::RESERVED_BUCKETS, true );
		$tags     = array( 'task_class:' . $task_class );
		if ( $assistant_id > 0 ) {
			$tags[] = 'assistant:' . $assistant_id;
		}
		if ( '' !== $wing ) {
			$tags[] = 'wing:' . $wing;
		}

		$profile = HarnessProfile::get( $assistant_id );

		return array(
			'success'    => true,
			'task_class' => $task_class,
			'reserved'   => $reserved,
			'tags'       => $tags,
			'pii_filter' => ! empty( $profile['memory']['pii_filter'] ),
		);
	}
}
