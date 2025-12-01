<?php
/**
 * Custom Filters Applicator
 *
 * Applies saved custom filter values from settings to WordPress filters.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Custom_Filters_Applicator' ) ) {
	/**
	 * Applies custom filter values from settings.
	 */
	class WP_MCP_AI_Custom_Filters_Applicator {
		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->register_filter_hooks();
		}

		/**
		 * Register WordPress filter hooks to apply saved custom filter values.
		 */
		private function register_filter_hooks() {
			// Model Selection Filters.
			add_filter( 'wp_mcp_ai_default_light_model', array( $this, 'apply_default_light_model' ), 5 );
			add_filter( 'wp_mcp_ai_default_advanced_model', array( $this, 'apply_default_advanced_model' ), 5 );

			// Resource Management Filters.
			add_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'apply_max_agentic_iterations' ), 5, 2 );
			add_filter( 'wp_mcp_ai_resource_max_tokens', array( $this, 'apply_resource_max_tokens' ), 5, 2 );
			add_filter( 'wp_mcp_ai_resource_request_timeout', array( $this, 'apply_resource_request_timeout' ), 5, 3 );

			// Retry and Error Handling.
			add_filter( 'wp_mcp_ai_max_retries', array( $this, 'apply_max_retries' ), 5, 2 );
			add_filter( 'wp_mcp_ai_max_retry_delay', array( $this, 'apply_max_retry_delay' ), 5, 2 );

			// File and Attachment Limits.
			add_filter( 'wp_mcp_ai_max_attachment_bytes', array( $this, 'apply_max_attachment_bytes' ), 5, 2 );

			// Endpoint URLs.
			add_filter( 'wp_mcp_ai_default_ollama_endpoint_url', array( $this, 'apply_default_ollama_endpoint_url' ), 5 );
			add_filter( 'wp_mcp_ai_default_lm_studio_endpoint_url', array( $this, 'apply_default_lm_studio_endpoint_url' ), 5 );
			add_filter( 'wp_mcp_ai_lm_studio_fallback_model', array( $this, 'apply_lm_studio_fallback_model' ), 5, 2 );
		}

		/**
		 * Get a custom filter setting value.
		 *
		 * @param string $key Setting key.
		 * @return mixed|null Setting value or null if not set.
		 */
		private function get_filter_setting( $key ) {
			$value = WP_MCP_AI_Settings_Registry::get_setting( $key, null );

			// Return null if empty string (not set).
			if ( '' === $value || null === $value ) {
				return null;
			}

			return $value;
		}

		/**
		 * Apply default light model filter.
		 *
		 * @param string $model Current model.
		 * @return string
		 */
		public function apply_default_light_model( $model ) {
			$custom = $this->get_filter_setting( 'filter_default_light_model' );
			return null !== $custom ? $custom : $model;
		}

		/**
		 * Apply default advanced model filter.
		 *
		 * @param string $model Current model.
		 * @return string
		 */
		public function apply_default_advanced_model( $model ) {
			$custom = $this->get_filter_setting( 'filter_default_advanced_model' );
			return null !== $custom ? $custom : $model;
		}

		/**
		 * Apply max agentic iterations filter.
		 *
		 * @param int   $iterations Current iterations.
		 * @param array $config Assistant config.
		 * @return int
		 */
		public function apply_max_agentic_iterations( $iterations, $config = array() ) {
			$custom = $this->get_filter_setting( 'filter_max_agentic_iterations' );
			return null !== $custom ? absint( $custom ) : $iterations;
		}

		/**
		 * Apply resource max tokens filter.
		 *
		 * @param int    $max_tokens Current max tokens.
		 * @param string $tier Workload tier.
		 * @return int
		 */
		public function apply_resource_max_tokens( $max_tokens, $tier = '' ) {
			$custom = $this->get_filter_setting( 'filter_resource_max_tokens' );
			return null !== $custom ? absint( $custom ) : $max_tokens;
		}

		/**
		 * Apply resource request timeout filter.
		 *
		 * @param int    $timeout Current timeout.
		 * @param string $tier Workload tier.
		 * @param int    $max_execution_time Max execution time.
		 * @return int
		 */
		public function apply_resource_request_timeout( $timeout, $tier = '', $max_execution_time = 0 ) {
			$custom = $this->get_filter_setting( 'filter_resource_request_timeout' );
			return null !== $custom ? absint( $custom ) : $timeout;
		}

		/**
		 * Apply max retries filter.
		 *
		 * @param int   $retries Current retries.
		 * @param array $options Options.
		 * @return int
		 */
		public function apply_max_retries( $retries, $options = array() ) {
			$custom = $this->get_filter_setting( 'filter_max_retries' );
			return null !== $custom ? absint( $custom ) : $retries;
		}

		/**
		 * Apply max retry delay filter.
		 *
		 * @param int   $delay Current delay.
		 * @param array $options Options.
		 * @return int
		 */
		public function apply_max_retry_delay( $delay, $options = array() ) {
			$custom = $this->get_filter_setting( 'filter_max_retry_delay' );
			return null !== $custom ? absint( $custom ) : $delay;
		}

		/**
		 * Apply max attachment bytes filter.
		 *
		 * @param int   $bytes Current bytes.
		 * @param array $context Context.
		 * @return int
		 */
		public function apply_max_attachment_bytes( $bytes, $context = array() ) {
			$custom = $this->get_filter_setting( 'filter_max_attachment_bytes' );
			return null !== $custom ? absint( $custom ) : $bytes;
		}

		/**
		 * Apply default Ollama endpoint URL filter.
		 *
		 * @param string $url Current URL.
		 * @return string
		 */
		public function apply_default_ollama_endpoint_url( $url ) {
			$custom = $this->get_filter_setting( 'filter_default_ollama_endpoint_url' );
			return null !== $custom ? esc_url_raw( $custom ) : $url;
		}

		/**
		 * Apply default LM Studio endpoint URL filter.
		 *
		 * @param string $url Current URL.
		 * @return string
		 */
		public function apply_default_lm_studio_endpoint_url( $url ) {
			$custom = $this->get_filter_setting( 'filter_default_lm_studio_endpoint_url' );
			return null !== $custom ? esc_url_raw( $custom ) : $url;
		}

		/**
		 * Apply LM Studio fallback model filter.
		 *
		 * @param string $fallback_model Current fallback model.
		 * @param array  $options        Request options.
		 * @return string
		 */
		public function apply_lm_studio_fallback_model( $fallback_model, $options ) {
			$custom = $this->get_filter_setting( 'filter_lm_studio_fallback_model' );
			return null !== $custom ? sanitize_text_field( $custom ) : $fallback_model;
		}
	}
}
