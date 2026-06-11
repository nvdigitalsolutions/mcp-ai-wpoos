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

	public function __construct(
		private readonly SettingsStoreInterface $settings,
		private readonly ErrorFactoryInterface $errors,
	) {}

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

		return $provider->chat( $messages, $options );
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

		return $provider->stream( $messages, $options, $onChunk );
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
