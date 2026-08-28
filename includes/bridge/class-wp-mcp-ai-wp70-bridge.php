<?php
/**
 * WP 7.0 Connectors API Bridge — gate and connector registration.
 *
 * Detects WordPress 7.0+ AI infrastructure (wp_supports_ai, wp_connectors_init,
 * wp_get_connector) and provides:
 *
 *  - A single availability gate (is_available).
 *  - Connector registration for all NV oOS providers on the core
 *    Settings → Connectors screen.
 *  - Metadata overrides so core's built-in Anthropic / Google / OpenAI
 *    connectors reflect NV oOS management.
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_WP70_Bridge' ) ) :

	/**
	 * WP 7.0 AI infrastructure bridge.
	 */
	final class WP_MCP_AI_WP70_Bridge {

		/**
		 * Cached availability result.
		 *
		 * @var bool|null
		 */
		private static $available = null;

		/**
		 * NV oOS providers that should appear as connectors on the core
		 * Settings → Connectors screen.
		 *
		 * Keys are connector IDs. Each value contains:
		 *  - name:           display name
		 *  - description:    one-liner shown on the connector card
		 *  - authentication: method + optional credentials_url + optional setting_name
		 *
		 * Built-in providers (anthropic, google, openai) are handled separately
		 * via metadata override; they are NOT duplicated here.
		 *
		 * @since 1.8.0
		 * @var array<string, array>
		 */
		const CUSTOM_CONNECTORS = array(
			'openrouter'   => array(
				'name'           => 'OpenRouter',
				'description'    => 'Unified API gateway for 200+ models (NV oOS).',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://openrouter.ai/keys',
				),
			),
			'deepseek'     => array(
				'name'           => 'DeepSeek',
				'description'    => 'DeepSeek AI models (NV oOS).',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://platform.deepseek.com/api_keys',
				),
			),
			'nvidia'       => array(
				'name'           => 'NVIDIA NIM',
				'description'    => 'NVIDIA NIM inference endpoints (NV oOS).',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://build.nvidia.com/',
				),
			),
			'huggingface'  => array(
				'name'           => 'Hugging Face',
				'description'    => 'Hugging Face inference API (NV oOS).',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://huggingface.co/settings/tokens',
				),
			),
			'kimi'         => array(
				'name'           => 'Kimi (Moonshot AI)',
				'description'    => 'Moonshot AI inference (NV oOS).',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://platform.moonshot.ai/console/api-keys',
				),
			),
			'baseten'      => array(
				'name'           => 'Baseten',
				'description'    => 'Baseten serverless inference (NV oOS).',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://app.baseten.co/settings/api-keys',
				),
			),
			'digitalocean' => array(
				'name'           => 'DigitalOcean',
				'description'    => 'DigitalOcean Serverless Inference (NV oOS).',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://cloud.digitalocean.com/account/api/tokens',
				),
			),
			'cloudflare'   => array(
				'name'           => 'Cloudflare Workers AI',
				'description'    => 'Cloudflare Workers AI inference (NV oOS).',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://dash.cloudflare.com/profile/api-tokens',
				),
			),
			'ollama'       => array(
				'name'           => 'Ollama',
				'description'    => 'Local AI inference via Ollama — no cloud API key needed (NV oOS).',
				'authentication' => array(
					'method' => 'none',
				),
			),
			'lm_studio'    => array(
				'name'           => 'LM Studio',
				'description'    => 'Local AI inference via LM Studio — no cloud API key needed (NV oOS).',
				'authentication' => array(
					'method' => 'none',
				),
			),
			'embedded'     => array(
				'name'           => 'Embedded LLM',
				'description'    => 'In-browser GGUF inference (NV oOS Pro).',
				'authentication' => array(
					'method' => 'none',
				),
			),
		);

		/**
		 * Labels applied to the three built-in WP 7.0 connectors when NV oOS
		 * is managing them.
		 *
		 * @since 1.8.0
		 * @var array<string, string>
		 */
		const BUILTIN_LABELS = array(
			'anthropic' => 'Anthropic (Claude) — NV oOS',
			'google'    => 'Google Gemini — NV oOS',
			'openai'    => 'OpenAI — NV oOS',
		);

		/**
		 * Whether the WP 7.0 AI infrastructure is available and enabled.
		 *
		 * Checks three conditions:
		 *  1. wp_supports_ai() exists and returns true.
		 *  2. wp_connectors_init action is available (WP ≥ 7.0).
		 *  3. The wp_mcp_ai_use_wp70_bridge filter has not opted out.
		 *
		 * Result is cached for the lifetime of the request.
		 *
		 * @since 1.8.0
		 * @return bool
		 */
		public static function is_available(): bool {
			if ( null !== self::$available ) {
				return self::$available;
			}

			if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
				self::$available = false;
				return false;
			}

			if ( ! has_action( 'wp_connectors_init' ) && ! function_exists( 'wp_get_connector' ) ) {
				self::$available = false;
				return false;
			}

			/**
			 * Filter: allow site owners or other plugins to opt out of the
			 * NV oOS ↔ WP 7.0 Connectors bridge.
			 *
			 * @since 1.8.0
			 * @param bool $enabled Whether the bridge is enabled. Default true.
			 */
			self::$available = apply_filters( 'wp_mcp_ai_use_wp70_bridge', true );

			return self::$available;
		}

		/**
		 * Bootstrap the bridge — register hooks.
		 *
		 * Called from bridge/bootstrap.php during plugins_loaded or init.
		 *
		 * @since 1.8.0
		 */
		public static function bootstrap(): void {
			if ( ! self::is_available() ) {
				return;
			}

			add_action( 'wp_connectors_init', array( __CLASS__, 'on_wp_connectors_init' ), 20 );
		}

		/**
		 * Wp_connectors_init callback — register NV oOS custom connectors and
		 * override built-in connector metadata.
		 *
		 * Runs at priority 20 so community / official plugins have already
		 * registered their connectors by the time we override metadata.
		 *
		 * @since 1.8.0
		 * @param WP_Connector_Registry $registry The connector registry.
		 */
		public static function on_wp_connectors_init( WP_Connector_Registry $registry ): void {
			self::override_builtins( $registry );
			self::register_custom_connectors( $registry );
		}

		/**
		 * Override metadata on the three built-in connectors so the
		 * Settings → Connectors screen reflects NV oOS management.
		 *
		 * Uses the documented unregister → modify → register pattern.
		 *
		 * @since 1.8.0
		 * @param WP_Connector_Registry $registry The connector registry.
		 */
		private static function override_builtins( WP_Connector_Registry $registry ): void {
			foreach ( self::BUILTIN_LABELS as $id => $label ) {
				if ( ! $registry->is_registered( $id ) ) {
					continue;
				}

				$connector = $registry->unregister( $id );
				if ( ! is_array( $connector ) ) {
					continue;
				}

				$connector['name']        = $label;
				$connector['description'] = ( $connector['description'] ?? '' )
					. ' (Managed by NV oOS)';

				// Link back to NV oOS so the Connectors screen shows
				// "Installed & Active" for our plugin.
				$connector['plugin'] = array(
					'file' => 'mcp-ai-wpoos/mcp-ai-wpoos.php',
				);

				$registry->register( $id, $connector );
			}
		}

		/**
		 * Register NV oOS-exclusive providers as custom connectors.
		 *
		 * Skips any ID that is already registered (e.g., by a community
		 * connector plugin).
		 *
		 * @since 1.8.0
		 * @param WP_Connector_Registry $registry The connector registry.
		 */
		private static function register_custom_connectors( WP_Connector_Registry $registry ): void {
			foreach ( self::CUSTOM_CONNECTORS as $id => $args ) {
				if ( $registry->is_registered( $id ) ) {
					continue;
				}

				$connector = array_merge(
					array(
						'name'        => $id,
						'description' => '',
						'type'        => 'ai_provider',
						'plugin'      => array(
							'file' => 'mcp-ai-wpoos/mcp-ai-wpoos.php',
						),
					),
					$args
				);

				$registry->register( $id, $connector );
			}
		}

		/**
		 * Get the connector database setting name for a given provider.
		 *
		 * Returns the setting_name from the connector's authentication
		 * metadata, or the auto-generated fallback:
		 *   connectors_ai_{$provider_id}_api_key
		 *
		 * @since 1.8.0
		 * @param string $provider_id Provider slug (e.g., 'openai', 'deepseek').
		 * @return string Option name for the API key database setting.
		 */
		public static function get_connector_setting_name( string $provider_id ): string {
			if ( function_exists( 'wp_get_connector' ) ) {
				// The registry emits a doing-it-wrong notice for unknown IDs, so
				// check registration first (the connector may not be registered
				// yet, e.g. before wp_connectors_init has run or for providers
				// this bridge does not manage).
				$registry = class_exists( 'WP_Connector_Registry' ) ? WP_Connector_Registry::get_instance() : null;

				if ( $registry instanceof WP_Connector_Registry && $registry->is_registered( $provider_id ) ) {
					$connector = wp_get_connector( $provider_id );
					if ( is_array( $connector ) && ! empty( $connector['authentication']['setting_name'] ) ) {
						return $connector['authentication']['setting_name'];
					}
				}
			}

			// Auto-generated fallback per the WP 7.0 dev note:
			// connectors_{type}_{id}_api_key.
			return 'connectors_ai_' . $provider_id . '_api_key';
		}
	}

endif;
