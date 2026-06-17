<?php
/**
 * Tool: select_prompt_cue — Layer A selection helper.
 *
 * Selects the most appropriate prompt cue for a task class. The actual
 * selection logic is filterable via `wp_mcp_ai_select_prompt_cue` so addons
 * (e.g. Pro, with its learned router) can override the default choice.
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
 * Select a prompt cue for a task.
 */
class WP_MCP_AI_Tool_Select_Prompt_Cue implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'select_prompt_cue';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Select Prompt Cue', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Pick the best prompt cue for a task class. Returns the cue (slug, label, template, citation) so the caller can prepend it to the system prompt.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
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

	/**
	 * Get the required capability for this tool.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the select prompt cue tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$task_class = isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : 'general';
		if ( '' === $task_class ) {
			$task_class = 'general';
		}

		$assistant_id = isset( $arguments['assistant_id'] ) ? (int) $arguments['assistant_id'] : 0;
		$model        = isset( $arguments['model'] ) ? sanitize_text_field( (string) $arguments['model'] ) : '';

		$cue = WP_MCP_AI_Prompt_Cue_Library::get_instance()->select( $task_class, $assistant_id, $model );

		if ( null === $cue ) {
			return array(
				'success' => true,
				'cue'     => null,
				'message' => __( 'No cue applies to this task class.', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success' => true,
			'cue'     => $cue,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent' );
	}
}
