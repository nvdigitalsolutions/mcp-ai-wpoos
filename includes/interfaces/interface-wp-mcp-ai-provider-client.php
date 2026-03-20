<?php
/**
 * Interface: AI Provider Client
 *
 * Common contract for all AI provider clients (OpenAI, Gemini, Anthropic, Ollama,
 * LM Studio, HuggingFace, Cloudflare). Implementing this interface allows the
 * Language Model Router to treat all providers uniformly and makes provider clients
 * independently testable.
 *
 * Concrete implementations live in `includes/infrastructure/providers/`.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstraction for AI language-model provider clients.
 *
 * All parameters and return values use plain PHP arrays rather than
 * framework-specific objects to keep the interface dependency-free.
 *
 * @since 1.2.0
 */
interface Interface_WP_MCP_AI_Provider_Client {

	/**
	 * Send a chat-completion request and return the full response.
	 *
	 * @param array $messages  Conversation messages in OpenAI-compatible format.
	 * @param array $options   Provider-specific options (model, temperature, tools, etc.).
	 * @return array|WP_Error Response array with at minimum `content`, `usage`, and
	 *                        `model` keys, or WP_Error on failure.
	 */
	public function chat( $messages, $options = array() );

	/**
	 * Send a chat-completion request and stream the response via callback.
	 *
	 * @param array    $messages  Conversation messages.
	 * @param array    $options   Provider-specific options.
	 * @param callable $callback  Called for each streamed token/chunk. Signature:
	 *                            `function( string $chunk, bool $done ): void`.
	 * @return array|WP_Error Final response summary or WP_Error on failure.
	 */
	public function stream( $messages, $options = array(), $callback = null );

	/**
	 * Return a list of available models for this provider.
	 *
	 * @return array|WP_Error Array of model identifier strings, or WP_Error on failure.
	 */
	public function list_models();

	/**
	 * Return the provider slug (e.g. 'openai', 'gemini', 'anthropic', 'ollama').
	 *
	 * Used by the Language Model Router to identify the provider.
	 *
	 * @return string
	 */
	public function get_provider_slug();
}
