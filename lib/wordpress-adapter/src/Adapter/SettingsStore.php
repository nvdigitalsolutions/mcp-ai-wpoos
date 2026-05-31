<?php
/**
 * WordPress adapter: SettingsStoreInterface implementation.
 *
 * Wraps WordPress option functions (get_option, update_option, delete_option)
 * behind the framework-agnostic SettingsStoreInterface.
 *
 * @package Oos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Oos\WordPress\Adapter;

use Oos\Core\Domain\Contract\SettingsStoreInterface;

class SettingsStore implements SettingsStoreInterface {

	/**
	 * The WordPress option key that holds all plugin settings.
	 */
	private const OPTION_KEY = 'wp_mcp_ai_settings';

	/**
	 * Default settings used when the option is not yet set.
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

	public function getDefaultProvider(): string {
		return (string) $this->get( 'default_provider', 'openai' );
	}

	public function getDefaultModel(): string {
		$provider = $this->getDefaultProvider();

		if ( 'gemini' === $provider ) {
			return (string) $this->get( 'default_gemini_model', 'gemini-2.0-flash' );
		}

		return (string) $this->get( 'default_model', 'gpt-4o-mini' );
	}

	public function getApiKey( string $provider ): ?string {
		$keyMap = array(
			'openai'             => 'openai_api_key',
			'openai_huggingface' => 'huggingface_api_key', // HuggingFace uses OpenAI-compatible endpoint
			'gemini'             => 'gemini_api_key',
			'anthropic'          => 'anthropic_api_key',
			'deepseek'           => 'deepseek_api_key',
			'ollama'             => null, // Ollama is local — no API key needed.
			'lm_studio'          => null, // LM Studio is local.
			'openrouter'         => 'openrouter_api_key',
			'kimi'               => 'kimi_api_key',
			'digitalocean'       => 'digitalocean_api_key',
			'nvidia_nim'         => 'nvidia_nim_api_key',
			'cloudflare'         => 'cloudflare_api_key',
		);

		$optionKey = $keyMap[ $provider ] ?? "{$provider}_api_key";

		if ( null === $optionKey ) {
			return ''; // Local providers return empty string, not null.
		}

		$key = $this->get( $optionKey );

		return is_string( $key ) && '' !== $key ? $key : null;
	}

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

		return is_string( $url ) && '' !== $url ? \untrailingslashit( $url ) : null;
	}

	public function getRequestTimeout(): int {
		return max( 5, (int) $this->get( 'request_timeout', 60 ) );
	}

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
