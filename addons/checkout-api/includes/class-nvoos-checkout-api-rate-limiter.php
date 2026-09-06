<?php
/**
 * Per-IP rate limiter.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cheap transient-based rate limiter.
 *
 * The /session and /verify endpoints are called server-to-server by
 * customer sites and carry no shared secret, so throttling per client IP
 * is the first line of defense (Stripe-side verification is the second).
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Rate_Limiter {

	/**
	 * Whether a request in the given bucket is allowed.
	 *
	 * @param string $bucket Bucket name (e.g. 'session').
	 * @param int    $max    Max requests per window.
	 * @param int    $window Window length in seconds.
	 * @return bool
	 */
	public static function check( string $bucket, int $max, int $window ): bool {
		$key   = 'nvoos_checkout_rl_' . md5( $bucket . '|' . self::client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Best-effort client IP, sanitized.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $ip && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$first     = strstr( $forwarded, ',', true );
			$first     = false === $first ? $forwarded : trim( $first );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				$ip = $first;
			}
		}
		return '' !== $ip ? $ip : 'unknown';
	}
}
