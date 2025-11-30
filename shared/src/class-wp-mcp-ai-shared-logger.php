<?php
/**
 * Simple logging utility for debugging.
 *
 * @package WP_MCP_AI_Shared
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple logging utility for debugging.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Shared_Logger {

	/**
	 * Log a message if debug mode is enabled.
	 *
	 * @param string $message Log message.
	 * @param mixed  $data    Optional data to include.
	 */
	public static function log( $message, $data = null ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$entry = '[WP MCP AI] ' . $message;

		if ( null !== $data ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$entry .= ' | Data: ' . print_r( $data, true );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $entry );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Error message.
	 * @param mixed  $data    Optional error data.
	 */
	public static function error( $message, $data = null ) {
		self::log( 'ERROR: ' . $message, $data );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Warning message.
	 * @param mixed  $data    Optional warning data.
	 */
	public static function warning( $message, $data = null ) {
		self::log( 'WARNING: ' . $message, $data );
	}
}
