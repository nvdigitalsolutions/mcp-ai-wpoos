<?php
/**
 * Resource Manager for dynamic AI resource management.
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
		 * @return int Recommended maximum tokens.
		 */
		public function get_max_tokens() {
			$tier = $this->get_workload_tier();

			$max_tokens_map = array(
				'low'    => 1000,
				'medium' => 4000,
				'high'   => 16000,
			);

			$max_tokens = isset( $max_tokens_map[ $tier ] ) ? $max_tokens_map[ $tier ] : 4000;

			/**
			 * Filter the recommended maximum tokens.
			 *
			 * @param int    $max_tokens The recommended maximum tokens.
			 * @param string $tier       The current workload tier.
			 */
			return apply_filters( 'wp_mcp_ai_resource_max_tokens', $max_tokens, $tier );
		}

		/**
		 * Get the recommended request timeout based on the current workload tier.
		 *
		 * @return int Recommended timeout in seconds.
		 */
		public function get_request_timeout() {
			$tier               = $this->get_workload_tier();
			$max_execution_time = $this->get_max_execution_time();

			$timeout_map = array(
				'low'    => 30,
				'medium' => 60,
				'high'   => 120,
			);

			$base_timeout = isset( $timeout_map[ $tier ] ) ? $timeout_map[ $tier ] : 60;

			// Ensure timeout doesn't exceed max_execution_time minus a buffer.
			$max_allowed_timeout = max( 5, $max_execution_time - 5 );
			$timeout             = min( $base_timeout, $max_allowed_timeout );

			/**
			 * Filter the recommended request timeout.
			 *
			 * @param int    $timeout             The recommended timeout in seconds.
			 * @param string $tier                The current workload tier.
			 * @param int    $max_execution_time  The PHP max_execution_time setting.
			 */
			return apply_filters( 'wp_mcp_ai_resource_request_timeout', $timeout, $tier, $max_execution_time );
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
			$num  = absint( $size );

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
	}
}
