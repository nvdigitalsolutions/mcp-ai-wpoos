<?php
/**
 * Helper: Retrieve a stored API key with transparent encryption.
 *
 * This is the canonical entry point all tools should use instead of
 * calling get_option('wp_mcp_ai_*_api_key') directly.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_mcp_ai_get_api_key' ) ) {
	/**
	 * Get an API key value with transparent encryption/decryption.
	 *
	 * @param string $key_suffix Option suffix (e.g. 'openai_api_key').
	 * @param mixed  $default    Default value if key is not set.
	 * @return mixed Decrypted value or default.
	 */
	function wp_mcp_ai_get_api_key( $key_suffix, $default = '' ) {
		if ( class_exists( 'WP_MCP_AI_Api_Key_Store' ) ) {
			$value = WP_MCP_AI_Api_Key_Store::get( $key_suffix );
			return '' !== $value ? $value : $default;
		}

		// Fallback for bootstrapping before Api_Key_Store is loaded.
		$raw = get_option( 'wp_mcp_ai_' . $key_suffix, $default );
		return $raw;
	}
}

if ( ! function_exists( 'wp_mcp_ai_set_api_key' ) ) {
	/**
	 * Store an API key value with transparent encryption.
	 *
	 * @param string $key_suffix Option suffix (e.g. 'openai_api_key').
	 * @param string $value      Plaintext value to encrypt and store.
	 * @return bool True on success.
	 */
	function wp_mcp_ai_set_api_key( $key_suffix, $value ) {
		if ( class_exists( 'WP_MCP_AI_Api_Key_Store' ) ) {
			return WP_MCP_AI_Api_Key_Store::set( $key_suffix, $value );
		}

		// Fallback for bootstrapping.
		if ( empty( $value ) ) {
			return delete_option( 'wp_mcp_ai_' . $key_suffix );
		}

		return update_option( 'wp_mcp_ai_' . $key_suffix, $value );
	}
}

if ( ! function_exists( 'wp_mcp_ai_validate_url' ) ) {
	/**
	 * Validate a URL is safe for outbound HTTP requests (SSRF protection).
	 *
	 * @param string $url URL to validate.
	 * @return true|WP_Error True if safe, WP_Error with details if blocked.
	 */
	function wp_mcp_ai_validate_url( $url ) {
		if ( class_exists( 'WP_MCP_AI_Url_Guard' ) ) {
			return WP_MCP_AI_Url_Guard::validate( $url );
		}

		return true; // Guard not loaded — allow (bootstrap context).
	}
}

if ( ! function_exists( 'wp_mcp_ai_acquire_concurrency_slot' ) ) {
	/**
	 * Acquire a concurrency slot for a resource-intensive operation.
	 *
	 * @param string $operation_type Operation type (e.g. 'image_generation').
	 * @return true|WP_Error True if slot acquired.
	 */
	function wp_mcp_ai_acquire_concurrency_slot( $operation_type ) {
		if ( class_exists( 'WP_MCP_AI_Concurrency_Guard' ) ) {
			return WP_MCP_AI_Concurrency_Guard::acquire( $operation_type );
		}

		return true; // Guard not loaded — allow.
	}
}

if ( ! function_exists( 'wp_mcp_ai_release_concurrency_slot' ) ) {
	/**
	 * Release a concurrency slot.
	 *
	 * @param string $operation_type Operation type.
	 * @return void
	 */
	function wp_mcp_ai_release_concurrency_slot( $operation_type ) {
		if ( class_exists( 'WP_MCP_AI_Concurrency_Guard' ) ) {
			WP_MCP_AI_Concurrency_Guard::release( $operation_type );
		}
	}
}
