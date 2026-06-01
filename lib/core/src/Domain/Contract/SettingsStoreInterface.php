<?php
/**
 * Settings store contract for the oOS AI orchestration core.
 *
 * Abstracts plugin/application configuration so that provider clients,
 * tools, and services never depend on WordPress get_option/update_option
 * or any other framework's config system.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Contract;

interface SettingsStoreInterface {

	/**
	 * Get a single setting value.
	 *
	 * @return mixed  The setting value, or $default if not set.
	 */
	public function get( string $key, mixed $default = null ): mixed;

	/**
	 * Get all settings as an associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array;

	/**
	 * Set a setting value.
	 */
	public function set( string $key, mixed $value ): void;

	/**
	 * Delete a setting entirely.
	 */
	public function delete( string $key ): void;

	/**
	 * Get the default AI provider slug (e.g., 'openai', 'gemini').
	 */
	public function getDefaultProvider(): string;

	/**
	 * Get the default AI model identifier.
	 */
	public function getDefaultModel(): string;

	/**
	 * Get an API key for a given provider.
	 *
	 * @return string|null  Null if no key is configured for this provider.
	 */
	public function getApiKey( string $provider ): ?string;

	/**
	 * Get the base URL for a provider's API endpoint.
	 *
	 * Returns null when the provider uses its default endpoint.
	 */
	public function getApiBaseUrl( string $provider ): ?string;

	/**
	 * Get the request timeout in seconds for HTTP calls to AI providers.
	 */
	public function getRequestTimeout(): int;

	/**
	 * Check if a boolean feature flag is enabled.
	 */
	public function isEnabled( string $feature ): bool;
}
