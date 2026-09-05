<?php
/**
 * Apply Prompt Cue tool (D8 Cluster 3 port of the base plugin's
 * WP_MCP_AI_Tool_Apply_Prompt_Cue — byte-identical slug, schema, error
 * codes, and envelope; serves the platform-ported PromptCueLibrary).
 *
 * @package NvoosContentGraphAiPlatform\Harness\Tools
 * @since   2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness\Tools;

use NvoosContentGraphAi\Tools\AbstractAiTool;
use NvoosContentGraphAiPlatform\Harness\PromptCueLibrary;

/**
 * Prepend one or more prompt cues to a system prompt.
 */
class ApplyPromptCueTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'apply_prompt_cue';
	}

	public function getName(): string {
		return __( 'Apply Prompt Cue', 'nvoos-content-graph-ai-platform' );
	}

	public function getDescription(): string {
		return __( 'Prepend one or more prompt cues to a system prompt. Cues augment, never replace, the existing prompt. Returns the augmented prompt and the list of cues that were applied.', 'nvoos-content-graph-ai-platform' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'system_prompt' => array(
					'type'        => 'string',
					'description' => 'Existing assistant system prompt. May be empty.',
				),
				'cue_slugs'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'One or more cue slugs to prepend in order.',
				),
			),
			'required'   => array( 'cue_slugs' ),
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'idempotent' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$system_prompt = isset( $arguments['system_prompt'] ) ? (string) $arguments['system_prompt'] : '';
		$cue_slugs     = array();
		if ( isset( $arguments['cue_slugs'] ) && is_array( $arguments['cue_slugs'] ) ) {
			foreach ( $arguments['cue_slugs'] as $slug ) {
				$key = sanitize_key( (string) $slug );
				if ( '' !== $key ) {
					$cue_slugs[] = $key;
				}
			}
		}

		if ( empty( $cue_slugs ) ) {
			return new \WP_Error( 'wp_mcp_ai_apply_prompt_cue_missing_slugs', __( 'At least one cue_slugs entry is required.', 'nvoos-content-graph-ai-platform' ) );
		}

		$library   = PromptCueLibrary::get_instance();
		$augmented = $library->apply( $system_prompt, $cue_slugs );
		$applied   = array();
		$skipped   = array();
		foreach ( $cue_slugs as $slug ) {
			if ( null !== $library->get( $slug ) ) {
				$applied[] = $slug;
			} else {
				$skipped[] = $slug;
			}
		}

		return array(
			'success'       => true,
			'system_prompt' => $augmented,
			'applied_cues'  => $applied,
			'skipped_cues'  => $skipped,
		);
	}
}
