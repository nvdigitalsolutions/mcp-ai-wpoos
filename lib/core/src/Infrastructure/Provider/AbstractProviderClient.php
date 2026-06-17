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
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;

abstract class AbstractProviderClient {

	/**
	 * The provider slug used for routing and settings lookups.
	 *
	 * Concrete providers MUST set this (e.g., 'openai', 'gemini', 'anthropic').
	 */
	protected string $providerSlug;

	/**
	 * Optional token-budget manager for pre-flight context-window validation.
	 *
	 * When set (via the oos-bridge or adapter layer), every chat() / stream()
	 * call performs a pre-flight check against the model's context window.
	 * When null, validation is skipped — this preserves backward compatibility
	 * for callers that haven't wired the token-budget manager yet.
	 */
	protected ?TokenBudgetManager $tokenBudget = null;

	public function __construct(
		protected readonly SettingsStoreInterface $settings,
		protected readonly HttpClientInterface $http,
		protected readonly ErrorFactoryInterface $errors,
	) {}

	/**
	 * Wire the token-budget manager for pre-flight context-window validation.
	 *
	 * Called by the DI bridge (oos-bridge.php) after all providers are
	 * constructed.  Once set, every chat() and stream() call runs
	 * validateContextWindow() before sending the request.
	 */
	public function setTokenBudgetManager( TokenBudgetManager $budget ): void {
		$this->tokenBudget = $budget;
	}

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

	// ─── Context Window Validation ─────────────────────────────────────

	/**
	 * Run pre-flight context-window validation on a chat payload.
	 *
	 * Concrete provider clients call this *after* building the full
	 * request payload and *before* sending the HTTP request.  When the
	 * token-budget manager is wired, this:
	 *
	 *  1. Estimates tokens from the serialised payload.
	 *  2. Looks up the model's context window limit.
	 *  3. Hard-rejects if the estimate exceeds the limit.
	 *
	 * Returns null when validation passes (or when no budget manager is
	 * wired).  Returns an error shape when the context window is exceeded.
	 *
	 * @param array  $payload  The full request payload about to be sent.
	 * @param string $model    Model identifier.
	 * @return array|null  Error shape or null.
	 *
	 * @phpstan-return array{code: string, message: string, data: array}|null
	 */
	protected function validateContextWindow( array $payload, string $model ): ?array {
		if ( null === $this->tokenBudget ) {
			return null;
		}

		return $this->tokenBudget->validateContextWindow(
			$payload,
			$model,
			$this->providerSlug,
		);
	}

	/**
	 * Convert a context-window validation error into a provider error.
	 *
	 * Since validateContextWindow() returns raw array shapes (not error
	 * objects), this helper wraps the result through ErrorFactoryInterface
	 * so that concrete providers can return a proper error type.
	 *
	 * @param array $result  The error shape from validateContextWindow().
	 * @return mixed  A properly-typed error via ErrorFactoryInterface.
	 */
	protected function contextWindowError( array $result ): mixed {
		return $this->errors->create(
			$result['code'],
			$result['message'],
			$result['data'],
		);
	}
}
