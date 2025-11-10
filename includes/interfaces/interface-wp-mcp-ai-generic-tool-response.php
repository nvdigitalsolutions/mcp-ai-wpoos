<?php
/**
 * Generic Tool Response Interface.
 *
 * Defines the contract for standardized tool/AI provider responses.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for standardized AI provider responses.
 *
 * This interface provides a uniform way to access response data from different
 * AI providers (OpenAI, Gemini, Anthropic, Ollama, etc.), abstracting away the
 * provider-specific response formats.
 */
interface WP_MCP_AI_Generic_Tool_Response {

	/**
	 * Get the main text content from the response.
	 *
	 * @return string|null The response content, or null if not available.
	 */
	public function get_content();

	/**
	 * Get error information if the response represents an error.
	 *
	 * @return array|null Array with 'code' and 'message' keys, or null if no error.
	 */
	public function get_error();

	/**
	 * Get tool calls from the response.
	 *
	 * @return array|null Array of tool call objects, or null if none present.
	 */
	public function get_tool_calls();

	/**
	 * Get token usage information.
	 *
	 * @return array|null Array with 'prompt_tokens', 'completion_tokens', 'total_tokens', or null.
	 */
	public function get_usage();

	/**
	 * Get the finish reason for the response.
	 *
	 * @return string|null Reason such as 'stop', 'length', 'tool_calls', or null.
	 */
	public function get_finish_reason();

	/**
	 * Get the original raw response from the provider.
	 *
	 * @return array The raw, unprocessed response for debugging or advanced use.
	 */
	public function get_original_response();

	/**
	 * Check if the response represents a successful completion.
	 *
	 * @return bool True if successful, false if error occurred.
	 */
	public function is_success();

	/**
	 * Get the provider identifier.
	 *
	 * @return string Provider name (e.g., 'openai', 'gemini', 'anthropic', 'ollama').
	 */
	public function get_provider();

	/**
	 * Get the model used for the response.
	 *
	 * @return string|null Model identifier, or null if not available.
	 */
	public function get_model();
}
