<?php
/**
 * Optional interface for tools that need custom chat-client sanitization rules.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for tools to define custom sanitization for chat-client display.
 *
 * Tools that return large or complex data structures can implement this interface
 * to specify which fields should be sanitized before passing results to the
 * chat-client. The chat-client adds these results to the conversation array,
 * which is sent back to the API in subsequent requests, so they must comply
 * with provider message schemas (e.g., OpenAI, Gemini).
 *
 * This is separate from sanitize_for_llm() because:
 * - LLM sanitization focuses on token reduction and removing duplicate data
 * - Chat sanitization focuses on schema compliance and keeping display-friendly data
 */
interface WP_MCP_AI_Tool_Chat_Sanitizer_Interface {
	/**
	 * Sanitize tool result before passing to chat-client.
	 *
	 * This method receives the raw tool execution result and should return
	 * a cleaned version suitable for chat-client consumption. The goal is to:
	 * - Remove large binary/encoded data that violates provider schemas
	 * - Strip fields that aren't needed for display or message context
	 * - Ensure compliance with OpenAI/Gemini message parameter requirements
	 * - Keep data that the chat UI needs to display results (URLs, IDs, text)
	 *
	 * The chat-client will add this sanitized result to the conversation array,
	 * which is sent back to the API in the next request, so it must not contain
	 * parameters that would cause "Invalid parameter(s)" errors.
	 *
	 * Keep fields that:
	 * - Are needed for UI display (URLs, attachment IDs, file names)
	 * - Comply with provider message schemas (role, content, name, tool_call_id)
	 * - Provide context for the conversation (status messages, descriptions)
	 *
	 * Remove:
	 * - Large base64 image data (use URLs instead)
	 * - Raw API responses
	 * - Extra fields not in the provider's message schema
	 *
	 * @param mixed $result Raw tool execution result.
	 * @return mixed Sanitized result safe for chat-client and API resubmission.
	 */
	public function sanitize_for_chat( $result );
}
