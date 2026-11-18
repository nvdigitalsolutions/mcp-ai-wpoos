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
 * TWO LAYERS (Proposal 149 §16). A store constructed with a tenant id reads
 * that estate's rows over the install-wide platform rows, and writes to the
 * estate table. A store constructed without one is the platform layer: it
 * reads and writes `nvoos_settings` only. The default constructor argument
 * keeps every pre-existing caller on the platform layer, which is what the
 * console relied on before settings became estate-scoped.
 *
 * On WordPress the distinction does not exist: one install is one estate, so
 * the global option table already is the estate layer.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SettingsStore implements SettingsStoreInterface {

	/**
	 * The config key prefix for oOS settings (config/nvoos.php).
	 */
	private const CONFIG_KEY = 'oos';

	/**
	 * The install-wide (platform) table for runtime overridden settings.
	 */
	private string $table;

	/**
	 * The estate scoped table. Read only when a tenant id is set.
	 */
	private string $tenantTable;

	/**
	 * Resolves the estate this store acts for, or null for the platform layer.
	 *
	 * A RESOLVER, not a fixed id, and that is load-bearing. `ProviderRouter` is a
	 * singleton and hands one store instance to twelve provider clients at
	 * construction time — before any tenant middleware has run. A fixed id would
	 * therefore pin every provider call in the process to whichever estate
	 * happened to be current at boot. Reading through a closure moves the
	 * decision to each `all()`, so a request or job sees its own estate.
	 *
	 * Null (or a resolver returning an empty value) disables the estate layer,
	 * which is how `/ops` writes an operator-scope setting exactly once rather
	 * than once per estate.
	 */
	private ?Closure $tenant;

	/**
	 * Default settings used when neither config nor DB has a value.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'default_provider'               => 'openai',
		'default_model'                  => 'gpt-4o-mini',
		// Keep in step with the WordPress default
		// (WP_MCP_AI_Admin_Settings_Base::get_default_settings()). A retired model
		// here silently breaks every Gemini chat on a fresh install.
		'default_gemini_model'           => 'gemini-3.8-flash',
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
	 * @param string       $table        Platform table for runtime settings.
	 * @param Closure|null $tenant       Resolves the estate to scope reads and
	 *                                   writes to, or null for the platform layer.
	 * @param string       $tenantTable  Estate table for runtime settings.
	 */
	public function __construct(
		string $table = 'nvoos_settings',
		?Closure $tenant = null,
		string $tenantTable = 'nvoos_tenant_settings'
	) {
		$this->table       = $table;
		$this->tenant      = $tenant;
		$this->tenantTable = $tenantTable;
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
	 * Merges: DEFAULTS → config/nvoos.php → platform rows → estate rows.
	 *
	 * The estate layer comes last on purpose: an estate that has set its own
	 * default model must win over the deployment default. Platform rows are
	 * consulted in both layers, which is why an operator-scope setting (held
	 * only in the platform table) still resolves for an estate.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$fileSettings = Config::get( self::CONFIG_KEY, array() );
		$platformRows = $this->loadFromDatabase( $this->table );
		$estateRows   = $this->loadEstateSettings();

		return array_merge(
			self::DEFAULTS,
			is_array( $fileSettings ) ? $fileSettings : array(),
			$platformRows,
			$estateRows,
		);
	}

	/**
	 * Set a runtime setting value (persisted to database).
	 *
	 * Writes to the layer this instance was built for: the estate table when a
	 * tenant id is set, otherwise the platform table. Only settings that differ
	 * from config-file defaults should be written.
	 *
	 * The caller decides the layer, not the key. That is deliberate: the store
	 * is a key/value adapter and has no catalogue, so it cannot know which keys
	 * are operator-scope. `App\Support\Settings\SettingsService` owns that
	 * routing (149 R1).
	 *
	 * @param string $key    Setting key.
	 * @param mixed  $value  New value.
	 */
	public function set( string $key, mixed $value ): void {
		$table = $this->writeTable();

		$this->ensureTableExists( $table );

		$row = array(
			'key'        => $key,
			'value'      => json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'updated_at' => now(),
		);

		if ( $this->isEstateScoped() ) {
			$row['tenant_id'] = (string) $this->tenantId();

			DB::table( $table )->upsert(
				$row,
				array( 'tenant_id', 'key' ),
				array( 'value', 'updated_at' ),
			);

			return;
		}

		DB::table( $table )->upsert(
			$row,
			array( 'key' ),
			array( 'value', 'updated_at' ),
		);
	}

	/**
	 * Delete a setting from the layer this instance writes to.
	 *
	 * The config-file default (if any) will be used on subsequent reads. On an
	 * estate layer this reveals the platform or configured value again, which is
	 * "reset to inherited" rather than "unset" — the documented behaviour of a
	 * fallback chain.
	 *
	 * @param string $key  Setting key.
	 */
	public function delete( string $key ): void {
		$table = $this->writeTable();

		if ( ! $this->tableExists( $table ) ) {
			return;
		}

		$query = DB::table( $table )->where( 'key', $key );

		if ( $this->isEstateScoped() ) {
			$query->where( 'tenant_id', (string) $this->tenantId() );
		}

		$query->delete();
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
			return (string) $this->get( 'default_gemini_model', 'gemini-3.8-flash' );
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
		// The settings surface is the source of truth for these names. Several of
		// them were read under a second name that nothing wrote (`gemini_api_base_url`,
		// `lm_studio_base_url`, `nvidia_nim_base_url`, `ollama_base_url`), so a custom
		// endpoint configured by an administrator never reached the provider client.
		// `legacy` keeps those old names readable for rows that predate the fix.
		$urlMap = array(
			'openai'       => 'openai_base_url',
			'gemini'       => 'gemini_base_url',
			'anthropic'    => 'anthropic_base_url',
			'deepseek'     => 'deepseek_base_url',
			'ollama'       => 'ollama_endpoint_url',
			'lm_studio'    => 'lm_studio_endpoint_url',
			'openrouter'   => 'openrouter_base_url',
			'kimi'         => 'kimi_base_url',
			'digitalocean' => 'digitalocean_base_url',
			'nvidia_nim'   => 'nvidia_endpoint_url',
			'cloudflare'   => 'cloudflare_base_url',
			'huggingface'  => 'huggingface_endpoint_url',
			'baseten'      => 'baseten_base_url',
			// Not an AI provider: the Crawl4AI tools call this contract for their
			// service root, and the missing entry made their override unreachable.
			'crawl4ai'     => 'crawl4ai_base_url',
		);

		$legacyMap = array(
			'ollama'     => 'ollama_base_url',
			'gemini'     => 'gemini_api_base_url',
			'lm_studio'  => 'lm_studio_base_url',
			'nvidia_nim' => 'nvidia_nim_base_url',
		);

		$optionKey = $urlMap[ $provider ] ?? null;
		if ( null === $optionKey ) {
			return null;
		}

		$url = $this->get( $optionKey );

		if ( ( ! is_string( $url ) || '' === $url ) && isset( $legacyMap[ $provider ] ) ) {
			$url = $this->get( $legacyMap[ $provider ] );
		}

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
	 * The estate this instance acts for, or null for the platform layer.
	 *
	 * The empty string is treated as "no estate": an unpinned TenantContext can
	 * surface as either null or '', and a row keyed by '' would be a third layer
	 * nobody can reason about.
	 */
	private function tenantId(): ?string {
		if ( null === $this->tenant ) {
			return null;
		}

		$tenantId = ( $this->tenant )();

		return is_string( $tenantId ) && '' !== $tenantId ? $tenantId : null;
	}

	/**
	 * Whether this instance acts for an estate rather than the platform.
	 */
	private function isEstateScoped(): bool {
		return null !== $this->tenantId();
	}

	/**
	 * The table this instance writes to.
	 */
	private function writeTable(): string {
		return $this->isEstateScoped() ? $this->tenantTable : $this->table;
	}

	/**
	 * Load one settings table as a key/value map.
	 *
	 * @param string $table  Table name.
	 * @return array<string, mixed>
	 */
	private function loadFromDatabase( string $table ): array {
		if ( ! $this->tableExists( $table ) ) {
			return array();
		}

		return $this->decode( DB::table( $table )->pluck( 'value', 'key' ) );
	}

	/**
	 * The estate layer, or an empty set for the platform instance.
	 *
	 * @return array<string, mixed>
	 */
	private function loadEstateSettings(): array {
		if ( ! $this->isEstateScoped() ) {
			return array();
		}

		if ( ! $this->tableExists( $this->tenantTable ) ) {
			return array();
		}

		$rows = DB::table( $this->tenantTable )
			->where( 'tenant_id', (string) $this->tenantId() )
			->pluck( 'value', 'key' );

		return $this->decode( $rows );
	}

	/**
	 * Decode JSON-encoded values, leaving anything unparseable as-is.
	 *
	 * @param iterable<string, mixed> $rows
	 * @return array<string, mixed>
	 */
	private function decode( iterable $rows ): array {
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
	 * Whether a settings table exists in the database.
	 *
	 * @param string $table  Table name.
	 */
	private function tableExists( string $table ): bool {
		return Schema::hasTable( $table );
	}

	/**
	 * Create a settings table if it doesn't exist.
	 *
	 * Called lazily on first write. Production apps should use the published
	 * migration instead; this path exists so a bare install can persist a value
	 * without one. It must stay shape-identical to the migration, including the
	 * composite key on the estate table — a mismatch would make `upsert()`
	 * silently insert duplicates instead of updating.
	 *
	 * @param string $table  Table name.
	 */
	private function ensureTableExists( string $table ): void {
		if ( $this->tableExists( $table ) ) {
			return;
		}

		$estate = $table === $this->tenantTable;

		Schema::create( $table, static function ( $blueprint ) use ( $estate ) {
			if ( $estate ) {
				$blueprint->string( 'tenant_id', 64 );
			}

			$blueprint->string( 'key', 191 );
			$blueprint->text( 'value' )->nullable();
			$blueprint->timestamps();

			if ( $estate ) {
				$blueprint->primary( array( 'tenant_id', 'key' ) );
			} else {
				$blueprint->primary( 'key' );
			}
		} );
	}
}
