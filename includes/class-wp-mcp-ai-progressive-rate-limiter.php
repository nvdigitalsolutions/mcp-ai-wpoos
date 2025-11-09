<?php
/**
 * Progressive Rate Limiting for WP oOS.
 *
 * Implements progressive/adaptive rate limiting with escalating restrictions
 * based on usage patterns and violation history.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Progressive rate limiter with adaptive thresholds.
 */
class WP_MCP_AI_Progressive_Rate_Limiter {

	/**
	 * Transient prefix for rate limit tracking.
	 */
	const TRANSIENT_PREFIX = 'wp_mcp_ai_rate_';

	/**
	 * Transient prefix for violation tracking.
	 */
	const VIOLATION_PREFIX = 'wp_mcp_ai_violations_';

	/**
	 * Tier thresholds and limits.
	 *
	 * @var array
	 */
	protected static $tiers = array();

	/**
	 * Initialize rate limit tiers.
	 */
	protected static function init_tiers() {
		if ( ! empty( self::$tiers ) ) {
			return;
		}

		// Define progressive tiers.
		self::$tiers = array(
			'normal' => array(
				'requests_per_minute' => 60,
				'requests_per_hour'   => 1000,
				'burst_size'          => 10,
				'description'         => 'Normal usage tier',
			),
			'warning' => array(
				'requests_per_minute' => 30,
				'requests_per_hour'   => 500,
				'burst_size'          => 5,
				'description'         => 'Warning tier - moderate restrictions',
			),
			'restricted' => array(
				'requests_per_minute' => 10,
				'requests_per_hour'   => 100,
				'burst_size'          => 2,
				'description'         => 'Restricted tier - heavy restrictions',
			),
			'blocked' => array(
				'requests_per_minute' => 0,
				'requests_per_hour'   => 0,
				'burst_size'          => 0,
				'description'         => 'Blocked - no requests allowed',
			),
		);

		/**
		 * Filter rate limit tiers.
		 *
		 * @since 1.0.0
		 *
		 * @param array $tiers Rate limit tier configuration.
		 */
		self::$tiers = apply_filters( 'wp_mcp_ai_rate_limit_tiers', self::$tiers );
	}

	/**
	 * Check if request should be rate limited.
	 *
	 * @param string $identifier User/IP/API key identifier.
	 * @param string $endpoint   Optional endpoint name for granular limits.
	 * @return array Rate limit result with 'allowed' boolean and metadata.
	 */
	public static function check_rate_limit( $identifier, $endpoint = 'default' ) {
		self::init_tiers();

		$identifier = sanitize_key( $identifier );
		$endpoint = sanitize_key( $endpoint );

		// Get current tier for identifier.
		$tier = self::get_current_tier( $identifier );
		$limits = self::$tiers[ $tier ];

		// Check minute limit.
		$minute_key = self::TRANSIENT_PREFIX . 'minute_' . $identifier . '_' . $endpoint;
		$minute_count = (int) get_transient( $minute_key );

		// Check hour limit.
		$hour_key = self::TRANSIENT_PREFIX . 'hour_' . $identifier . '_' . $endpoint;
		$hour_count = (int) get_transient( $hour_key );

		// Check burst (consecutive rapid requests).
		$burst_key = self::TRANSIENT_PREFIX . 'burst_' . $identifier . '_' . $endpoint;
		$burst_count = (int) get_transient( $burst_key );

		$allowed = true;
		$reason = '';

		if ( $minute_count >= $limits['requests_per_minute'] ) {
			$allowed = false;
			$reason = 'minute_limit_exceeded';
		} elseif ( $hour_count >= $limits['requests_per_hour'] ) {
			$allowed = false;
			$reason = 'hour_limit_exceeded';
		} elseif ( $burst_count >= $limits['burst_size'] ) {
			$allowed = false;
			$reason = 'burst_limit_exceeded';
		}

		$result = array(
			'allowed'             => $allowed,
			'tier'                => $tier,
			'reason'              => $reason,
			'minute_remaining'    => max( 0, $limits['requests_per_minute'] - $minute_count ),
			'hour_remaining'      => max( 0, $limits['requests_per_hour'] - $hour_count ),
			'burst_remaining'     => max( 0, $limits['burst_size'] - $burst_count ),
			'reset_minute'        => 60,
			'reset_hour'          => 3600,
			'limits'              => $limits,
		);

		if ( ! $allowed ) {
			// Record violation.
			self::record_violation( $identifier, $reason );

			// Log security event.
			if ( class_exists( 'WP_MCP_AI_SIEM_Logger' ) ) {
				WP_MCP_AI_SIEM_Logger::log_security_event(
					WP_MCP_AI_SIEM_Logger::EVENT_RATE_LIMIT,
					sprintf( 'Rate limit exceeded: %s', $reason ),
					array(
						'identifier' => $identifier,
						'endpoint'   => $endpoint,
						'tier'       => $tier,
					),
					WP_MCP_AI_SIEM_Logger::SEVERITY_WARNING
				);
			}
		}

		/**
		 * Filter rate limit check result.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $result     Rate limit result.
		 * @param string $identifier Request identifier.
		 * @param string $endpoint   Endpoint name.
		 */
		return apply_filters( 'wp_mcp_ai_rate_limit_result', $result, $identifier, $endpoint );
	}

