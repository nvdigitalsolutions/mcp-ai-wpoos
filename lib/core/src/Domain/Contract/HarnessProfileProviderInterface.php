<?php
/**
 * Harness profile provider contract.
 *
 * The LLM-harness layers (prompt cues, reasoning, tool routing, retrieval,
 * self-refine, memory scoping, guardrails) are configured per assistant by a
 * profile document. Where that document lives is a platform concern:
 *
 *  - WordPress: post meta `_wp_mcp_ai_harness_profile` on the assistant, with a
 *    `wp_mcp_ai_harness_profile_default` option as the site-wide fallback and a
 *    `wp_mcp_ai_harness_profile` filter for overrides — implemented by
 *    `WP_MCP_AI_Harness_Profile`.
 *  - Laravel:   no harness storage ships yet. A runtime that has none simply
 *    does not bind this contract, and consumers fall back to the documented
 *    defaults.
 *
 * Consumers read the resolved profile, never the storage. That keeps the
 * sanitisation and clamping rules in one place per runtime.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface HarnessProfileProviderInterface {

	/**
	 * Resolve the harness profile for an assistant.
	 *
	 * Implementations must return a complete, sanitised profile: every
	 * top-level key from the canonical defaults, with values already clamped to
	 * their documented ranges. A partial array is treated by consumers as
	 * "unset", which is how a profile with no `memory` block reports the PII
	 * filter as off.
	 *
	 * @param int $assistantId Assistant identifier. 0 means the global default.
	 *
	 * @return array<string, mixed> Resolved profile.
	 */
	public function getProfile( int $assistantId ): array;
}
