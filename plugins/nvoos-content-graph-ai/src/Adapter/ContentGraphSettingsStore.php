<?php
/**
 * Content Graph SettingsStore — maps nvoos_content_graph_settings to
 * the nvoos/core SettingsStoreInterface.
 *
 * The existing Nvoos\WordPress\Adapter\SettingsStore (v1) targets
 * wp_mcp_ai_settings for the base/pro plugin. This v2 adapter targets
 * nvoos_content_graph_settings for the nvoos-content-graph ecosystem.
 *
 * Key translation:
 *   Interface method           → Content Graph option key
 *   ───────────────────────────────────────────────────
 *   getDefaultProvider()       → ai_default_provider
 *   getDefaultModel()          → ai_default_model
 *   getApiKey('openai')        → ai_api_key_openai
 *   getApiBaseUrl('ollama')    → ollama_base_url
 *   getRequestTimeout()        → (always 120 — not configurable in Content Graph)
 *
 * @package NvoosContentGraphAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Adapter;

use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use NvoosContentGraphAi\Security\CredentialStore;

class ContentGraphSettingsStore implements SettingsStoreInterface {

	/**
	 * The WordPress option key used by nvoos-content-graph.
	 */
	private const OPTION_KEY = 'nvoos_content_graph_settings';

	/**
	 * Default values seeded by Plugin::addDefaultSettings().
	 *
	 * These mirror the defaults registered via the
	 * nvoos_content_graph/default_settings filter in Plugin.php.
	 */
	private const DEFAULTS = array(
		'ai_default_provider'      => 'openai',
		'ai_default_model'         => 'gpt-4o',
		'ai_chat_enabled'          => true,
		'ai_temperature'           => 0.7,
		'ai_max_tokens'            => 4096,
		'ai_api_key_openai'        => '',
		'ai_api_key_gemini'        => '',
		'ai_api_key_ollama'        => '',
		'ollama_base_url'          => 'http://localhost:11434',
		'ollama_model'             => 'llama3.3',
		'ai_api_key_anthropic'     => '',
		'ai_api_key_deepseek'      => '',
		'ai_api_key_openrouter'    => '',
		'ai_api_key_huggingface'   => '',
		'huggingface_endpoint_url' => 'https://api-inference.huggingface.co',
		'huggingface_model'        => 'meta-llama/Llama-3.3-70B-Instruct',
		'ai_api_key_cloudflare'    => '',
		'cloudflare_account_id'    => '',
		'cloudflare_model'         => '@cf/meta/llama-3.3-70b-instruct',
		'ai_api_key_lmstudio'      => '',
		'lmstudio_base_url'        => 'http://localhost:1234/v1',
		'lmstudio_model'           => 'local-model',
		'ai_api_key_nvidia'        => '',
		'ai_api_key_digitalocean'  => '',
		'ai_api_key_kimi'          => '',
		'ai_api_key_baseten'       => '',
		'ai_api_key_zai'           => '',
		'zai_base_url'             => 'https://api.z.ai/api/paas/v4',
		'ai_system_prompt'         => 'You are a helpful assistant for the NV oOS Content Graph on this WordPress site. Answer questions about the site content and its knowledge graph accurately and concisely. When tools for querying the graph are provided, use them to ground your answers in real data instead of guessing. Cite nodes, posts, or relationships when relevant. If you do not know something or the data is unavailable, say so plainly. Format answers with Markdown.',
	);

	// ─── SettingsStoreInterface ────────────────────────────────────

	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->all();

		return $settings[ $key ] ?? $default;
	}

	public function all(): array {
		$settings = \get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Secrets live in the encrypted CredentialStore — never surface
		// them through the generic settings map. Consumers must use
		// getApiKey() / hasCredentials() instead.
		foreach ( array_keys( $settings ) as $key ) {
			if ( self::isSecretSettingKey( $key ) ) {
				unset( $settings[ $key ] );
			}
		}

		return \array_merge( self::DEFAULTS, $settings );
	}

	public function set( string $key, mixed $value ): void {
		if ( self::isSecretSettingKey( $key ) ) {
			if ( is_string( $value ) ) {
				CredentialStore::set( self::suffixForKey( $key ), $value );
			}
			return;
		}

		$settings         = $this->all();
		$settings[ $key ] = $value;
		\update_option( self::OPTION_KEY, $settings, false );
	}

	public function delete( string $key ): void {
		if ( self::isSecretSettingKey( $key ) ) {
			CredentialStore::delete( self::suffixForKey( $key ) );
			return;
		}

		$settings = $this->all();
		unset( $settings[ $key ] );
		\update_option( self::OPTION_KEY, $settings, false );
	}

	// ─── Provider-aware accessors ──────────────────────────────────

	public function getDefaultProvider(): string {
		return (string) $this->get( 'ai_default_provider', 'openai' );
	}

	public function getDefaultModel(): string {
		return (string) $this->get( 'ai_default_model', 'gpt-4o' );
	}

	/**
	 * Resolve an API key for the given provider slug.
	 *
	 * Delegates to {@see CredentialResolver} which checks sources
	 * in priority order:
	 *
	 *  1. nvoos_content_graph_settings → ai_api_key_{provider}
	 *  2. Base plugin's Credential_Resolver (wp_mcp_ai_settings +
	 *     WP 7.0 Connector DB)
	 *  3. {PROVIDER}_API_KEY environment variable
	 *  4. {PROVIDER}_API_KEY PHP constant
	 *
	 * Local providers (ollama, lm_studio) have no key requirement.
	 *
	 * @return string|null  The API key string, or null if not configured.
	 */
	public function getApiKey( string $provider ): ?string {
		return CredentialResolver::getApiKey( $provider );
	}

	/**
	 * Check whether a provider has usable credentials.
	 *
	 * Returns true when an API key is found (via any source) or the
	 * provider uses 'none' authentication (Ollama, LM Studio).
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public function hasCredentials( string $provider ): bool {
		return CredentialResolver::hasCredentials( $provider );
	}

	/**
	 * Resolve a custom base URL for the given provider.
	 *
	 * Content Graph stores base URLs for a few providers (ollama, lmstudio,
	 * huggingface). All others use their provider's default endpoint.
	 *
	 * @return string|null  The base URL, or null to use the provider default.
	 */
	public function getApiBaseUrl( string $provider ): ?string {
		$urlMap = array(
			'ollama'      => 'ollama_base_url',
			'lm_studio'   => 'lmstudio_base_url',
			'huggingface' => 'huggingface_endpoint_url',
			'zai'         => 'zai_base_url',
		);

		$optionKey = $urlMap[ $provider ] ?? null;
		if ( null === $optionKey ) {
			return null;
		}

		$url = $this->get( $optionKey );

		return is_string( $url ) && '' !== $url ? \untrailingslashit( $url ) : null;
	}

	public function getRequestTimeout(): int {
		// Content Graph doesn't expose a configurable timeout — use a generous default.
		return 120;
	}

	/**
	 * Whether a settings key holds a secret that belongs in the
	 * encrypted CredentialStore.
	 *
	 * @param string $key Settings key.
	 * @return bool
	 */
	private static function isSecretSettingKey( string $key ): bool {
		return 0 === strpos( $key, 'ai_api_key_' ) || 'openai_api_key' === $key;
	}

	/**
	 * Convert a secret settings key to a provider settings suffix.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function suffixForKey( string $key ): string {
		return 'openai_api_key' === $key ? 'openai' : substr( $key, strlen( 'ai_api_key_' ) );
	}

	public function isEnabled( string $feature ): bool {
		// Content Graph has ai_chat_enabled; other features default to true.
		$flagMap = array(
			'chat' => 'ai_chat_enabled',
		);

		$optionKey = $flagMap[ $feature ] ?? null;
		if ( null !== $optionKey ) {
			return (bool) $this->get( $optionKey, true );
		}

		// Unknown features default to enabled.
		return true;
	}
}
