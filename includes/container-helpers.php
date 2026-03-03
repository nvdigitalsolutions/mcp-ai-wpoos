<?php
/**
 * Global Container Helper Functions
 *
 * Provides easy access to the DI container and its services.
 * Part of Phase 4 refactoring (Milestone 10).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the global DI container instance
 *
 * @return WP_MCP_AI_Container Container instance.
 */
function wp_mcp_ai_container() {
	return WP_MCP_AI_Container::get_instance();
}

/**
 * Resolve a service from the container or get the container itself
 *
 * When called with a service ID: Returns the service instance.
 * When called without parameters: Returns the container itself.
 *
 * @param string $id Optional. Service identifier.
 * @return mixed Service instance if $id provided, container instance otherwise.
 */
function wp_mcp_ai( $id = null ) {
	$container = wp_mcp_ai_container();

	if ( null === $id ) {
		return $container;
	}

	return $container->get( $id );
}

/**
 * Make an instance with dependency injection
 *
 * @param string $class  Class name.
 * @param array  $params Additional parameters.
 * @return object Instance.
 */
function wp_mcp_ai_make( $class, $params = array() ) {
	return wp_mcp_ai_container()->make( $class, $params );
}

/**
 * Recursively sanitize an array of values (e.g. decoded JSON input).
 *
 * Strings are passed through sanitize_text_field(), integers and floats
 * are cast to their respective types, booleans are preserved, and
 * nested arrays are sanitized recursively.
 *
 * @since 1.1.3
 *
 * @param array $data The data to sanitize.
 * @return array Sanitized data.
 */
function wp_mcp_ai_sanitize_recursive( $data ) {
	if ( ! is_array( $data ) ) {
		return array();
	}

	$sanitized = array();
	foreach ( $data as $key => $value ) {
		$clean_key = is_int( $key ) ? $key : sanitize_text_field( $key );

		if ( is_array( $value ) ) {
			$sanitized[ $clean_key ] = wp_mcp_ai_sanitize_recursive( $value );
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
