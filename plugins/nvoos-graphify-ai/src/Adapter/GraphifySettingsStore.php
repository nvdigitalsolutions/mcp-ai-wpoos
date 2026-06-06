<?php
/**
 * Graphify SettingsStore — maps nvoos_graphify_settings to
 * the nvoos/core SettingsStoreInterface.
 *
 * The existing Nvoos\WordPress\Adapter\SettingsStore (v1) targets
 * wp_mcp_ai_settings for the base/pro plugin. This v2 adapter targets
 * nvoos_graphify_settings for the nvoos-graphify ecosystem.
 *
 * Key translation:
 *   Interface method           → Graphify option key
 *   ───────────────────────────────────────────────────
 *   getDefaultProvider()       → ai_default_provider
 *   getDefaultModel()          → ai_default_model
 *   getApiKey('openai')        → ai_api_key_openai
 *   getApiBaseUrl('ollama')    → ollama_base_url
 *   getRequestTimeout()        → (always 120 — not configurable in Graphify)
 *
 * @package NvoosGraphifyAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosGraphifyAi\Adapter;

use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class GraphifySettingsStore implements SettingsStoreInterface {

	/**
	 * The WordPress option key used by nvoos-graphify.
	 */
	private const OPTION_KEY = 'nvoos_graphify_settings';

	/**
	 * Default values seeded by Plugin::addDefaultSettings().
	 *
	 * These mirror the defaults registered via the
	 * nvoos_graphify/default_settings filter in Plugin.php.
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

		return \array_merge( self::DEFAULTS, $settings );
	}

	public function set( string $key, mixed $value ): void {
		$settings         = $this->all();
		$settings[ $key ] = $value;
		\update_option( self::OPTION_KEY, $settings, false );
	}

	public function delete( string $key ): void {
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
	 * Graphify stores keys with the `ai_api_key_` prefix.
	 * Local providers (ollama, lm_studio) have no key requirement.
	 *
	 * @return string|null  The API key string, or null if not configured.
	 */
	public function getApiKey( string $provider ): ?string {
		// Local providers — no API key needed.
		$localProviders = array( 'ollama', 'lm_studio' );
		if ( in_array( $provider, $localProviders, true ) ) {
			return '';
		}

		$key = $this->get( "ai_api_key_{$provider}" );

		return is_string( $key ) && '' !== $key ? $key : null;
	}

	/**
	 * Resolve a custom base URL for the given provider.
	 *
	 * Graphify stores base URLs for a few providers (ollama, lmstudio,
	 * huggingface). All others use their provider's default endpoint.
	 *
	 * @return string|null  The base URL, or null to use the provider default.
	 */
	public function getApiBaseUrl( string $provider ): ?string {
		$urlMap = array(
			'ollama'      => 'ollama_base_url',
			'lm_studio'   => 'lmstudio_base_url',
			'huggingface' => 'huggingface_endpoint_url',
		);

		$optionKey = $urlMap[ $provider ] ?? null;
		if ( null === $optionKey ) {
			return null;
		}

		$url = $this->get( $optionKey );

		return is_string( $url ) && '' !== $url ? \untrailingslashit( $url ) : null;
	}

	public function getRequestTimeout(): int {
		// Graphify doesn't expose a configurable timeout — use a generous default.
		return 120;
	}

	public function isEnabled( string $feature ): bool {
		// Graphify has ai_chat_enabled; other features default to true.
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
