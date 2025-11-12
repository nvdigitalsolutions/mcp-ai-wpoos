<?php
/**
 * Tool Response Helper Functions.
 *
 * Provides utility functions for working with generic tool responses.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-response-adapter.php';

/**
 * Extract a generic tool response from a provider-specific response.
 *
 * This is the main entry point for transforming AI provider responses into
 * a standardized format. It acts as a factory that dispatches to the
 * appropriate adapter based on the provider identifier.
 *
 * @param array|WP_Error $raw_response     The raw response from an AI provider.
 * @param string         $provider_identifier Provider name (e.g., 'openai', 'gemini').
 * @return WP_MCP_AI_Generic_Tool_Response The standardized response object.
 *
 * @throws InvalidArgumentException If the provider identifier is not supported.
 */
function wp_mcp_ai_extract_generic_tool_response( $raw_response, $provider_identifier ) {
	$provider_identifier = sanitize_key( $provider_identifier );

	// Get the appropriate adapter for the provider.
	$adapter = WP_MCP_AI_Tool_Response_Adapter::get_adapter_for_provider( $provider_identifier );

	if ( null === $adapter ) {
		// Provider not supported - create a generic error response.
		$error = new WP_Error(
			'wp_mcp_ai_unsupported_provider',
			sprintf(
				/* translators: %s: Provider identifier */
				__( 'Unsupported AI provider: %s', 'wp-mcp-ai' ),
				$provider_identifier
			),
			array( 'status' => 500 )
		);

		return WP_MCP_AI_Tool_Response_Adapter::from_openai( $error );
	}

	// Call the appropriate adapter method.
	return call_user_func( $adapter, $raw_response );
}

/**
 * Check if a provider is supported by the generic response system.
 *
 * @param string $provider_identifier Provider name to check.
 * @return bool True if supported, false otherwise.
 */
function wp_mcp_ai_is_provider_supported( $provider_identifier ) {
	$provider_identifier = sanitize_key( $provider_identifier );
	$adapter             = WP_MCP_AI_Tool_Response_Adapter::get_adapter_for_provider( $provider_identifier );

	return null !== $adapter;
}
