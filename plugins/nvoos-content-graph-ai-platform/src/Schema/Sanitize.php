<?php
/**
 * Recursive sanitization helpers.
 *
 * Mirrors the base plugin's wp_mcp_ai_sanitize_recursive() so standalone
 * mode keeps byte-identical sanitization semantics for decoded JSON
 * structures. When the base plugin is present (monolith mode) the base
 * function is used directly.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursive sanitization for decoded JSON structures.
 */
final class Sanitize {

	/**
	 * Recursively sanitize a decoded JSON structure.
	 *
	 * Strings are passed through sanitize_text_field(), integers and floats
	 * are cast to their respective types, booleans and nulls are preserved,
	 * and nested arrays are sanitized recursively — identical semantics to
	 * the base plugin's wp_mcp_ai_sanitize_recursive().
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $data Decoded JSON value.
	 * @return array Sanitized data.
	 */
	public static function recursive( $data ): array {
		// Monolith mode: delegate to the base helper for guaranteed parity.
		if ( function_exists( 'wp_mcp_ai_sanitize_recursive' ) ) {
			return wp_mcp_ai_sanitize_recursive( $data );
		}

		if ( ! is_array( $data ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $data as $key => $value ) {
			$clean_key = is_int( $key ) ? $key : sanitize_text_field( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $clean_key ] = self::recursive( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $clean_key ] = $value;
			} elseif ( is_int( $value ) ) {
				$sanitized[ $clean_key ] = (int) $value;
			} elseif ( is_float( $value ) ) {
				$sanitized[ $clean_key ] = (float) $value;
			} elseif ( is_null( $value ) ) {
				$sanitized[ $clean_key ] = null;
			} else {
				$sanitized[ $clean_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}
}
