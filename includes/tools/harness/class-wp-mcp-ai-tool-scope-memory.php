<?php
/**
 * Tool: scope_memory — Layer F task-class scoping primitive.
 *
 * Returns memory-scoping metadata for a given assistant + task class. The
 * actual scoping is enforced by callers (typically the harness self-refine
 * loop and the retrieval harness) by attaching the returned tags to writes
 * and filtering them on reads.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compute the task-class memory scope for an assistant.
 */
class WP_MCP_AI_Tool_Scope_Memory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Reserved task-class buckets recognised by the harness. Other values
	 * are accepted but flagged in the response so callers can decide
	 * whether to fall back to "general".
	 *
	 * @var array<int,string>
	 */
	const RESERVED_BUCKETS = array(
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
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'scope_memory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Scope Memory', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compute the memory scope tags for an assistant and task class. Returns the canonical task_class bucket plus the tag set callers should attach to memory writes (e.g. reflections) so reads can filter accurately.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
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

	public function get_required_capability() {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ) {
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

		$profile = WP_MCP_AI_Harness_Profile::get( $assistant_id );

		return array(
			'success'    => true,
			'task_class' => $task_class,
			'reserved'   => $reserved,
			'tags'       => $tags,
			'pii_filter' => ! empty( $profile['memory']['pii_filter'] ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent' );
	}
}
