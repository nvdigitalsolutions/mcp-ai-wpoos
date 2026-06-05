<?php
/**
 * Craft adapter: SettingsStoreInterface implementation.
 *
 * Wraps Craft's config system behind the framework-agnostic
 * SettingsStoreInterface. Reads from `config/nvoos.php` via
 * `Craft::$app->config->getConfigFromFile()`, with env variable
 * resolution via `Craft::parseEnv()`.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Adapter;

use Craft;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class SettingsStore implements SettingsStoreInterface {

	/**
	 * The config filename (without .php) that holds oOS settings.
	 */
	private const CONFIG_FILE = 'oos';

	/**
	 * Default settings used when the config file has no value.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'default_provider'               => 'openai',
		'default_model'                  => 'gpt-4o-mini',
		'default_gemini_model'           => 'gemini-2.0-flash',
		'request_timeout'                => 60,
		'enable_rate_limiting'           => false,
		'rate_limit_requests'            => 100,
		'rate_limit_window'              => 3600,
		'enable_high_token_model_switch' => true,
		'enable_multi_agent_teams'       => true,
		'enable_acp_server'              => false,
		'enable_a2a_server'              => false,
		'enable_chat_memory'             => true,
		'rest_enable_assistant_list'     => true,
		'rest_enable_assistant_create'   => false,
		'rest_enable_assistant_delete'   => false,
	);

	/**
	 * Cached merged settings to avoid repeated config reads per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cached = null;

	/**
	 * Get a single setting value.
	 *
	 * Resolution order: config/nvoos.php → self::DEFAULTS → $default.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $default  Fallback if not found in any layer.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->all();

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Get all settings as an associative array.
	 *
	 * Merges: DEFAULTS → config/nvoos.php. Environment variables
	 * referenced via `Craft::parseEnv('$VAR')` are automatically
	 * resolved by Craft's config system.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cached ) {
			return $this->cached;
		}

		$fileSettings = Craft::$app->config->getConfigFromFile( self::CONFIG_FILE );

		$this->cached = array_merge(
			self::DEFAULTS,
			is_array( $fileSettings ) ? $fileSettings : array(),
		);

		return $this->cached;
	}

	/**
	 * Set a runtime setting value.
	 *
	 * Craft's config is file-based and not designed for runtime writes.
	 * This method stores values in the cache layer so they survive the
	 * current request, but will reset on cache clear. For persistent
	 * runtime settings, use a database-backed approach or plugin store.
	 *
	 * @param string $key    Setting key.
	 * @param mixed  $value  New value.
	 */
	public function set( string $key, mixed $value ): void {
		// Runtime settings are stored in cache since Craft config is read-only.
		$runtimeKey = 'nvoos_runtime_setting_' . $key;
		Craft::$app->cache->set( $runtimeKey, $value, 86400 * 30 );

		// Merge into the in-memory cache.
		$this->cached = null;
	}

	/**
	 * Delete a runtime setting.
	 *
	 * @param string $key  Setting key.
	 */
	public function delete( string $key ): void {
		Craft::$app->cache->delete( 'nvoos_runtime_setting_' . $key );
		$this->cached = null;
	}

	public function getDefaultProvider(): string {
		$value = $this->get( 'default_provider', 'openai' );

		return is_string( $value ) ? $value : 'openai';
	}

	public function getDefaultModel(): string {
		$provider = $this->getDefaultProvider();

		if ( 'gemini' === $provider ) {
			$value = $this->get( 'default_gemini_model', 'gemini-2.0-flash' );

			return is_string( $value ) ? $value : 'gemini-2.0-flash';
		}

		$value = $this->get( 'default_model', 'gpt-4o-mini' );

		return is_string( $value ) ? $value : 'gpt-4o-mini';
	}

	/**
	 * Get an API key for a given provider.
	 *
	 * API keys are read from the config file, which supports
	 * `Craft::parseEnv('$OPENAI_API_KEY')` for environment-based secrets.
	 *
	 * @param string $provider  Provider slug.
	 * @return string|null
	 */
	public function getApiKey( string $provider ): ?string {
		// Local providers don't need API keys.
		if ( in_array( $provider, array( 'ollama', 'lm_studio' ), true ) ) {
			return '';
		}

		// Try the provider-specific config key first.
		$key = $this->get( "{$provider}_api_key" );
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}

		// Fall back to the generic api_keys map.
		$apiKeys = $this->get( 'api_keys', array() );
		if ( is_array( $apiKeys ) && isset( $apiKeys[ $provider ] ) && is_string( $apiKeys[ $provider ] ) && '' !== $apiKeys[ $provider ] ) {
			return $apiKeys[ $provider ];
		}

		return null;
	}

	/**
	 * Get the base URL for a provider's API endpoint.
	 *
	 * @param string $provider  Provider slug.
	 * @return string|null
	 */
	public function getApiBaseUrl( string $provider ): ?string {
		$urlMap = array(
			'openai'       => 'openai_base_url',
			'gemini'       => 'gemini_api_base_url',
			'anthropic'    => 'anthropic_base_url',
			'deepseek'     => 'deepseek_base_url',
			'ollama'       => 'ollama_base_url',
			'lm_studio'    => 'lm_studio_base_url',
			'openrouter'   => 'openrouter_base_url',
			'kimi'         => 'kimi_base_url',
			'digitalocean' => 'digitalocean_base_url',
			'nvidia_nim'   => 'nvidia_nim_base_url',
			'cloudflare'   => 'cloudflare_base_url',
		);

		$optionKey = $urlMap[ $provider ] ?? null;
		if ( null === $optionKey ) {
			return null;
		}

		$url = $this->get( $optionKey );

		return is_string( $url ) && '' !== $url ? rtrim( $url, '/' ) : null;
	}

	public function getRequestTimeout(): int {
		return max( 5, (int) $this->get( 'request_timeout', 60 ) );
	}

	/**
	 * Check if a boolean feature flag is enabled.
	 *
	 * @param string $feature  Feature identifier.
	 * @return bool
	 */
	public function isEnabled( string $feature ): bool {
		$flagMap = array(
			'rate_limiting'           => 'enable_rate_limiting',
			'high_token_model_switch' => 'enable_high_token_model_switch',
			'multi_agent_teams'       => 'enable_multi_agent_teams',
			'acp_server'              => 'enable_acp_server',
			'a2a_server'              => 'enable_a2a_server',
			'chat_memory'             => 'enable_chat_memory',
			'assistant_list_rest'     => 'rest_enable_assistant_list',
			'assistant_create_rest'   => 'rest_enable_assistant_create',
			'assistant_delete_rest'   => 'rest_enable_assistant_delete',
		);

		$optionKey = $flagMap[ $feature ] ?? $feature;

		return (bool) $this->get( $optionKey, false );
	}
}
