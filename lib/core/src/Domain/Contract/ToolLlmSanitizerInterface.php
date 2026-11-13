<?php
/**
 * Tool LLM sanitizer interface.
 *
 * Ported from the existing WP_MCP_AI_Tool_Interface with minor adaptations
 * for framework agnosticism:
 *  - Replaces WP_Error with ErrorFactoryInterface (via convention)
 *  - Replaces string capabilities with capability contract
 *  - Adds optional sub-interfaces for capability flags, rules, data contracts,
 *    flow stages, and LLM sanitization.
 *
 * Type aliases (`ToolArgs`, `ToolContext`, `ToolParametersSchema`) are
 * declared once in lib/core/phpstan.neon so every contract can name its
 * untyped arrays without repeating the shape. `...<array-key, mixed>` means
 * "known keys documented, extra keys tolerated" while tools migrate.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Optional interface for tools that sanitize results before sending to the LLM.
 *
 * The LLM sees a stripped version of tool results (no base64 blobs,
 * no raw HTTP responses). The chat client receives the full result.
 */
interface ToolLlmSanitizerInterface {

	/**
	 * Sanitize a tool result for LLM consumption.
	 *
	 * Strips verbose metadata, base64 content, duplicate raw API responses,
	 * and truncates to a reasonable size.
	 *
	 * @param mixed $result  The raw tool execution result.
	 * @return string        Sanitized text suitable for the LLM context window.
	 */
	public function sanitizeForLlm( mixed $result ): string;
}
