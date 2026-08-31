<?php
/**
 * Harness Prompt Injector — Layer A integration into the chat client.
 *
 * The chat client surfaces a system prompt at three points:
 *
 *   1. Server-side chat path in `class-wp-mcp-ai-rest.php` (after the
 *      professional_prompt merge).
 *   2. The embedded-config endpoint in
 *      `class-wp-mcp-ai-rest-chat-controller.php` that the WebLLM client
 *      calls to refresh its prompt at runtime.
 *   3. The shortcode bootstrap in `class-wp-mcp-ai-shortcode.php` that
 *      pre-localises the prompt for the page render.
 *
 * Each of those points applies the `wp_mcp_ai_resolved_system_prompt`
 * filter. This injector is the single subscriber that augments the prompt
 * with harness cues drawn from the assistant's harness profile. When the
 * profile is disabled (the default), the original prompt is returned
 * unchanged.
 *
 * Cues are *prepended* to the prompt — they augment rather than replace
 * the existing assistant configuration, matching the contract advertised
 * by the Prompt Cue Library.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply harness profile cues to the chat-client system prompt.
 */
class HarnessPromptInjector {

	/**
	 * Hook the injector to the resolved system prompt filter.
	 */
	public static function register() {
		add_filter( 'wp_mcp_ai_resolved_system_prompt', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/**
	 * Apply harness profile cues to a system prompt.
	 *
	 * @param string $system_prompt The current system prompt.
	 * @param int    $assistant_id  The assistant post ID (0 for global).
	 * @param array  $context       Optional. Surface-specific context.
	 *                              Reserved keys: 'task_class', 'model'.
	 * @return string Augmented prompt, or the original on no-op.
	 */
	public static function filter( $system_prompt, $assistant_id = 0, $context = array() ) {
		$system_prompt = (string) $system_prompt;
		$assistant_id  = (int) $assistant_id;

		if ( ! class_exists( __NAMESPACE__ . '\\HarnessProfile' ) ) {
			return $system_prompt;
		}

		$profile = HarnessProfile::get( $assistant_id );

		// Off by default: the harness must be explicitly enabled, and the
		// profile must list at least one cue.
		if ( empty( $profile['enabled'] ) || empty( $profile['cues'] ) || ! is_array( $profile['cues'] ) ) {
			return $system_prompt;
		}

		if ( ! class_exists( __NAMESPACE__ . '\\PromptCueLibrary' ) ) {
			return $system_prompt;
		}

		$cue_slugs = array();
		foreach ( $profile['cues'] as $slug ) {
			$key = sanitize_key( (string) $slug );
			if ( '' !== $key ) {
				$cue_slugs[] = $key;
			}
		}

		if ( empty( $cue_slugs ) ) {
			return $system_prompt;
		}

		/**
		 * Filter the cue slugs the injector is about to apply.
		 *
		 * Allows late-stage substitution (e.g. selection by task class)
		 * without mutating the stored harness profile.
		 *
		 * @param array  $cue_slugs    Cue slugs to apply, in order.
		 * @param int    $assistant_id Assistant post ID.
		 * @param array  $context      Surface-specific context.
		 * @param array  $profile      The resolved harness profile.
		 */
		$cue_slugs = (array) apply_filters( 'wp_mcp_ai_harness_inject_cue_slugs', $cue_slugs, $assistant_id, $context, $profile );

		if ( empty( $cue_slugs ) ) {
			return $system_prompt;
		}

		$library   = PromptCueLibrary::get_instance();
		$augmented = $library->apply( $system_prompt, $cue_slugs );

		return is_string( $augmented ) ? $augmented : $system_prompt;
	}
}
