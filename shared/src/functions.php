<?php
/**
 * Shared utility functions for WP MCP AI Core and Add-ons.
 *
 * This file contains common utility functions that are used by both
 * the Core plugin and Pro add-ons. It is licensed under GPL-3.0-or-later.
 *
 * @package WP_MCP_AI_Shared
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ============================================================================
 * CAPABILITY UTILITIES
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_check_capability' ) ) {
	/**
	 * Check if the current user has a specific capability.
	 *
	 * @param string   $capability Capability to check.
	 * @param int|null $user_id    User ID to check. Defaults to current user.
	 * @return bool Whether the user has the capability.
	 */
	function wp_mcp_ai_check_capability( $capability, $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		return user_can( $user_id, $capability );
	}
}

if ( ! function_exists( 'wp_mcp_ai_can_manage_tools' ) ) {
	/**
	 * Check if the current user can manage MCP tools.
	 *
	 * @param int|null $user_id User ID to check. Defaults to current user.
	 * @return bool Whether the user can manage tools.
	 */
	function wp_mcp_ai_can_manage_tools( $user_id = null ) {
		return wp_mcp_ai_check_capability( 'manage_options', $user_id );
	}
}

/**
 * ============================================================================
 * OPTION UTILITIES
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_get_option' ) ) {
	/**
	 * Get a plugin option with a default fallback.
	 *
	 * @param string $option_name   Option name.
	 * @param mixed  $default_value Default value if option doesn't exist.
	 * @return mixed Option value or default.
	 */
	function wp_mcp_ai_get_option( $option_name, $default_value = null ) {
		$value = get_option( $option_name, $default_value );

		/**
		 * Filter a plugin option value.
		 *
		 * @param mixed  $value         Option value.
		 * @param string $option_name   Option name.
		 * @param mixed  $default_value Default value.
		 */
		return apply_filters( 'wp_mcp_ai_option', $value, $option_name, $default_value );
	}
}

if ( ! function_exists( 'wp_mcp_ai_update_option' ) ) {
	/**
	 * Update a plugin option.
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $value       Option value.
	 * @return bool Whether the option was updated.
	 */
	function wp_mcp_ai_update_option( $option_name, $value ) {
		/**
		 * Filter a plugin option value before saving.
		 *
		 * @param mixed  $value       Option value to save.
		 * @param string $option_name Option name.
		 */
		$value = apply_filters( 'wp_mcp_ai_option_before_save', $value, $option_name );

		return update_option( $option_name, $value );
	}
}

/**
 * ============================================================================
 * SANITIZATION UTILITIES
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_sanitize_tool_slug' ) ) {
	/**
	 * Sanitize a tool slug.
	 *
	 * @param string $slug Tool slug.
	 * @return string Sanitized slug.
	 */
	function wp_mcp_ai_sanitize_tool_slug( $slug ) {
		$slug = strtolower( $slug );
		$slug = preg_replace( '/[^a-z0-9_-]/', '', $slug );
		return $slug;
	}
}

if ( ! function_exists( 'wp_mcp_ai_sanitize_json' ) ) {
	/**
	 * Sanitize JSON data.
	 *
	 * @param string $json JSON string.
	 * @return array|null Decoded and sanitized array, or null if invalid.
	 */
	function wp_mcp_ai_sanitize_json( $json ) {
		if ( ! is_string( $json ) ) {
			return null;
		}

		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}

		return $data;
	}
}

/**
 * ============================================================================
 * ARRAY UTILITIES
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_array_get' ) ) {
	/**
	 * Get a value from an array using dot notation.
	 *
	 * @param array  $arr           Array to search.
	 * @param string $key           Key in dot notation (e.g., 'parent.child.value').
	 * @param mixed  $default_value Default value if key doesn't exist.
	 * @return mixed Value or default.
	 */
	function wp_mcp_ai_array_get( $arr, $key, $default_value = null ) {
		if ( ! is_array( $arr ) ) {
			return $default_value;
		}

		if ( isset( $arr[ $key ] ) ) {
			return $arr[ $key ];
		}

		foreach ( explode( '.', $key ) as $segment ) {
			if ( ! is_array( $arr ) || ! array_key_exists( $segment, $arr ) ) {
				return $default_value;
			}

			$arr = $arr[ $segment ];
		}

		return $arr;
	}
}

if ( ! function_exists( 'wp_mcp_ai_array_set' ) ) {
	/**
	 * Set a value in an array using dot notation.
	 *
	 * @param array  $arr   Array to modify (passed by reference).
	 * @param string $key   Key in dot notation.
	 * @param mixed  $value Value to set.
	 * @return array Modified array.
	 */
	function wp_mcp_ai_array_set( &$arr, $key, $value ) {
		$keys      = explode( '.', $key );
		$keys_left = count( $keys );

		while ( $keys_left > 1 ) {
			$key       = array_shift( $keys );
			$keys_left = count( $keys );

			if ( ! isset( $arr[ $key ] ) || ! is_array( $arr[ $key ] ) ) {
				$arr[ $key ] = array();
			}

			$arr = &$arr[ $key ];
		}

		$arr[ array_shift( $keys ) ] = $value;

		return $arr;
	}
}

/**
 * ============================================================================
 * TYPE UTILITIES
 * ============================================================================
 */

if ( ! function_exists( 'wp_mcp_ai_ensure_array' ) ) {
	/**
	 * Ensure a value is an array.
	 *
	 * @param mixed $value Value to check.
	 * @return array Array value.
	 */
	function wp_mcp_ai_ensure_array( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( null === $value || '' === $value ) {
			return array();
		}

		return array( $value );
	}
}

if ( ! function_exists( 'wp_mcp_ai_is_json' ) ) {
	/**
	 * Check if a string is valid JSON.
	 *
	 * @param string $str String to check.
	 * @return bool Whether the string is valid JSON.
	 */
	function wp_mcp_ai_is_json( $str ) {
		if ( ! is_string( $str ) ) {
			return false;
		}

		json_decode( $str );
		return json_last_error() === JSON_ERROR_NONE;
	}
}
