<?php
/**
 * Optional interface for tools that need custom LLM sanitization rules.
 *
 * @package WP_MCP_AI
 */

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
