<?php
/**
 * Generic Tool Response Implementation.
 *
 * Concrete implementation of the generic tool response interface.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-generic-tool-response.php';

/**
 * Standard implementation of the generic tool response interface.
 *
 * This class wraps a normalized response and provides a consistent API
 * for accessing response data regardless of the AI provider.
 */
class WP_MCP_AI_Generic_Tool_Response_Impl implements WP_MCP_AI_Generic_Tool_Response {

	/**
	 * The normalized response data.
	 *
	 * @var array
	 */
	protected $normalized_response;

	/**
	 * The original raw response from the provider.
	 *
	 * @var array
	 */
	protected $original_response;

	/**
	 * Whether the response represents a successful completion.
	 *
	 * @var bool
	 */
	protected $is_success;

	/**
	 * Constructor.
	 *
	 * @param array $normalized_response Normalized response in standard format.
	 * @param array $original_response   Original raw response from provider.
	 * @param bool  $is_success          Whether the response is successful.
	 */
	public function __construct( array $normalized_response, array $original_response, $is_success = true ) {
		$this->normalized_response = $normalized_response;
		$this->original_response   = $original_response;
		$this->is_success          = (bool) $is_success;
	}

	/**
	 * Get the main text content from the response.
	 *
	 * @return string|null The response content, or null if not available.
	 */
	public function get_content() {
		if ( ! isset( $this->normalized_response['choices'][0]['message']['content'] ) ) {
			return null;
		}

		$content = $this->normalized_response['choices'][0]['message']['content'];

		// Content can be either a string or an array of segments.
		if ( is_string( $content ) ) {
			return $content;
		}

		// If it's an array, extract text from segments.
		if ( is_array( $content ) ) {
			$text_parts = array();
			foreach ( $content as $segment ) {
				if ( is_array( $segment ) && isset( $segment['type'] ) && 'text' === $segment['type'] && isset( $segment['text'] ) ) {
					$text_parts[] = $segment['text'];
				}
			}

			return ! empty( $text_parts ) ? implode( '', $text_parts ) : null;
		}

		return null;
	}

	/**
	 * Get error information if the response represents an error.
	 *
	 * @return array|null Array with 'code' and 'message' keys, or null if no error.
	 */
	public function get_error() {
		if ( $this->is_success ) {
			return null;
		}

		// Extract error from normalized response.
		if ( isset( $this->normalized_response['error'] ) ) {
			$error = $this->normalized_response['error'];

			return array(
				'code'    => isset( $error['code'] ) ? $error['code'] : 'unknown',
				'message' => isset( $error['message'] ) ? $error['message'] : 'Unknown error',
			);
		}

		// Fallback to checking original response.
		if ( isset( $this->original_response['error'] ) ) {
			$error = $this->original_response['error'];

			return array(
				'code'    => isset( $error['code'] ) ? $error['code'] : 'unknown',
				'message' => isset( $error['message'] ) ? $error['message'] : 'Unknown error',
			);
		}

		return array(
			'code'    => 'unknown',
			'message' => 'Request failed',
		);
	}

	/**
	 * Get tool calls from the response.
	 *
	 * @return array|null Array of tool call objects, or null if none present.
	 */
	public function get_tool_calls() {
		if ( ! isset( $this->normalized_response['choices'][0]['message']['tool_calls'] ) ) {
			return null;
		}

		$tool_calls = $this->normalized_response['choices'][0]['message']['tool_calls'];

		return is_array( $tool_calls ) && ! empty( $tool_calls ) ? $tool_calls : null;
	}

	/**
	 * Get token usage information.
	 *
	 * @return array|null Array with 'prompt_tokens', 'completion_tokens', 'total_tokens', or null.
	 */
	public function get_usage() {
		if ( ! isset( $this->normalized_response['usage'] ) || ! is_array( $this->normalized_response['usage'] ) ) {
			return null;
		}

		return $this->normalized_response['usage'];
	}

	/**
	 * Get the finish reason for the response.
	 *
	 * @return string|null Reason such as 'stop', 'length', 'tool_calls', or null.
	 */
	public function get_finish_reason() {
		if ( ! isset( $this->normalized_response['choices'][0]['finish_reason'] ) ) {
			return null;
		}

		return (string) $this->normalized_response['choices'][0]['finish_reason'];
	}

	/**
	 * Get the original raw response from the provider.
	 *
	 * @return array The raw, unprocessed response for debugging or advanced use.
	 */
	public function get_original_response() {
		return $this->original_response;
	}

	/**
	 * Check if the response represents a successful completion.
	 *
	 * @return bool True if successful, false if error occurred.
	 */
	public function is_success() {
		return $this->is_success;
	}

	/**
	 * Get the provider identifier.
	 *
	 * @return string Provider name (e.g., 'openai', 'gemini', 'anthropic', 'ollama').
	 */
	public function get_provider() {
		if ( isset( $this->normalized_response['provider'] ) ) {
			return (string) $this->normalized_response['provider'];
		}

		return 'unknown';
	}

	/**
	 * Get the model used for the response.
	 *
	 * @return string|null Model identifier, or null if not available.
	 */
	public function get_model() {
		if ( isset( $this->normalized_response['model'] ) ) {
			return (string) $this->normalized_response['model'];
		}

		return null;
	}
}
