<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Contracts;

/**
 * Contract for AI language-model provider clients.
 *
 * Every provider (OpenAI, Gemini, Anthropic, Ollama, etc.) implements
 * this interface so the ChatService can route requests uniformly.
 *
 * All parameters and return values use plain PHP arrays to keep the
 * interface dependency-free and testable.
 *
 * @since 1.0.0
 */
interface ProviderClient {

	/**
	 * Send a chat-completion request and return the full response.
	 *
	 * @param array $messages Conversation messages in OpenAI-compatible format.
	 * @param array $options  Provider-specific options (model, temperature, tools, etc.).
	 * @return array|\WP_Error Response array with at minimum `content` and `usage`
	 *                         keys, or WP_Error on failure.
	 */
	public function chat( array $messages, array $options = array() );

	/**
	 * Send a chat-completion request and stream the response via callback.
	 *
	 * @param array         $messages Conversation messages.
	 * @param array         $options  Provider-specific options.
	 * @param callable|null $callback Called for each streamed token/chunk.
	 *                                Signature: function(string $chunk, bool $done): void.
	 * @return array|\WP_Error Final response summary or WP_Error on failure.
	 */
	public function stream( array $messages, array $options = array(), ?callable $callback = null );

	/**
	 * Return a list of available models for this provider.
	 *
	 * @return array|\WP_Error Array of model identifier strings, or WP_Error.
	 */
	public function listModels();

	/**
	 * Return the provider slug (e.g. 'openai', 'gemini', 'anthropic').
	 *
	 * Used by the provider registry to identify and route to this client.
	 *
	 * @return string
	 */
	public function getProviderSlug(): string;
}