	/**
	 * Record a request for rate limiting.
	 *
	 * @param string $identifier User/IP/API key identifier.
	 * @param string $endpoint   Optional endpoint name.
	 * @return bool True if recorded successfully.
	 */
	public static function record_request( $identifier, $endpoint = 'default' ) {
		$identifier = sanitize_key( $identifier );
		$endpoint = sanitize_key( $endpoint );

		// Increment minute counter.
		$minute_key = self::TRANSIENT_PREFIX . 'minute_' . $identifier . '_' . $endpoint;
		$minute_count = (int) get_transient( $minute_key );
		set_transient( $minute_key, $minute_count + 1, 60 );

		// Increment hour counter.
		$hour_key = self::TRANSIENT_PREFIX . 'hour_' . $identifier . '_' . $endpoint;
		$hour_count = (int) get_transient( $hour_key );
		set_transient( $hour_key, $hour_count + 1, 3600 );

		// Increment burst counter (short window).
		$burst_key = self::TRANSIENT_PREFIX . 'burst_' . $identifier . '_' . $endpoint;
		$burst_count = (int) get_transient( $burst_key );
		set_transient( $burst_key, $burst_count + 1, 10 ); // 10 second burst window.

		return true;
	}

	/**
	 * Get current tier for identifier.
	 *
	 * @param string $identifier Request identifier.
	 * @return string Tier name.
	 */
	public static function get_current_tier( $identifier ) {
		$identifier = sanitize_key( $identifier );

		// Get violation count.
		$violations = self::get_violation_count( $identifier );

		// Calculate tier based on violations.
		if ( $violations >= 10 ) {
			return 'blocked';
		} elseif ( $violations >= 5 ) {
			return 'restricted';
		} elseif ( $violations >= 2 ) {
			return 'warning';
		}

		return 'normal';
	}

	/**
	 * Record a rate limit violation.
	 *
	 * @param string $identifier Request identifier.
	 * @param string $reason     Violation reason.
	 * @return bool True if recorded successfully.
	 */
	protected static function record_violation( $identifier, $reason ) {
		$identifier = sanitize_key( $identifier );

		$violation_key = self::VIOLATION_PREFIX . $identifier;
		$violations = get_transient( $violation_key );

		if ( false === $violations ) {
			$violations = array();
		}

		$violations[] = array(
			'timestamp' => current_time( 'mysql', true ),
			'reason'    => $reason,
		);

		// Keep only last 20 violations.
		if ( count( $violations ) > 20 ) {
			$violations = array_slice( $violations, -20 );
		}

		// Store violations for 24 hours.
		set_transient( $violation_key, $violations, DAY_IN_SECONDS );

		return true;
	}

	/**
	 * Get violation count for identifier.
	 *
	 * @param string $identifier Request identifier.
	 * @param int    $time_window Optional time window in seconds. Default 3600 (1 hour).
	 * @return int Violation count.
	 */
	public static function get_violation_count( $identifier, $time_window = 3600 ) {
		$identifier = sanitize_key( $identifier );

		$violation_key = self::VIOLATION_PREFIX . $identifier;
		$violations = get_transient( $violation_key );

		if ( false === $violations || ! is_array( $violations ) ) {
			return 0;
		}

		$cutoff = time() - $time_window;
		$recent_violations = array_filter(
			$violations,
			function( $violation ) use ( $cutoff ) {
				return strtotime( $violation['timestamp'] ) > $cutoff;
			}
		);

		return count( $recent_violations );
	}

	/**
	 * Clear violations for identifier (admin function).
	 *
	 * @param string $identifier Request identifier.
	 * @return bool True if cleared successfully.
	 */
	public static function clear_violations( $identifier ) {
		$identifier = sanitize_key( $identifier );

		$violation_key = self::VIOLATION_PREFIX . $identifier;
		return delete_transient( $violation_key );
	}

	/**
	 * Get rate limit status for identifier.
	 *
	 * @param string $identifier Request identifier.
	 * @param string $endpoint   Optional endpoint name.
	 * @return array Status information.
	 */
	public static function get_status( $identifier, $endpoint = 'default' ) {
		self::init_tiers();

		$identifier = sanitize_key( $identifier );
		$endpoint = sanitize_key( $endpoint );

		$tier = self::get_current_tier( $identifier );
		$limits = self::$tiers[ $tier ];

		$minute_key = self::TRANSIENT_PREFIX . 'minute_' . $identifier . '_' . $endpoint;
		$minute_count = (int) get_transient( $minute_key );

		$hour_key = self::TRANSIENT_PREFIX . 'hour_' . $identifier . '_' . $endpoint;
		$hour_count = (int) get_transient( $hour_key );

		$burst_key = self::TRANSIENT_PREFIX . 'burst_' . $identifier . '_' . $endpoint;
		$burst_count = (int) get_transient( $burst_key );

		$violations = self::get_violation_count( $identifier );

		return array(
			'tier'                => $tier,
			'tier_description'    => $limits['description'],
			'violations_count'    => $violations,
			'minute_used'         => $minute_count,
			'minute_limit'        => $limits['requests_per_minute'],
			'minute_remaining'    => max( 0, $limits['requests_per_minute'] - $minute_count ),
			'hour_used'           => $hour_count,
			'hour_limit'          => $limits['requests_per_hour'],
			'hour_remaining'      => max( 0, $limits['requests_per_hour'] - $hour_count ),
			'burst_used'          => $burst_count,
			'burst_limit'         => $limits['burst_size'],
			'burst_remaining'     => max( 0, $limits['burst_size'] - $burst_count ),
		);
	}

	/**
	 * Check if progressive rate limiting is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Filter to enable/disable progressive rate limiting.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether progressive rate limiting is enabled. Default false.
		 */
		return apply_filters( 'wp_mcp_ai_progressive_rate_limit_enabled', false );
	}
}
