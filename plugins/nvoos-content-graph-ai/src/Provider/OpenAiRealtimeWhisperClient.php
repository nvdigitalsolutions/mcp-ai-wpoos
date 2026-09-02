<?php
/**
 * GPT-Realtime-Whisper Client for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-openai-realtime-whisper-client.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Session shape, latency handling, and error codes are
 * byte-identical.
 *
 * Decoupling (documented, additive): settings reads go through
 * `get_settings()` (base settings registry in monolith installs, the
 * content-graph settings store in standalone installs) and API-key reads
 * through `get_api_key()` (base settings/resolver in monolith, CG-AI
 * `CredentialResolver` standalone).
 *
 * @package NvoosContentGraphAi\Provider
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Provider;

use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GPT-Realtime-Whisper voice provider.
 *
 * @since 1.1.0
 */
class OpenAiRealtimeWhisperClient implements VoiceProviderInterface {

	const DEFAULT_MODEL          = 'gpt-realtime-whisper';
	const DEFAULT_LATENCY_DELAY  = 1.0;

	/**
	 * Retrieve the configured OpenAI API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			$key      = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

			if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
				$key = \WP_MCP_AI_Credential_Resolver::get_api_key( 'openai' ) ?? '';
			}

			return $key;
		}

		$resolved = CredentialResolver::getApiKey( 'openai' );

		return null !== $resolved ? $resolved : '';
	}

	/**
	 * Read the active settings map (per-install-mode seam).
	 *
	 * @return array
	 */
	protected static function get_settings() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return \WP_MCP_AI_Admin_Settings::get_settings();
		}

		return CoreBridge::instance()->settings->all();
	}

	/**
	 * Get the unique provider slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'openai_realtime_whisper';
	}

	/**
	 * Get the human-readable provider name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'OpenAI Realtime Whisper', 'nvoos-content-graph-ai' );
	}

	/**
	 * Get the transport mode.
	 *
	 * @return string
	 */
	public function get_transport_mode() {
		return 'realtime';
	}

	/**
	 * Check if this voice provider is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return false;
		}

		$settings = static::get_settings();

		$enabled = isset( $settings['voice_mode'] ) && 'realtime' === $settings['voice_mode']
			&& isset( $settings['voice_realtime_provider'] ) && 'openai_whisper' === $settings['voice_realtime_provider'];

		return (bool) apply_filters( 'wp_mcp_ai_openai_whisper_available', $enabled, ! empty( $api_key ) );
	}

	/**
	 * Get the default model.
	 *
	 * @return string
	 */
	public function get_default_model() {
		return self::DEFAULT_MODEL;
	}

	/**
	 * Get available voices (not applicable for transcription).
	 *
	 * @return array
	 */
	public function get_available_voices() {
		return array();
	}

	/**
	 * Get default voice (not applicable for transcription).
	 *
	 * @return string
	 */
	public function get_default_voice() {
		return '';
	}

	/**
	 * Get the default latency delay from settings or constant.
	 *
	 * @return float
	 */
	public function get_default_latency_delay() {
		$settings = static::get_settings();

		if ( isset( $settings['realtime_whisper_latency_delay'] ) && is_numeric( $settings['realtime_whisper_latency_delay'] ) ) {
			return (float) $settings['realtime_whisper_latency_delay'];
		}

		return self::DEFAULT_LATENCY_DELAY;
	}

	/**
	 * Create a transcription session.
	 *
	 * @param int   $assistant_id The assistant ID (informational).
	 * @param array $options      Optional overrides (latency_delay).
	 * @return array|\WP_Error
	 */
	public function create_session( $assistant_id, $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'wp_mcp_ai_whisper_no_key',
				__( 'OpenAI API key is not configured.', 'nvoos-content-graph-ai' )
			);
		}

		$latency_delay = isset( $options['latency_delay'] ) && is_numeric( $options['latency_delay'] )
			? (float) $options['latency_delay']
			: $this->get_default_latency_delay();

		return array(
			'type'           => 'openai_whisper',
			'transport_mode' => 'realtime',
			'model'          => self::DEFAULT_MODEL,
			'latency_delay'  => $latency_delay,
		);
	}
}
