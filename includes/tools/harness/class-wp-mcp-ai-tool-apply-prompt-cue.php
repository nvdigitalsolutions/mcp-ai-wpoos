<?php
/**
 * Tool: apply_prompt_cue — Layer A application helper.
 *
 * Returns a system prompt with one or more cues prepended. The original
 * system prompt is preserved verbatim — cues *augment*, never replace.
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
 * Apply prompt cues to an existing system prompt.
 */
class WP_MCP_AI_Tool_Apply_Prompt_Cue implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'apply_prompt_cue';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Apply Prompt Cue', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Prepend one or more prompt cues to a system prompt. Cues augment, never replace, the existing prompt. Returns the augmented prompt and the list of cues that were applied.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
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

	public function get_required_capability() {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ) {
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
			return new WP_Error( 'wp_mcp_ai_apply_prompt_cue_missing_slugs', __( 'At least one cue_slugs entry is required.', 'mcp-ai-wpoos' ) );
		}

		$library   = WP_MCP_AI_Prompt_Cue_Library::get_instance();
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

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'local-only', 'idempotent' );
	}
}
