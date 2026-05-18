<?php
/**
 * Tool: list_prompt_cues — Layer A reflection helper.
 *
 * Returns the catalogue of prompt cues registered with the
 * WP_MCP_AI_Prompt_Cue_Library, optionally filtered by task class.
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
 * List registered prompt cues.
 */
class WP_MCP_AI_Tool_List_Prompt_Cues implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_prompt_cues';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Prompt Cues', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List registered prompt cues from the LLM-harness Prompt Cue Library, optionally filtered by task class. Returns slug, label, description, version, citation, and applicable task classes for each cue.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
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

	public function get_required_capability() {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$task_class = isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : '';
		$cues       = WP_MCP_AI_Prompt_Cue_Library::get_instance()->list_cues( $task_class );
		return array(
			'success' => true,
			'count'   => count( $cues ),
			'cues'    => $cues,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent', 'cacheable' );
	}
}
