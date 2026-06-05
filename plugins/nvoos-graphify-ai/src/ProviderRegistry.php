<?php
declare(strict_types=1);

namespace NvoosGraphifyAi;

use NvoosGraphifyAi\Contracts\ProviderClient;

/**
 * Provider registry — a container for registered AI provider clients.
 *
 * @since 1.0.0
 */
final class ProviderRegistry {

	/**
	 * Registered providers, keyed by provider slug.
	 *
	 * @var array<string,ProviderClient>
	 */
	private array $providers = array();

	/**
	 * Register a provider client.
	 *
	 * If a provider with the same slug is already registered,
	 * it is silently replaced (last-registered wins).
	 *
	 * @param ProviderClient $provider The provider client to register.
	 * @return void
	 */
	public function register( ProviderClient $provider ): void {
		$this->providers[ $provider->getProviderSlug() ] = $provider;
	}

	/**
	 * Retrieve a provider by its slug.
	 *
	 * @param string $slug The provider slug (e.g. 'openai', 'gemini').
	 * @return ProviderClient|null The provider client, or null if not found.
	 */
	public function get( string $slug ): ?ProviderClient {
		return $this->providers[ $slug ] ?? null;
	}

	/**
	 * Get the default provider (first registered, or the one matching
	 * the configured default).
	 *
	 * @return ProviderClient|null
	 */
	public function getDefault(): ?ProviderClient {
		// If no providers registered, bail.
		if ( empty( $this->providers ) ) {
			return null;
		}

		// Prefer the configured default from settings.
		$default = Settings::getDefaultProvider();
		if ( $default && isset( $this->providers[ $default ] ) ) {
			return $this->providers[ $default ];
		}

		// Fall back to the first registered.
		$first = array_key_first( $this->providers );
		return $this->providers[ $first ];
	}

	/**
	 * Return all registered providers.
	 *
	 * @return array<string,ProviderClient>
	 */
	public function all(): array {
		return $this->providers;
	}

	/**
	 * Check whether a provider with the given slug is registered.
	 *
	 * @param string $slug The provider slug.
	 * @return bool
	 */
	public function has( string $slug ): bool {
		return isset( $this->providers[ $slug ] );
	}

	/**
	 * Return the total count of registered providers.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->providers );
	}

	/**
	 * Return an array of provider slugs currently registered.
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		return array_keys( $this->providers );
	}
}
