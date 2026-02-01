<?php
/**
 * Simplified Settings Saver for NV oOS
 *
 * Provides a streamlined, optimized approach to saving settings
 * with better performance and less complexity than the full
 * section-based sanitization system.
 *
 * This can be used as an alternative to the modular section system
 * when performance is critical or when dealing with simple flat
 * settings structures.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Simple_Settings_Saver' ) ) {
	/**
	 * Simplified settings saver with optimized sanitization.
	 */
	class WP_MCP_AI_Simple_Settings_Saver {
		/**
		 * Field type definitions for sanitization.
		 *
		 * Maps field keys to their types for automatic sanitization.
		 *
		 * @var array
		 */
		private static $field_types = array();

		/**
		 * Initialize field type definitions.
		 *
		 * This method should be called once during plugin initialization
		 * to register field types for automatic sanitization.
		 */
		public static function init_field_types() {
			if ( ! empty( self::$field_types ) ) {
				return; // Already initialized.
			}

			self::$field_types = array(
				// Boolean fields (checkboxes).
				'enable_logging'                     => 'checkbox',
				'enable_extended_logging'            => 'checkbox',
				'enable_agentic_loop_logging'        => 'checkbox',
				'enable_api_logging'                 => 'checkbox',
				'enable_tool_execution_logging'      => 'checkbox',
				'enable_chat_interaction_logging'    => 'checkbox',
				'delete_on_uninstall'                => 'checkbox',
				'enable_openai'                      => 'checkbox',
				'enable_huggingface'                 => 'checkbox',
				'enable_high_token_model_switch'     => 'checkbox',
				'enable_mesh'                        => 'checkbox',
				'enable_federation'                  => 'checkbox',
				'enable_federation_directory'        => 'checkbox',
				'enable_budget_management'           => 'checkbox',
				'enable_predictive_optimization'     => 'checkbox',
				'enable_capability_gating'           => 'checkbox',
				'enable_cron_orchestration'          => 'checkbox',
				'enable_auto_async_execution'        => 'checkbox',
				'rest_enable_assistant_list'         => 'checkbox',
				'rest_enable_assistant_create'       => 'checkbox',
				'rest_enable_assistant_delete'       => 'checkbox',
				'sse_enable_post_method'             => 'checkbox',
				'enable_varnish_purge'               => 'checkbox',
				'enable_huggingface_datasets'        => 'checkbox',
				'rabbitmq_enabled'                   => 'checkbox',
				'rabbitmq_priority_queues'           => 'checkbox',
				'rabbitmq_parallel_execution'        => 'checkbox',
				'rabbitmq_dead_letter_enabled'       => 'checkbox',

				// Password/API key fields.
				'openai_api_key'                     => 'password',
				'gemini_api_key'                     => 'password',
				'anthropic_api_key'                  => 'password',
				'huggingface_api_key'                => 'password',
				'cloudflare_api_token'               => 'password',
				'brave_search_api_key'               => 'password',
				'mubert_api_key'                     => 'password',
				'crawl4ai_api_key'                   => 'password',
				'cloudways_api_key'                  => 'password',
				'mailjet_api_key'                    => 'password',
				'mailjet_api_secret'                 => 'password',
				'mailjet_client_secret'              => 'password',
				'removebg_api_key'                   => 'password',
				'quickbooks_api_key'                 => 'password',
				'quickbooks_client_secret'           => 'password',
				'github_client_secret'               => 'password',
				'gmail_client_secret'                => 'password',
				'rabbitmq_password'                  => 'password',
				'meta_app_secret'                    => 'password',
				'meta_access_token'                  => 'password',
				'tiktok_access_token'                => 'password',
				'tiktok_client_secret'               => 'password',
				'google_analytics_credentials'       => 'password',
				'mesh_inbound_api_key'               => 'password',

				// URL fields.
				'ollama_endpoint_url'                => 'url',
				'lm_studio_endpoint_url'             => 'url',
				'huggingface_endpoint_url'           => 'url',
				'crawl4ai_base_url'                  => 'url',

				// Email fields.
				'cloudways_email'                    => 'email',
				'gmail_user_email'                   => 'email',
				'mailjet_from_email'                 => 'email',

				// Number fields.
				'default_assistant'                  => 'number',
				'request_timeout'                    => 'number',
				'memory_max_file_bytes'              => 'number',
				'cloudflare_image_width'             => 'number',
				'cloudflare_image_height'            => 'number',
				'cloudflare_image_num_steps'         => 'number',
				'max_history_messages'               => 'number',
				'high_priority_budget'               => 'number',
				'medium_priority_budget'             => 'number',
				'low_priority_budget'                => 'number',
				'critical_health_reduction'          => 'number',
				'warning_health_reduction'           => 'number',
				'low_tier_max_tokens'                => 'number',
				'medium_tier_max_tokens'             => 'number',
				'high_tier_max_tokens'               => 'number',
				'prediction_confidence_threshold'    => 'number',
				'prediction_safety_buffer'           => 'number',
				'memory_warning_threshold'           => 'number',
				'memory_critical_threshold'          => 'number',
				'error_rate_warning_threshold'       => 'number',
				'error_rate_critical_threshold'      => 'number',
				'huggingface_datasets_cache_ttl'     => 'number',
				'huggingface_datasets_default_limit' => 'number',
				'rabbitmq_port'                      => 'number',
				'rabbitmq_worker_timeout'            => 'number',
				'rabbitmq_max_retries'               => 'number',
				'rabbitmq_retry_delay'               => 'number',
				'rabbitmq_dead_letter_ttl'           => 'number',
				'group_email_max_recipients'         => 'number',

				// Float fields.
				'cloudflare_image_guidance'          => 'float',

				// All other fields default to text sanitization.
			);

			/**
			 * Filter the field type definitions.
			 *
			 * Allows plugins and themes to add custom field types.
			 *
			 * @param array $field_types Field type definitions.
			 */
			self::$field_types = apply_filters( 'wp_mcp_ai_simple_saver_field_types', self::$field_types );
		}

		/**
		 * Save settings with simplified sanitization.
		 *
		 * This method provides a streamlined alternative to the full
		 * section-based sanitization system. It's faster and simpler
		 * but less flexible.
		 *
		 * @param array $posted_data    Raw posted data from form.
		 * @param bool  $merge_existing Whether to merge with existing settings (default: true).
		 * @return array Sanitized and saved settings.
		 */
		public static function save_settings( $posted_data, $merge_existing = true ) {
			// Initialize field types if not already done.
			if ( empty( self::$field_types ) ) {
				self::init_field_types();
			}

			$sanitized = array();

			// Get existing settings if merging.
			$existing = $merge_existing ? get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() ) : array();

			// Process each posted field.
			foreach ( $posted_data as $key => $value ) {
				$field_type = isset( self::$field_types[ $key ] ) ? self::$field_types[ $key ] : 'text';

				$sanitized[ $key ] = self::sanitize_field( $value, $field_type, $key, $existing );
			}

			// Handle checkboxes that weren't posted (means unchecked).
			foreach ( self::$field_types as $key => $type ) {
				if ( 'checkbox' === $type && ! isset( $posted_data[ $key ] ) ) {
					$sanitized[ $key ] = false;
				}
			}

			// Merge with existing settings if requested.
			if ( $merge_existing ) {
				$sanitized = array_merge( $existing, $sanitized );
			}

			// Save to database.
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized );

			// Clear settings cache.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();

			return $sanitized;
		}

		/**
		 * Sanitize a single field based on its type.
		 *
		 * @param mixed  $value    Field value.
		 * @param string $type     Field type.
		 * @param string $key      Field key.
		 * @param array  $existing Existing settings (for password field preservation).
		 * @return mixed Sanitized value.
		 */
		private static function sanitize_field( $value, $type, $key, $existing ) {
			// Handle array values - prevent "Array to string conversion" errors.
			if ( is_array( $value ) ) {
				// If the field type is specifically 'array', sanitize as array.
				if ( 'array' === $type ) {
					return array_map( 'sanitize_text_field', $value );
				}
				// For non-array field types that receive arrays, serialize or convert to JSON.
				// This preserves the data rather than causing a fatal error.
				return wp_json_encode( $value );
			}

			switch ( $type ) {
				case 'checkbox':
					return ! empty( $value );

				case 'password':
					// Don't overwrite existing password if new value is empty.
					$trimmed = trim( sanitize_text_field( $value ) );
					if ( '' === $trimmed && isset( $existing[ $key ] ) ) {
						return $existing[ $key ]; // Preserve existing.
					}
					return $trimmed;

				case 'url':
					return '' === $value ? '' : esc_url_raw( $value );

				case 'email':
					return sanitize_email( $value );

				case 'number':
					return '' === $value ? '' : absint( $value );

				case 'float':
					return '' === $value ? '' : (float) $value;

				case 'textarea':
					return sanitize_textarea_field( $value );

				case 'array':
					// This case is handled above for actual arrays.
					// If we get here with non-array value, return empty array.
					return array();

				case 'text':
				default:
					return sanitize_text_field( $value );
			}
		}

		/**
		 * Get field type for a given key.
		 *
		 * @param string $key Field key.
		 * @return string Field type.
		 */
		public static function get_field_type( $key ) {
			if ( empty( self::$field_types ) ) {
				self::init_field_types();
			}

			return isset( self::$field_types[ $key ] ) ? self::$field_types[ $key ] : 'text';
		}

		/**
		 * Batch update multiple settings without full form processing.
		 *
		 * Useful for programmatic updates or migrations.
		 *
		 * @param array $updates Associative array of key => value pairs to update.
		 * @return bool True on success, false on failure.
		 */
		public static function batch_update( $updates ) {
			$existing = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			foreach ( $updates as $key => $value ) {
				$type             = self::get_field_type( $key );
				$existing[ $key ] = self::sanitize_field( $value, $type, $key, $existing );
			}

			$result = update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $existing );

			if ( $result ) {
				WP_MCP_AI_Admin_Settings::reset_settings_cache();
			}

			return $result;
		}
	}
}
