<?php
/**
 * Admin Settings Base for WP oOS.
 *
 * Handles core settings registration and management.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
	/**
	 * Core settings registration and management.
	 */
	class WP_MCP_AI_Admin_Settings_Base {
		const DEFAULT_MEMORY_MAX_FILE_BYTES  = 5242880; // 5 MB.
		const OPTION_NAME                    = 'wp_mcp_ai_settings';
		const SETTINGS_GROUP                 = 'wp_mcp_ai_settings_group';
		const PAGE_SLUG                      = 'wp-mcp-ai-settings';
		const SIMPLE_JWT_LOGIN_PLUGIN        = 'simple-jwt-login/simple-jwt-login.php';
		const GMAIL_OAUTH_SCOPE              = 'https://www.googleapis.com/auth/gmail.readonly';
		const GMAIL_OAUTH_AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
		const GMAIL_OAUTH_TOKEN_ENDPOINT     = 'https://oauth2.googleapis.com/token';
		const GMAIL_PROFILE_ENDPOINT         = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

		/**
		 * Cached settings for the current request.
		 *
		 * @var array|null
		 */
		private static $settings_cache = null;

		/**
		 * Sanitize settings before saving.
		 *
		 * NOTE: This method is kept for backward compatibility but should NOT be used
		 * as a WordPress sanitize_callback. The Settings Dashboard handles registration
		 * with subtab-aware sanitization. This method is only for legacy code or
		 * programmatic sanitization needs.
		 *
		 * @param array $settings Raw settings input.
		 * @return array Sanitized settings.
		 */
		public function sanitize_settings( $settings ) {
			if ( ! is_array( $settings ) ) {
				return array();
			}

			$sanitized = array();
			$defaults  = self::get_default_settings();
			$current   = get_option( self::OPTION_NAME, array() );

			foreach ( $defaults as $key => $default_value ) {
				// If key is not present in submitted settings, preserve existing value or use default.
				if ( ! isset( $settings[ $key ] ) ) {
					// For boolean defaults (checkboxes), missing key means false (unchecked).
					if ( is_bool( $default_value ) ) {
						$sanitized[ $key ] = false;
					} else {
						// For other types, preserve existing value or use default.
						$sanitized[ $key ] = isset( $current[ $key ] ) ? $current[ $key ] : $default_value;
					}
					continue;
				}

				$value = $settings[ $key ];

				// Sanitize based on key patterns and types.
				// Note: Using strpos() for PHP 7.4 compatibility (str_contains() requires PHP 8.0+).
				if ( false !== strpos( $key, '_api_key' ) || false !== strpos( $key, '_api_token' ) || false !== strpos( $key, '_secret' ) ) {
					$sanitized[ $key ] = sanitize_text_field( $value );
				} elseif ( false !== strpos( $key, '_email' ) ) {
					$sanitized[ $key ] = sanitize_email( $value );
				} elseif ( false !== strpos( $key, '_url' ) || false !== strpos( $key, '_endpoint' ) ) {
					$sanitized[ $key ] = esc_url_raw( $value );
				} elseif ( false !== strpos( $key, '_model' ) ) {
					$sanitized[ $key ] = sanitize_text_field( $value );
				} elseif ( is_bool( $default_value ) ) {
					$sanitized[ $key ] = ! empty( $value );
				} elseif ( is_int( $default_value ) ) {
					$sanitized[ $key ] = absint( $value );
				} elseif ( is_array( $default_value ) ) {
					$sanitized[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
				} else {
					$sanitized[ $key ] = sanitize_text_field( $value );
				}
			}

			// Mesh peer sites special handling.
			if ( isset( $settings['mesh_peer_sites'] ) ) {
				$sanitized['mesh_peer_sites'] = $this->sanitize_mesh_peer_sites( $settings['mesh_peer_sites'] );
			}

			// Provider priority list special handling.
			if ( isset( $settings['provider_priority_list'] ) ) {
				$sanitized['provider_priority_list'] = $this->sanitize_provider_priority_list( $settings['provider_priority_list'] );
			}

			// Generate mesh API key if needed.
			if ( isset( $settings['enable_mesh'] ) && ! empty( $settings['enable_mesh'] ) ) {
				if ( empty( $sanitized['mesh_inbound_api_key'] ) ) {
					$sanitized['mesh_inbound_api_key'] = $this->generate_mesh_api_key();
				}
			}

			/**
			 * Filter sanitized settings before saving.
			 *
			 * Allows third-party plugins and extensions to modify or inject settings.
			 *
			 * @since 1.0.0
			 *
			 * @param array $sanitized Sanitized settings array.
			 * @param array $settings  Raw input settings array.
			 */
			$sanitized = apply_filters( 'wp_mcp_ai_admin_settings_sanitize', $sanitized, $settings );

			// Clear settings cache.
			self::reset_settings_cache();

			return $sanitized;
		}

		/**
		 * Generate a secure random API key for mesh networking.
		 *
		 * @return string
		 */
		private function generate_mesh_api_key() {
			return 'mesh_' . bin2hex( random_bytes( 32 ) );
		}

		/**
		 * Sanitize mesh peer sites array.
		 *
		 * @param array $peer_sites Raw peer sites data.
		 * @return array Sanitized peer sites.
		 */
		private function sanitize_mesh_peer_sites( $peer_sites ) {
			if ( ! is_array( $peer_sites ) ) {
				return array();
			}

			$sanitized = array();

			foreach ( $peer_sites as $peer ) {
				if ( ! is_array( $peer ) ) {
					continue;
				}

				$sanitized_peer = array(
					'url'     => isset( $peer['url'] ) ? esc_url_raw( $peer['url'] ) : '',
					'api_key' => isset( $peer['api_key'] ) ? sanitize_text_field( $peer['api_key'] ) : '',
					'name'    => isset( $peer['name'] ) ? sanitize_text_field( $peer['name'] ) : '',
					'enabled' => ! empty( $peer['enabled'] ),
				);

				if ( ! empty( $sanitized_peer['url'] ) && ! empty( $sanitized_peer['api_key'] ) ) {
					$sanitized[] = $sanitized_peer;
				}
			}

			return $sanitized;
		}

		/**
		 * Sanitize provider priority list.
		 *
		 * Ensures the list only contains valid provider keys and removes duplicates.
		 *
		 * @param mixed $priority_list The provider priority list to sanitize.
		 * @return array Sanitized provider priority list.
		 */
		private function sanitize_provider_priority_list( $priority_list ) {
			if ( ! is_array( $priority_list ) ) {
				return array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
			}

			$available_providers = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
			$sanitized           = array();
			$seen                = array();

			foreach ( $priority_list as $provider ) {
				$provider = sanitize_key( $provider );

				// Only include valid providers that haven't been added yet.
				if ( in_array( $provider, $available_providers, true ) && ! in_array( $provider, $seen, true ) ) {
					$sanitized[] = $provider;
					$seen[]      = $provider;
				}
			}

			// Ensure all available providers are in the list (add missing ones at the end).
			foreach ( $available_providers as $provider ) {
				if ( ! in_array( $provider, $sanitized, true ) ) {
					$sanitized[] = $provider;
				}
			}

			return $sanitized;
		}

		/**
		 * Get all settings with defaults.
		 *
		 * @return array
		 */
		public static function get_settings() {
			if ( null !== self::$settings_cache ) {
				return self::$settings_cache;
			}

			$defaults = self::get_default_settings();
			$saved    = get_option( self::OPTION_NAME, array() );

			if ( ! is_array( $saved ) ) {
				$saved = array();
			}

			$settings = wp_parse_args( $saved, $defaults );

			// Ensure chat_colors is properly merged.
			if ( ! isset( $settings['chat_colors'] ) || ! is_array( $settings['chat_colors'] ) ) {
				$settings['chat_colors'] = self::get_default_chat_colors();
			} else {
				$settings['chat_colors'] = array_merge( self::get_default_chat_colors(), $settings['chat_colors'] );
			}

			self::$settings_cache = $settings;

			return $settings;
		}

		/**
		 * Reset the settings cache.
		 */
		public static function reset_settings_cache() {
			self::$settings_cache = null;
		}

		/**
		 * Returns the option defaults.
		 *
		 * @return array
		 */
		public static function get_default_settings() {
			return array(
				'openai_api_key'                       => '',
				'gemini_api_key'                       => '',
				'ollama_endpoint_url'                  => '',
				'ollama_model'                         => '',
				'lm_studio_endpoint_url'               => '',
				'lm_studio_model'                      => '',
				'default_assistant'                    => 0,
				'enable_logging'                       => false,
				'default_model'                        => 'gpt-4.1',
				'default_gemini_model'                 => 'gemini-2.5-flash',
				'default_provider'                     => 'openai',
				'provider_priority_list'               => array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ),
				'web_search_provider'                  => 'duckduckgo',
				'brave_search_api_key'                 => '',
				'google_maps_api_key'                  => '',
				'ita_tariff_api_key'                   => '',
				'request_timeout'                      => 30,
				'memory_max_file_bytes'                => self::DEFAULT_MEMORY_MAX_FILE_BYTES,
				'auth0_domain'                         => '',
				'auth0_audience'                       => '',
				'auth0_required_scope'                 => '',
				'enable_auth0_github_bridge'           => false,
				'auth0_management_client_id'           => '',
				'auth0_management_client_secret'       => '',
				'enable_wordpress_gravatar_bridge'     => false,
				'wordpress_gravatar_userinfo_endpoint' => '',
				'enable_simple_jwt_login'              => false,
				'delete_on_uninstall'                  => false,
				'crawl4ai_base_url'                    => '',
				'crawl4ai_api_key'                     => '',
				'cloudflare_api_token'                 => '',
				'cloudflare_zone_id'                   => '',
				'enable_varnish_purge'                 => false,
				'cloudways_email'                      => '',
				'cloudways_api_key'                    => '',
				'cloudways_server_id'                  => '',
				'cloudways_app_id'                     => '',
				// RabbitMQ settings (Cloudways integration).
				'rabbitmq_enabled'                     => false,
				'rabbitmq_host'                        => 'localhost',
				'rabbitmq_port'                        => 5672,
				'rabbitmq_username'                    => 'guest',
				'rabbitmq_password'                    => '',
				'rabbitmq_vhost'                       => '/',
				'rabbitmq_queue_prefix'                => 'wp_mcp_ai',
				'rabbitmq_priority_queues'             => true,
				'rabbitmq_parallel_execution'          => false,
				'rabbitmq_worker_timeout'              => 300,
				'rabbitmq_max_retries'                 => 3,
				'rabbitmq_retry_delay'                 => 1000,
				'rabbitmq_dead_letter_enabled'         => true,
				'rabbitmq_dead_letter_ttl'             => 86400,
				'mailjet_api_key'                      => '',
				'mailjet_api_secret'                   => '',
				'mailjet_from_email'                   => '',
				'mailjet_from_name'                    => '',
				'removebg_api_key'                     => '',
				'quickbooks_company_id'                => '',
				'quickbooks_api_key'                   => '',
				'quickbooks_client_id'                 => '',
				'quickbooks_client_secret'             => '',
				'google_analytics_property_id'         => '',
				'google_analytics_credentials'         => '',
				'google_analytics_credentials_json'    => '',
				'meta_access_token'                    => '',
				'meta_app_id'                          => '',
				'meta_app_secret'                      => '',
				'meta_business_account_id'             => '',
				'tiktok_access_token'                  => '',
				'tiktok_client_key'                    => '',
				'tiktok_client_secret'                 => '',
				'gmail_client_id'                      => '',
				'gmail_client_secret'                  => '',
				'gmail_refresh_token'                  => '',
				'gmail_user_email'                     => '',
				'github_client_id'                     => '',
				'github_client_secret'                 => '',
				'github_access_token'                  => '',
				'github_username'                      => '',
				'group_email_capability'               => 'publish_posts',
				'group_email_max_recipients'           => 100,
				'openai_image_model'                   => 'gpt-image-1.5',
				'openai_image_size'                    => '1024x1024',
				'openai_image_quality'                 => 'medium',
				'openai_image_response_format'         => 'b64_json',
				'openai_speech_model'                  => 'gpt-4o-mini-tts',
				'openai_speech_voice'                  => 'alloy',
				'openai_speech_format'                 => 'mp3',
				'openai_transcribe_model'              => 'gpt-4o-mini-transcribe',
				'openai_transcribe_response_format'    => 'verbose_json',
				'openai_transcribe_language'           => '',
				'openai_transcribe_temperature'        => '',
				'openai_embedding_model'               => 'text-embedding-3-small',
				'gemini_image_model'                   => 'gemini-2.5-flash-image',
				'gemini_image_aspect_ratio'            => '1:1',
				'gemini_image_mime_type'               => 'image/png',
				'max_history_messages'                 => 8,
				'chat_colors'                          => self::get_default_chat_colors(),
				'allowed_image_mimes'                  => array(),
				'allowed_file_mimes'                   => array(),
				'rest_enable_assistant_list'           => true,
				'rest_enable_assistant_create'         => false,
				'rest_enable_assistant_delete'         => false,
				'sse_enable_post_method'               => false,
				'enable_high_token_model_switch'       => true,
				'high_token_fallback_model'            => 'gemini-2.5-flash',
				'enable_mesh'                          => false,
				'mesh_inbound_api_key'                 => '',
				'mesh_peer_sites'                      => array(),
				'enable_federation'                    => false,
				'enable_federation_directory'          => false,
				'federation_regions'                   => 'global',
				'federation_data_tags'                 => '',
				'federation_qps'                       => 5,
				'federation_burst'                     => 10,
				'federation_jwks_keys'                 => array(),
				'federation_price_hints'               => array(),
				// Orchestration Layer settings - defaults match "Balanced" preset.
				'orchestration_preset'                 => 'custom',
				'enable_budget_management'             => true,
				'enable_predictive_optimization'       => true,
				'enable_capability_gating'             => true,
				'enable_cron_orchestration'            => true,
				'enable_auto_async_execution'          => true,
				'cron_job_retention_period'            => '24',
				'memory_warning_threshold'             => 70,
				'memory_critical_threshold'            => 85,
				'error_rate_warning_threshold'         => 5,
				'error_rate_critical_threshold'        => 10,
				'high_priority_budget'                 => 100,
				'medium_priority_budget'               => 75,
				'low_priority_budget'                  => 50,
				'critical_health_reduction'            => 50,
				'warning_health_reduction'             => 75,
				'low_tier_max_tokens'                  => 2000,
				'medium_tier_max_tokens'               => 8000,
				'high_tier_max_tokens'                 => 32000,
				'prediction_confidence_threshold'      => 40,
				'prediction_safety_buffer'             => 15,
			);
		}

		/**
		 * Get default chat colors configuration.
		 *
		 * @return array
		 */
		public static function get_default_chat_colors() {
			$color_definitions = self::get_chat_color_definitions();
			$defaults          = array();

			foreach ( $color_definitions as $key => $definition ) {
				$defaults[ $key ] = $definition['default'];
			}

			return $defaults;
		}

		/**
		 * Returns metadata about configurable chat colors.
		 *
		 * @return array
		 */
		public static function get_chat_color_definitions() {
			// Delegate to main admin settings class if it exists and has the full definitions.
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'get_chat_color_definitions' ) ) {
				return WP_MCP_AI_Admin_Settings::get_chat_color_definitions();
			}

			// Fallback to basic definitions (this should not happen in practice).
			return array(
				'container-border'     => array(
					'label'       => __( 'Container border', 'wp-mcp-ai' ),
					'group'       => 'container',
					'default'     => '#d5d5d5',
					'format'      => 'hex',
					'description' => __( 'Border surrounding the chat interface.', 'wp-mcp-ai' ),
				),
				'container-background' => array(
					'label'       => __( 'Container background', 'wp-mcp-ai' ),
					'group'       => 'container',
					'default'     => '#fff',
					'format'      => 'hex',
					'description' => __( 'Main background color for the chat container.', 'wp-mcp-ai' ),
				),
			);
		}

		/**
		 * Apply memory max file bytes filter.
		 *
		 * @param int $max_bytes Current max bytes.
		 * @param int $attachment_id Attachment ID.
		 * @return int Filtered max bytes.
		 */
		public function filter_memory_max_file_bytes( $max_bytes, $attachment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			$settings = self::get_settings();
			if ( isset( $settings['memory_max_file_bytes'] ) && $settings['memory_max_file_bytes'] > 0 ) {
				return absint( $settings['memory_max_file_bytes'] );
			}
			return $max_bytes;
		}
	}
}
