<?php
/**
 * Abstract base class for all AI provider clients.
 *
 * Provides constructor injection of the three domain contracts every
 * provider needs (settings, HTTP, errors) plus common helper methods
 * for building authenticated requests.
 *
 * Concrete providers override getProviderSlug(), chat(), stream(),
 * and listModels().
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

abstract class AbstractProviderClient {

	/**
	 * The provider slug used for routing and settings lookups.
	 *
	 * Concrete providers MUST set this (e.g., 'openai', 'gemini', 'anthropic').
	 */
	protected string $providerSlug;

	public function __construct(
		protected readonly SettingsStoreInterface $settings,
		protected readonly HttpClientInterface $http,
		protected readonly ErrorFactoryInterface $errors,
	) {}

	/**
	 * Return the provider identifier slug.
	 */
	public function getProviderSlug(): string {
		return $this->providerSlug;
	}

	/**
	 * Send a chat-completion request and return the full response.
	 *
	 * @param array $messages  Conversation messages in OpenAI-compatible format.
	 * @param array $options   Provider-specific options (model, temperature, tools, etc.).
	 *
	 * @return array|mixed  Response array or error via ErrorFactoryInterface.
	 */
	abstract public function chat( array $messages, array $options = array() ): mixed;

	/**
	 * Stream a chat-completion response token by token.
	 *
	 * @param array         $messages  Conversation messages.
	 * @param array         $options   Provider-specific options.
	 * @param callable|null $onChunk  Called for each received token.
	 *
	 * @return array|mixed  Final response or error.
	 */
	abstract public function stream( array $messages, array $options = array(), ?callable $onChunk = null ): mixed;

	/**
	 * List available models for this provider.
	 *
	 * @return string[]|mixed  Array of model slugs, or error.
	 */
	abstract public function listModels(): mixed;

	// ─── Helpers for concrete providers ──────────────────────────────

	/**
	 * Get the configured API key for this provider.
	 *
	 * @return string  Empty string when no key is configured.
	 */
	protected function getApiKey(): string {
		$key = $this->settings->getApiKey( $this->providerSlug );

		return is_string( $key ) ? $key : '';
	}

	/**
	 * Get the base URL for this provider's API.
	 *
	 * Returns the provider's default URL when no override is configured.
	 */
	protected function getBaseUrl(): string {
		$url = $this->settings->getApiBaseUrl( $this->providerSlug );

		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}

		return $this->getDefaultBaseUrl();
	}

	/**
	 * Return the default API endpoint URL when no override is set.
	 */
	abstract protected function getDefaultBaseUrl(): string;

	/**
	 * Create a "missing API key" error in the canonical format.
	 */
	protected function missingApiKeyError(): mixed {
		$providerName = \ucfirst( $this->providerSlug );

		return $this->errors->create(
			'missing_api_key',
			"No {$providerName} API key has been configured.",
			array(
				'status'  => 400,
				'actions' => array(
					'configure_api_key' => "Add a {$providerName} API key in the plugin settings.",
				),
			),
		);
	}

	/**
	 * Build standard Authorization and content-type headers.
	 *
	 * @return array<string, string>
	 */
	protected function buildAuthHeaders( string $apiKey ): array {
		return array(
			'Authorization' => "Bearer {$apiKey}",
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Resolve the model identifier from options, falling back to the default.
	 */
	protected function resolveModel( array $options ): string {
		if ( ! empty( $options['model'] ) ) {
			return (string) $options['model'];
		}

		return $this->settings->getDefaultModel();
	}

	/**
	 * Get the HTTP request timeout in seconds.
	 */
	protected function getTimeout( array $options = array() ): int {
		if ( ! empty( $options['timeout'] ) ) {
			return \max( 5, (int) $options['timeout'] );
		}

		return $this->settings->getRequestTimeout();
	}
}
