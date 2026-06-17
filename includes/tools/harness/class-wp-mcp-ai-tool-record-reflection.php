<?php
/**
 * Tool: record_reflection — Layer E reflexion-style memory write.
 *
 * Persists a verbal reflection ("Next time, check Y before answering")
 * into agent memory after PII / secret scrubbing. Reflections are tagged
 * by task class so they don't pollute unrelated future tasks.
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
 * Persist a verbal reflection into agent memory.
 */
class WP_MCP_AI_Tool_Record_Reflection implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'record_reflection';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Record Reflection', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Persist a verbal reflection (Reflexion-style) into agent memory after PII / secret scrubbing. Use after a self-refine cycle to capture what to do differently next time. Tagged by task class so reflections do not pollute unrelated tasks.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'reflection' => array(
					'type'        => 'string',
					'description' => 'The reflection text. Will be scrubbed for PII / secrets before persistence.',
				),
				'task_class' => array(
					'type'        => 'string',
					'description' => 'Task class to scope the reflection to (e.g. "math", "code", "qa"). Defaults to "general".',
				),
			),
			'required'   => array( 'reflection' ),
		);
	}

	public function get_required_capability() {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_record_reflection_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$reflection = isset( $arguments['reflection'] ) ? (string) $arguments['reflection'] : '';
		if ( '' === trim( $reflection ) ) {
			return new WP_Error( 'wp_mcp_ai_record_reflection_empty', __( 'Reflection text is required.', 'mcp-ai-wpoos' ) );
		}

		$task_class = isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : 'general';
		if ( '' === $task_class ) {
			$task_class = 'general';
		}

		$result = WP_MCP_AI_Self_Refine_Loop::record_reflection( $reflection, $task_class, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'write', 'state-changing', 'requires-capability' );
	}
}
