<?php
/**
 * Provider router — routes chat requests to the correct AI provider.
 *
 * Selects the appropriate provider client based on the assistant's
 * configured provider (openai, gemini, anthropic, etc.) and falls
 * back to the site default when not specified.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Provider;

use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;

class ProviderRouter {

	/**
	 * Registered provider clients, keyed by provider slug.
	 *
	 * @var array<string, AbstractProviderClient>
	 */
	private array $providers = array();

	/**
	 * Health tracker for provider failover.
	 *
	 * @var ProviderHealthTracker|null
	 */
	private ?ProviderHealthTracker $healthTracker = null;

	public function __construct(
		private readonly SettingsStoreInterface $settings,
		private readonly ErrorFactoryInterface $errors,
	) {}

	/**
	 * Set the health tracker for failover support.
	 *
	 * @since 1.2.5
	 *
	 * @param ProviderHealthTracker $tracker Health tracker instance.
	 * @return void
	 */
	public function setHealthTracker( ProviderHealthTracker $tracker ): void {
		$this->healthTracker = $tracker;
	}

	/**
	 * Check if provider failover is enabled and available.
	 *
	 * @since 1.2.5
	 *
	 * @return bool True when failover is active.
	 */
	private function failoverEnabled(): bool {
		if ( null === $this->healthTracker ) {
			return false;
		}

		$settings = $this->settings->get( 'enable_provider_failover', false );
		return (bool) $settings;
	}

	/**
	 * Register a provider client.
	 */
	public function register( AbstractProviderClient $client ): void {
		$this->providers[ $client->getProviderSlug() ] = $client;
	}

	/**
	 * Get a specific provider client by slug.
	 *
	 * @return AbstractProviderClient|null  Null if provider not registered.
	 */
	public function get( string $slug ): ?AbstractProviderClient {
		return $this->providers[ $slug ] ?? null;
	}

	/**
	 * Get all registered provider slugs.
	 *
	 * @return string[]
	 */
	public function getRegisteredSlugs(): array {
		return \array_keys( $this->providers );
	}

	/**
	 * Resolve the provider client to use for a chat request.
	 *
	 * Priority:
	 *  1. options['provider'] — explicitly requested
	 *  2. assistantConfig['provider'] — the assistant's configured provider
	 *  3. site default — as configured in settings
	 *
	 * @return AbstractProviderClient|null  Null if no provider resolves.
	 */
	public function resolveForChat( array $options = array(), array $assistantConfig = array() ): ?AbstractProviderClient {
		$providerSlug = '';

		if ( ! empty( $options['provider'] ) ) {
			$providerSlug = (string) $options['provider'];
		} elseif ( ! empty( $assistantConfig['provider'] ) ) {
			$providerSlug = (string) $assistantConfig['provider'];
		} else {
			$providerSlug = $this->settings->getDefaultProvider();
		}

		// Normalize aliases.
		$providerSlug = $this->normalizeSlug( $providerSlug );

		return $this->providers[ $providerSlug ] ?? null;
	}

	/**
	 * Send a chat completion through the appropriate provider.
	 *
	 * @return mixed  Provider response or error.
	 */
	public function chat(
		array $messages,
		array $options = array(),
		array $assistantConfig = array(),
	): mixed {
		$provider = $this->resolveForChat( $options, $assistantConfig );

		if ( null === $provider ) {
			$slug = $options['provider'] ?? $assistantConfig['provider'] ?? 'unknown';
			return $this->errors->create(
				'provider_not_found',
				"AI provider '{$slug}' is not registered or configured.",
				array( 'status' => 400 ),
			);
		}

		$result = $provider->chat( $messages, $options );

		// Record outcome and attempt failover on error.
		$slug = $provider->getProviderSlug();
		if ( $this->isError( $result ) ) {
			if ( $this->healthTracker ) {
				$this->healthTracker->recordFailure( $slug, $this->classifyError( $result ) );
			}

			$fallback = $this->attemptFailover( $messages, $options, $assistantConfig, $slug );
			if ( null !== $fallback ) {
				return $fallback;
			}
		} else {
			if ( $this->healthTracker ) {
				$this->healthTracker->recordSuccess( $slug, 0 );
			}
		}

		return $result;
	}

	/**
	 * Stream a chat completion through the appropriate provider.
	 */
	public function stream(
		array $messages,
		array $options = array(),
		array $assistantConfig = array(),
		?callable $onChunk = null,
	): mixed {
		$provider = $this->resolveForChat( $options, $assistantConfig );

		if ( null === $provider ) {
			$slug = $options['provider'] ?? $assistantConfig['provider'] ?? 'unknown';
			return $this->errors->create(
				'provider_not_found',
				"AI provider '{$slug}' is not registered.",
				array( 'status' => 400 ),
			);
		}

		$result = $provider->stream( $messages, $options, $onChunk );

		$slug = $provider->getProviderSlug();
		if ( $this->isError( $result ) ) {
			if ( $this->healthTracker ) {
				$this->healthTracker->recordFailure( $slug, $this->classifyError( $result ) );
			}
			$fallback = $this->attemptFailover( $messages, $options, $assistantConfig, $slug, $onChunk );
			if ( null !== $fallback ) {
				return $fallback;
			}
		} else {
			if ( $this->healthTracker ) {
				$this->healthTracker->recordSuccess( $slug, 0 );
			}
		}

		return $result;
	}

	/**
	 * List available models across all registered providers.
	 *
	 * @return array<string, string[]>  Provider slug → [model IDs].
	 */
	public function listAllModels(): array {
		$all = array();

		foreach ( $this->providers as $slug => $provider ) {
			$models = $provider->listModels();

			if ( is_array( $models ) ) {
				$all[ $slug ] = $models;
			}
		}

		return $all;
	}

	/**
	 * Check if a provider is registered.
	 */
	public function has( string $slug ): bool {
		return isset( $this->providers[ $this->normalizeSlug( $slug ) ] );
	}

	// ─── Failover helpers ───────────────────────────────────────────────

	/**
	 * Determine if a provider response is an error.
	 *
	 * @since 1.2.5
	 *
	 * @param mixed $result Provider response.
	 * @return bool True when the result represents an error.
	 */
	private function isError( mixed $result ): bool {
		if ( $result instanceof \WP_Error ) {
			return true;
		}

		if ( \is_array( $result ) && isset( $result['error'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Classify an error for health-tracker scoring.
	 *
	 * @since 1.2.5
	 *
	 * @param mixed $result Provider response.
	 * @return string Error classification.
	 */
	private function classifyError( mixed $result ): string {
		if ( $result instanceof \WP_Error ) {
			$message = strtolower( $result->get_error_message() );
			if ( str_contains( $message, 'timeout' ) || str_contains( $message, 'timed out' ) ) {
				return 'timeout';
			}
			if ( str_contains( $message, 'rate' ) || str_contains( $message, '429' ) ) {
				return 'rate_limit';
			}
			if ( str_contains( $message, '500' ) || str_contains( $message, '502' ) || str_contains( $message, '503' ) ) {
				return '5xx';
			}
			return 'api_error';
		}
		return 'unknown';
	}

	/**
	 * Attempt provider failover when the primary provider fails.
	 *
	 * @since 1.2.5
	 *
	 * @param array    $messages        Chat messages.
	 * @param array    $options         Request options.
	 * @param array    $assistantConfig Assistant config.
	 * @param string   $failedSlug      Slug of the provider that just failed.
	 * @param callable $onChunk         Optional stream callback.
	 * @return mixed|null Fallback result, or null if no fallback available.
	 */
	private function attemptFailover(
		array $messages,
		array $options,
		array $assistantConfig,
		string $failedSlug,
		?callable $onChunk = null
	): mixed {
		if ( ! $this->failoverEnabled() || null === $this->healthTracker ) {
			return null;
		}

		$fallbacks = $this->healthTracker->getFallbackChain( $failedSlug );
		foreach ( $fallbacks as $fallback ) {
			$fallbackProvider = $this->get( $fallback['slug'] );
			if ( ! $fallbackProvider ) {
				continue;
			}

			if ( null !== $onChunk ) {
				$result = $fallbackProvider->stream( $messages, $options, $onChunk );
			} else {
				$result = $fallbackProvider->chat( $messages, $options );
			}

			if ( ! $this->isError( $result ) ) {
				$this->healthTracker->recordSuccess( $fallback['slug'], 0 );
				return $result;
			}

			$this->healthTracker->recordFailure( $fallback['slug'], $this->classifyError( $result ) );
		}

		return null;
	}

	// ─── Helpers ──────────────────────────────────────────────────────

	/**
	 * Normalize provider slugs — Google's provider is 'gemini' in settings
	 * but may arrive as 'google'.
	 */
	private function normalizeSlug( string $slug ): string {
		return match ( \strtolower( \trim( $slug ) ) ) {
			'google' => 'gemini',
			'claude' => 'anthropic',
			'moonshot', 'moonshot_ai' => 'kimi',
			'nvidia'  => 'nvidia_nim',
			'cloudflare_ai', 'workers_ai' => 'cloudflare',
			'lmstudio' => 'lm_studio',
			'hugging_face', 'hf' => 'huggingface',
			'open_router' => 'openrouter',
			'digital_ocean', 'do' => 'digitalocean',
			default => $slug,
		};
	}
}
