<?php
/**
 * Example: Disable including tools in initialize response
 *
 * Some MCP clients may prefer to make explicit tools/list calls
 * rather than receiving tools in the initialize response.
 * This example shows how to disable the automatic inclusion.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

// Disable including tools in initialize response.
add_filter( 'wp_mcp_ai_initialize_include_tools', '__return_false' );

/**
 * Example: Conditionally include tools based on client info
 *
 * You can also conditionally include tools based on the client
 * that's connecting. This example only includes tools for
 * OpenAI Agent Builder while forcing other clients to make
 * explicit tools/list calls.
 */
add_filter( 'wp_mcp_ai_initialize_include_tools', function( $include, $params, $request ) {
	// Check if this is OpenAI Agent Builder.
	if ( isset( $params['clientInfo']['name'] ) ) {
		$client_name = strtolower( $params['clientInfo']['name'] );

		// Include tools for OpenAI Agent Builder and similar clients.
		if ( strpos( $client_name, 'openai' ) !== false ||
		     strpos( $client_name, 'agent builder' ) !== false ||
		     strpos( $client_name, 'chatgpt' ) !== false ) {
			return true;
		}
	}

	// For other clients, don't include tools in initialize response.
	return false;
}, 10, 3 );
