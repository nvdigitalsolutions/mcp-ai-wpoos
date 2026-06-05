<?php
/**
 * Laravel adapter: SettingsStoreInterface implementation.
 *
 * Wraps Laravel's config() helper and a database-backed settings table
 * behind the framework-agnostic SettingsStoreInterface. Config file
 * values provide defaults; database values (written at runtime) override
 * them. This mirrors the WordPress pattern where file-based defaults
 * are merged with runtime option values.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SettingsStore implements SettingsStoreInterface {

	/**
	 * The config key prefix for oOS settings (config/nvoos.php).
	 */
	private const CONFIG_KEY = 'oos';

	/**
	 * The database table name for runtime-overridden settings.
	 */
	private string $table;

	/**
	 * Default settings used when neither config nor DB has a value.
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
	 * Cached merged settings to avoid repeated DB + config reads per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cached = null;

	/**
	 * @param string $table  Database table for runtime settings. Defaults to 'nvoos_settings'.
	 */
	public function __construct( string $table = 'nvoos_settings' ) {
		$this->table = $table;
	}

	/**
	 * Get a single setting value.
	 *
	 * Resolution order: DB override → config file → self::DEFAULTS → $default.
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
	 * Merges: DEFAULTS → config/nvoos.php → database overrides.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cached ) {
			return $this->cached;
		}

		$fileSettings   = Config::get( self::CONFIG_KEY, array() );
		$dbSettings     = $this->loadFromDatabase();

		$this->cached = array_merge(
			self::DEFAULTS,
			is_array( $fileSettings ) ? $fileSettings : array(),
			$dbSettings,
		);

		return $this->cached;
	}

	/**
	 * Set a runtime setting value (persisted to database).
	 *
	 * Only settings that differ from config-file defaults should be
	 * written to the database.
	 *
	 * @param string $key    Setting key.
	 * @param mixed  $value  New value.
	 */
	public function set( string $key, mixed $value ): void {
		$this->ensureTableExists();

		DB::table( $this->table )->upsert(
			array(
				'key'        => $key,
				'value'      => json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'updated_at' => now(),
			),
			array( 'key' ),
			array( 'value', 'updated_at' ),
		);

		// Bust the in-memory cache so the next all() picks up the change.
		$this->cached = null;
	}

	/**
	 * Delete a setting from the database entirely.
	 *
	 * The config-file default (if any) will be used on subsequent reads.
	 *
	 * @param string $key  Setting key.
	 */
	public function delete( string $key ): void {
		DB::table( $this->table )->where( 'key', $key )->delete();
		$this->cached = null;
	}

	/**
	 * Get the default AI provider slug (e.g., 'openai', 'gemini').
	 *
	 * @return string
	 */
	public function getDefaultProvider(): string {
		return (string) $this->get( 'default_provider', 'openai' );
	}

	/**
	 * Get the default AI model identifier.
	 *
	 * Considers the current provider — Gemini uses a different default model.
	 *
	 * @return string
	 */
	public function getDefaultModel(): string {
		$provider = $this->getDefaultProvider();

		if ( 'gemini' === $provider ) {
			return (string) $this->get( 'default_gemini_model', 'gemini-2.0-flash' );
		}

		return (string) $this->get( 'default_model', 'gpt-4o-mini' );
	}

	/**
	 * Get an API key for a given provider.
	 *
	 * API keys are read from config (or env) — never stored in the
	 * settings table for security. Use the `services.{provider}.key`
	 * config path, falling back to `oos.api_keys.{provider}`.
	 *
	 * @param string $provider  Provider slug (e.g., 'openai').
	 * @return string|null  Null if no key is configured.
	 */
	public function getApiKey( string $provider ): ?string {
		// Local providers don't need API keys.
		if ( in_array( $provider, array( 'ollama', 'lm_studio' ), true ) ) {
			return '';
		}

		// Try Laravel's standard services config first.
		$key = Config::get( "services.{$provider}.key" );
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}

		// Fall back to the oOS-specific key map.
		$key = Config::get( "oos.api_keys.{$provider}" );
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}

		// Last resort: the legacy flat key in the config or DB.
		$keyMap = array(
			'openai'       => 'openai_api_key',
			'gemini'       => 'gemini_api_key',
			'anthropic'    => 'anthropic_api_key',
			'deepseek'     => 'deepseek_api_key',
			'openrouter'   => 'openrouter_api_key',
			'kimi'         => 'kimi_api_key',
			'digitalocean' => 'digitalocean_api_key',
			'nvidia_nim'   => 'nvidia_nim_api_key',
			'cloudflare'   => 'cloudflare_api_key',
		);

		$optionKey = $keyMap[ $provider ] ?? "{$provider}_api_key";
		$key       = $this->get( $optionKey );

		return is_string( $key ) && '' !== $key ? $key : null;
	}

	/**
	 * Get the base URL for a provider's API endpoint.
	 *
	 * Returns null when the provider uses its default endpoint.
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

	/**
	 * Get the request timeout in seconds for HTTP calls to AI providers.
	 *
	 * Capped at a minimum of 5 seconds.
	 *
	 * @return int
	 */
	public function getRequestTimeout(): int {
		return max( 5, (int) $this->get( 'request_timeout', 60 ) );
	}

	/**
	 * Check if a boolean feature flag is enabled.
	 *
	 * @param string $feature  Feature identifier (e.g., 'rate_limiting').
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

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * Load settings from the database table.
	 *
	 * @return array<string, mixed>
	 */
	private function loadFromDatabase(): array {
		if ( ! $this->tableExists() ) {
			return array();
		}

		$rows = DB::table( $this->table )->pluck( 'value', 'key' );

		$settings = array();
		foreach ( $rows as $key => $value ) {
			if ( is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				$settings[ $key ] = JSON_ERROR_NONE === json_last_error() ? $decoded : $value;
			} else {
				$settings[ $key ] = $value;
			}
		}

		return $settings;
	}

	/**
	 * Whether the settings table exists in the database.
	 */
	private function tableExists(): bool {
		return Schema::hasTable( $this->table );
	}

	/**
	 * Create the settings table if it doesn't exist.
	 *
	 * Called lazily on first write. Production apps should use the
	 * published migration instead.
	 */
	private function ensureTableExists(): void {
		if ( $this->tableExists() ) {
			return;
		}

		Schema::create( $this->table, function ( $table ) {
			$table->string( 'key' )->primary();
			$table->text( 'value' )->nullable();
			$table->timestamps();
		} );
	}
}
