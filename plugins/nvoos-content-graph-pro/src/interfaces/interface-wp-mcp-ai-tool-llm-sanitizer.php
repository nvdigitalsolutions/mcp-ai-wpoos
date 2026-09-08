<?php
/**
 * LLM sanitizer interface (ecosystem port - Wave F2, video-services D8-compat slice).
 *
 * Ported from the base plugin's `includes/` directory for the standalone `nvoos-content-graph-pro`
 * addon (D8-compat copy - same pattern as the tool-interface/chat-response/envelope copies). Kept
 * byte-identical. The base plugin owns the symbol in monolith installs - the addon boots nothing when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned `WP_MCP_AI_PATH` message-attachments/openai-client requires gain `defined(
 * 'WP_MCP_AI_PATH' )` guards (graceful standalone degrade until those base files land as D8 copies).
 *
 * Optional interface for tools that need custom LLM sanitization rules.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for tools to define custom sanitization for LLM context.
 *
 * Tools that return large or complex data structures can implement this interface
 * to specify which fields should be stripped before passing results to the LLM
 * in agentic workflow loops. This keeps each tool's sanitization logic
 * self-contained and maintainable.
 *
 * The full, unsanitized result is always preserved in tool_results[] for
 * frontend display.
 */
interface WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
	/**
	 * Sanitize tool result before passing to LLM.
	 *
	 * This method receives the raw tool execution result and should return
	 * a cleaned version suitable for LLM consumption. The goal is to remove:
	 * - Large binary/encoded data (base64 images, data URLs)
	 * - Duplicate data (raw API responses that mirror processed results)
	 * - Verbose metadata (HTTP headers, timestamps)
	 * - Any other tool-specific fields that bloat context without adding value
	 *
	 * Keep fields that the LLM needs to:
	 * - Understand what happened (status, success/error messages)
	 * - Reference results (IDs, URLs, permalinks)
	 * - Work with returned data (actual content if needed for reasoning)
	 *
	 * @param mixed $result Raw tool execution result.
	 * @return mixed Sanitized result safe for LLM context.
	 */
	public function sanitize_for_llm( $result );
}
