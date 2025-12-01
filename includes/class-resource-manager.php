<?php
/**
 * Resource Manager for dynamic AI resource management.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Resource_Manager' ) ) {
	/**
	 * Manages server resources and provides intelligent limits for AI operations.
	 */
	class WP_MCP_AI_Resource_Manager {

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_Resource_Manager
		 */
		private static $instance;

		/**
		 * Cached memory limit in bytes.
		 *
		 * @var int|null
		 */
		private $memory_limit_bytes = null;

		/**
		 * Cached max execution time in seconds.
		 *
		 * @var int|null
		 */
		private $max_execution_time = null;

		/**
		 * Cached workload tier.
		 *
		 * @var string|null
		 */
		private $workload_tier = null;

		/**
		 * Maximum concurrent AI requests allowed.
		 *
		 * @var int
		 */
		private $max_concurrent_requests = 2;

		/**
		 * Maximum input tokens per request.
		 *
		 * @var int
		 */
		private $max_input_tokens = 120000;

		/**
		 * Returns the singleton instance.
		 *
		 * @return WP_MCP_AI_Resource_Manager
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor to prevent direct instantiation.
		 */
		private function __construct() {
			// Initialization happens via method calls.
		}

		/**
		 * Get the PHP memory limit in bytes.
		 *
		 * @return int Memory limit in bytes.
		 */
		public function get_memory_limit() {
			if ( null !== $this->memory_limit_bytes ) {
				return $this->memory_limit_bytes;
			}

			$memory_limit = ini_get( 'memory_limit' );

			if ( empty( $memory_limit ) || '-1' === $memory_limit ) {
				// Unlimited or not set - assume a high value.
				$this->memory_limit_bytes = 512 * 1024 * 1024; // 512MB default for unlimited.
				return $this->memory_limit_bytes;
			}

			$this->memory_limit_bytes = $this->parse_size_to_bytes( $memory_limit );

			return $this->memory_limit_bytes;
		}

		/**
		 * Get the PHP max execution time in seconds.
		 *
		 * @return int Max execution time in seconds.
		 */
		public function get_max_execution_time() {
			if ( null !== $this->max_execution_time ) {
				return $this->max_execution_time;
			}

			$max_execution_time = ini_get( 'max_execution_time' );

			if ( empty( $max_execution_time ) || '0' === $max_execution_time ) {
				// Unlimited or not set - assume a reasonable default.
				$this->max_execution_time = 30; // 30 seconds default.
			} else {
				$this->max_execution_time = absint( $max_execution_time );
			}

			return $this->max_execution_time;
		}

		/**
		 * Determine the workload tier based on available resources.
		 *
		 * Tiers:
		 * - low: memory_limit < 128M
		 * - medium: 128M <= memory_limit < 512M
		 * - high: memory_limit >= 512M
		 *
		 * @return string The workload tier: 'low', 'medium', or 'high'.
		 */
		public function get_workload_tier() {
			if ( null !== $this->workload_tier ) {
				return $this->workload_tier;
			}

			$memory_limit = $this->get_memory_limit();

			// Define tier thresholds.
			$low_threshold    = 128 * 1024 * 1024; // 128MB.
			$medium_threshold = 512 * 1024 * 1024; // 512MB.

			if ( $memory_limit < $low_threshold ) {
				$this->workload_tier = 'low';
			} elseif ( $memory_limit < $medium_threshold ) {
				$this->workload_tier = 'medium';
			} else {
				$this->workload_tier = 'high';
			}

			/**
			 * Filter the determined workload tier.
			 *
			 * @param string $tier         The workload tier: 'low', 'medium', or 'high'.
			 * @param int    $memory_limit Memory limit in bytes.
			 */
			$this->workload_tier = apply_filters( 'wp_mcp_ai_workload_tier', $this->workload_tier, $memory_limit );

			return $this->workload_tier;
		}


		/**
		 * Get the recommended maximum tokens based on the current workload tier.
		 *
		 * This represents the Context Window limit - the total token budget for each
		 * complete AI interaction including system prompt, conversation history,
		 * user input, tool data, and AI output.
		 *
		 * @return int Recommended maximum tokens (context window size).
		 */
		public function get_max_tokens() {
			$tier = $this->get_workload_tier();

			// Read from orchestration settings if available (configured via Settings > Orchestration Layer).
			// These settings are managed by Configuration Presets and can be customized per workload tier.
			$setting_key      = $tier . '_tier_max_tokens';
			$configured_value = null;

			if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
				$configured_value = WP_MCP_AI_Settings_Registry::get_setting( $setting_key );
			}

			// Fallback to modern defaults if settings not available.
			// Updated from legacy values (1000/4000/16000) to match modern AI standards.
			$default_map = array(
				'low'    => 2000,
				'medium' => 8000,
				'high'   => 32000,
			);

			// Use configured value if available, otherwise use default for tier.
			if ( null !== $configured_value && absint( $configured_value ) > 0 ) {
				$max_tokens = absint( $configured_value );
			} else {
				$max_tokens = isset( $default_map[ $tier ] ) ? $default_map[ $tier ] : 8000;
			}

			/**
			 * Filter the recommended maximum tokens (context window size).
			 *
			 * @param int    $max_tokens The recommended maximum tokens.
			 * @param string $tier       The current workload tier ('low', 'medium', or 'high').
			 */
			return apply_filters( 'wp_mcp_ai_resource_max_tokens', $max_tokens, $tier );
		}

		/**
		 * Get the recommended request timeout based on the current workload tier.
		 *
		 * @param bool $ignore_execution_time Whether to ignore max_execution_time constraint.
		 *                                    Set to true for external HTTP requests to local AI providers.
		 * @return int Recommended timeout in seconds.
		 */
		public function get_request_timeout( $ignore_execution_time = false ) {
			$tier               = $this->get_workload_tier();
			$max_execution_time = $this->get_max_execution_time();

			$timeout_map = array(
				'low'    => 30,
				'medium' => 60,
				'high'   => 120,
			);

			$base_timeout = isset( $timeout_map[ $tier ] ) ? $timeout_map[ $tier ] : 60;

			// For external HTTP requests (e.g., to local AI providers), we can ignore.
			// max_execution_time since the HTTP API handles the wait without consuming PHP time.
			if ( ! $ignore_execution_time ) {
				// Ensure timeout doesn't exceed max_execution_time minus a buffer.
				$max_allowed_timeout = max( 5, $max_execution_time - 5 );
				$timeout             = min( $base_timeout, $max_allowed_timeout );
			} else {
				$timeout = $base_timeout;
			}

			/**
			 * Filter the recommended request timeout.
			 *
			 * @param int    $timeout             The recommended timeout in seconds.
			 * @param string $tier                The current workload tier.
			 * @param int    $max_execution_time  The PHP max_execution_time setting.
			 * @param bool   $ignore_execution_time Whether execution time constraint was ignored.
			 */
			return apply_filters( 'wp_mcp_ai_resource_request_timeout', $timeout, $tier, $max_execution_time, $ignore_execution_time );
		}

		/**
		 * Parse a size string (e.g., '256M', '1G') to bytes.
		 *
		 * @param string $size The size string to parse.
		 * @return int The size in bytes.
		 */
		private function parse_size_to_bytes( $size ) {
			$size = trim( $size );
			$unit = strtoupper( substr( $size, -1 ) );
			$num  = (int) $size;

			switch ( $unit ) {
				case 'G':
					$num *= 1024 * 1024 * 1024;
					break;
				case 'M':
					$num *= 1024 * 1024;
					break;
				case 'K':
					$num *= 1024;
					break;
			}

			return $num;
		}

		/**
		 * Check if the current environment can handle a specific operation.
		 *
		 * @param array $requirements Operation requirements (e.g., ['max_tokens' => 8000]).
		 * @return bool|WP_Error True if operation can be handled, WP_Error otherwise.
		 */
		public function can_handle_operation( $requirements = array() ) {
			$tier       = $this->get_workload_tier();
			$max_tokens = $this->get_max_tokens();

			if ( isset( $requirements['max_tokens'] ) && $requirements['max_tokens'] > $max_tokens ) {
				return new WP_Error(
					'wp_mcp_ai_insufficient_resources',
					sprintf(
						/* translators: 1: Requested tokens, 2: Maximum tokens, 3: Workload tier */
						__( 'The requested operation requires %1$d tokens, but the server is configured for a maximum of %2$d tokens (workload tier: %3$s).', 'wp-mcp-ai' ),
						$requirements['max_tokens'],
						$max_tokens,
						$tier
					),
					array(
						'status'           => 503,
						'tier'             => $tier,
						'max_tokens'       => $max_tokens,
						'requested_tokens' => $requirements['max_tokens'],
					)
				);
			}

			return true;
		}

		/**
		 * Get the maximum number of concurrent AI requests allowed.
		 *
		 * @return int Maximum concurrent requests.
		 */
		public function get_max_concurrent_requests() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );

			if ( isset( $settings['max_concurrent_requests'] ) && $settings['max_concurrent_requests'] > 0 ) {
				return absint( $settings['max_concurrent_requests'] );
			}

			/**
			 * Filter the maximum concurrent AI requests.
			 *
			 * @param int $max_concurrent Default maximum concurrent requests.
			 */
			return apply_filters( 'wp_mcp_ai_max_concurrent_requests', $this->max_concurrent_requests );
		}

		/**
		 * Set the maximum number of concurrent AI requests.
		 *
		 * @param int $max Maximum concurrent requests (1-10).
		 * @return bool True on success.
		 */
		public function set_max_concurrent_requests( $max ) {
			$max = max( 1, min( 10, absint( $max ) ) );

			$settings                            = get_option( 'wp_mcp_ai_settings', array() );
			$settings['max_concurrent_requests'] = $max;

			return update_option( 'wp_mcp_ai_settings', $settings );
		}

		/**
		 * Get the maximum input tokens allowed per request.
		 *
		 * @return int Maximum input tokens.
		 */
		public function get_max_input_tokens() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );

			if ( isset( $settings['max_input_tokens'] ) && $settings['max_input_tokens'] > 0 ) {
				return absint( $settings['max_input_tokens'] );
			}

			/**
			 * Filter the maximum input tokens per request.
			 *
			 * @param int $max_tokens Default maximum input tokens.
			 */
			return apply_filters( 'wp_mcp_ai_max_input_tokens', $this->max_input_tokens );
		}

		/**
		 * Set the maximum input tokens per request.
		 *
		 * @param int $max Maximum input tokens (1000-500000).
		 * @return bool True on success.
		 */
		public function set_max_input_tokens( $max ) {
			$max = max( 1000, min( 500000, absint( $max ) ) );

			$settings                     = get_option( 'wp_mcp_ai_settings', array() );
			$settings['max_input_tokens'] = $max;

			return update_option( 'wp_mcp_ai_settings', $settings );
		}

		/**
		 * Validate token count against budget.
		 *
		 * @param int $token_count Token count to validate.
		 * @return bool|WP_Error True if within budget, WP_Error otherwise.
		 */
		public function validate_token_budget( $token_count ) {
			$max_tokens = $this->get_max_input_tokens();

			if ( $token_count > $max_tokens ) {
				return new WP_Error(
					'wp_mcp_ai_token_budget_exceeded',
					sprintf(
						/* translators: 1: Token count, 2: Maximum allowed tokens */
						__( 'Request token count (%1$d) exceeds maximum allowed (%2$d).', 'wp-mcp-ai' ),
						$token_count,
						$max_tokens
					),
					array(
						'status'      => 413,
						'token_count' => $token_count,
						'max_tokens'  => $max_tokens,
					)
				);
			}

			return true;
		}

		/**
		 * Ensure adequate PHP execution time for a long-running operation.
		 *
		 * This method prevents "Maximum execution time exceeded" errors when performing
		 * operations that may take longer than the default PHP execution time limit.
		 *
		 * Common use case: AJAX handlers that make HTTP requests with long timeouts
		 * (60-120 seconds) to local AI providers. Without this, PHP kills the request
		 * after ~30 seconds even though the HTTP timeout hasn't been reached.
		 *
		 * @param int $required_time Minimum execution time required in seconds.
		 * @return bool True if execution time was adjusted, false otherwise.
		 */
		public function ensure_execution_time( $required_time ) {
			$required_time      = absint( $required_time );
			$current_time_limit = ini_get( 'max_execution_time' );

			// If max_execution_time is 0, it's unlimited - no need to adjust.
			if ( 0 === intval( $current_time_limit ) ) {
				return false;
			}

			// If current limit is already sufficient, no need to adjust.
			if ( $current_time_limit >= $required_time ) {
				return false;
			}

			// Attempt to increase the execution time limit.
			// Some hosts disable set_time_limit() for security, so we use @ to suppress warnings.
			$result = @set_time_limit( $required_time ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			/**
			 * Fires after attempting to adjust PHP execution time.
			 *
			 * @param int  $required_time      The requested execution time in seconds.
			 * @param int  $current_time_limit The previous execution time limit.
			 * @param bool $result             Whether set_time_limit succeeded.
			 */
			do_action( 'wp_mcp_ai_execution_time_adjusted', $required_time, $current_time_limit, $result );

			return $result;
		}
	}
}
