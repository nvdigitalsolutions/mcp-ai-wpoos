<?php
/**
 * Record Reflection tool (D8 Cluster 3 port of the base plugin's
 * WP_MCP_AI_Tool_Record_Reflection — byte-identical slug, schema, error
 * codes, and envelope; serves the platform-ported SelfRefineLoop).
 *
 * @package NvoosContentGraphAiPlatform\Harness\Tools
 * @since   2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness\Tools;

use NvoosContentGraphAi\Tools\AbstractAiTool;
use NvoosContentGraphAiPlatform\Harness\SelfRefineLoop;

/**
 * Persist a verbal reflection into agent memory.
 */
class RecordReflectionTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'record_reflection';
	}

	public function getName(): string {
		return __( 'Record Reflection', 'nvoos-content-graph-ai-platform' );
	}

	public function getDescription(): string {
		return __( 'Persist a verbal reflection (Reflexion-style) into agent memory after PII / secret scrubbing. Use after a self-refine cycle to capture what to do differently next time. Tagged by task class so reflections do not pollute unrelated tasks.', 'nvoos-content-graph-ai-platform' );
	}

	public function getParametersSchema(): array {
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

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'write', 'state-changing', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'wp_mcp_ai_record_reflection_forbidden', __( 'Permission denied.', 'nvoos-content-graph-ai-platform' ) );
		}

		$reflection = isset( $arguments['reflection'] ) ? (string) $arguments['reflection'] : '';
		if ( '' === trim( $reflection ) ) {
			return new \WP_Error( 'wp_mcp_ai_record_reflection_empty', __( 'Reflection text is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$task_class = isset( $arguments['task_class'] ) ? sanitize_key( (string) $arguments['task_class'] ) : 'general';
		if ( '' === $task_class ) {
			$task_class = 'general';
		}

		$result = SelfRefineLoop::record_reflection( $reflection, $task_class, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}
}
