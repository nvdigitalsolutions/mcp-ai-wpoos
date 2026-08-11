<?php
/**
 * Analytics Rate Limiter — Token-bucket rate coordinator for platform APIs.
 *
 * Prevents individual tools from exhausting platform API rate limits by
 * coordinating all analytics API calls through a single token bucket per
 * platform. Uses WP options (no autoload) for persistent counters.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics rate limiter service.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Analytics_Rate_Limiter {

	/**
	 * Singleton instance.
	 *
	 * @since 1.7.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Option name prefix for rate counters.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_rate_';

	/**
	 * Platform-specific rate limits (requests per window in seconds).
	 *
	 * @since 1.7.0
	 * @var array<string,array{limit:int,window:int}>
	 */
	const LIMITS = array(
		'twitter'          => array(
			'limit'  => 300,
			'window' => 900,
		),
		'facebook'         => array(
			'limit'  => 200,
			'window' => 3600,
		),
		'instagram'        => array(
			'limit'  => 200,
			'window' => 3600,
		),
		'linkedin'         => array(
			'limit'  => 100,
			'window' => 86400,
		),
		'tiktok'           => array(
			'limit'  => 50,
			'window' => 3600,
		),
		'google_business'  => array(
			'limit'  => 100,
			'window' => 3600,
		),
		'woocommerce'      => array(
			'limit'  => 500,
			'window' => 3600,
		),
		'google_analytics' => array(
			'limit'  => 100,
			'window' => 3600,
		),
		'cloudways'        => array(
			'limit'  => 60,
			'window' => 3600,
		),
		'default'          => array(
			'limit'  => 100,
			'window' => 3600,
		),
	);

	/**
	 * Hard block threshold (percentage of limit).
	 *
	 * @since 1.7.0
	 * @var float
	 */
	const HARD_BLOCK_PCT = 0.90;

	/**
	 * Soft warning threshold (percentage of limit).
	 *
	 * @since 1.7.0
	 * @var float
	 */
	const SOFT_WARNING_PCT = 0.70;

	/**
	 * Private constructor for singleton.
	 *
	 * @since 1.7.0
	 */
	private function __construct() {}

	/**
	 * Get singleton instance.
	 *
	 * @since 1.7.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the rate limit configuration for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return array{limit:int,window:int}
	 */
	public function get_limit_config( $platform ) {
		return isset( self::LIMITS[ $platform ] ) ? self::LIMITS[ $platform ] : self::LIMITS['default'];
	}

	/**
	 * Check if a request is allowed for the given platform.
	 *
	 * Always call consume() after a successful check to decrement.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return bool True if the request is allowed.
	 */
	public function check( $platform ) {
		$state = $this->get_state( $platform );
		$limit = $this->get_limit_config( $platform );

		// Refill tokens based on elapsed time.
		$now         = time();
		$elapsed     = $now - $state['last_refill'];
		$refill_rate = $limit['limit'] / $limit['window'];
		$tokens      = min( $limit['limit'], $state['tokens'] + ( $elapsed * $refill_rate ) );

		return $tokens >= 1;
	}

	/**
	 * Consume one token for the given platform.
	 *
	 * Must be called AFTER a successful API request.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return void
	 */
	public function consume( $platform ) {
		$state       = $this->get_state( $platform );
		$limit       = $this->get_limit_config( $platform );
		$now         = time();
		$elapsed     = $now - $state['last_refill'];
		$refill_rate = $limit['limit'] / $limit['window'];
		$tokens      = min( $limit['limit'], $state['tokens'] + ( $elapsed * $refill_rate ) );

		$state['tokens']      = max( 0, $tokens - 1 );
		$state['last_refill'] = $now;
		$state['total_used']  = ( $state['total_used'] ?? 0 ) + 1;

		$this->save_state( $platform, $state );
	}

	/**
	 * Get the remaining tokens for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return int Remaining request tokens.
	 */
	public function get_remaining( $platform ) {
		$state       = $this->get_state( $platform );
		$limit       = $this->get_limit_config( $platform );
		$now         = time();
		$elapsed     = $now - $state['last_refill'];
		$refill_rate = $limit['limit'] / $limit['window'];
		$tokens      = min( $limit['limit'], $state['tokens'] + ( $elapsed * $refill_rate ) );

		return (int) floor( $tokens );
	}

	/**
	 * Get the usage percentage for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return float Percentage (0-100).
	 */
	public function get_usage_pct( $platform ) {
		$limit     = $this->get_limit_config( $platform );
		$remaining = $this->get_remaining( $platform );
		return round( ( 1 - ( $remaining / $limit['limit'] ) ) * 100, 1 );
	}

	/**
	 * Reset rate limit counters for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return void
	 */
	public function reset( $platform ) {
		delete_option( self::OPTION_PREFIX . $platform );
	}

	/**
	 * Get the current state for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return array{tokens:float,last_refill:int,total_used:int}
	 */
	private function get_state( $platform ) {
		$default = array(
			'tokens'      => (float) $this->get_limit_config( $platform )['limit'],
			'last_refill' => time(),
			'total_used'  => 0,
		);

		$state = get_option( self::OPTION_PREFIX . $platform, $default );

		if ( ! is_array( $state ) ) {
			return $default;
		}

		return array_merge( $default, $state );
	}

	/**
	 * Save the state for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @param array  $state    State data.
	 * @return void
	 */
	private function save_state( $platform, array $state ) {
		update_option( self::OPTION_PREFIX . $platform, $state, false );
	}

	/**
	 * Get the hard block status for a platform.
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return bool True if the platform is hard-blocked (>90% usage).
	 */
	public function is_hard_blocked( $platform ) {
		return $this->get_usage_pct( $platform ) >= ( self::HARD_BLOCK_PCT * 100 );
	}

	/**
	 * Whether the platform is near its rate limit (soft warning).
	 *
	 * @since 1.7.0
	 *
	 * @param string $platform Platform identifier.
	 * @return bool True if above 70% usage.
	 */
	public function is_soft_warning( $platform ) {
		$pct = $this->get_usage_pct( $platform );
		return $pct >= ( self::SOFT_WARNING_PCT * 100 ) && $pct < ( self::HARD_BLOCK_PCT * 100 );
	}
}
