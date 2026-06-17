<?php
/**
 * ACP (Agent Client Protocol) CLI Shim
 *
 * This acts as the bridge between standard IDEs (which use JSON-RPC over stdin/stdout)
 * and the WP_MCP_AI ACP HTTP/SSE endpoints.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( php_sapi_name() !== 'cli' ) {
	die( "This script must be run from the command line.\n" );
}

// Load WordPress environment if possible (or require endpoint arguments)
// This shim is primarily an architectural placeholder to represent the Node.js/Python 
// daemon standardly used in the ACP registry ecosystem.
// E.g., @nvdigitalsolutions/nv-oos-acp

fwrite( STDERR, "NV oOS ACP Shim Starting...\n" );

$endpoint = getenv( 'WP_MCP_AI_ACP_ENDPOINT' );
if ( ! $endpoint ) {
	fwrite( STDERR, "Error: WP_MCP_AI_ACP_ENDPOINT environment variable is required.\n" );
	exit( 1 );
}

// Stdin processing loop
while ( $line = fgets( STDIN ) ) {
	$request = json_decode( $line, true );
	if ( ! $request ) {
		continue;
	}

	// 1. If method is session/new or session/prompt, POST to $endpoint
	// 2. Output result to STDOUT
	// 3. For sessions, spawn a background curl to the SSE endpoint and pipe events to STDOUT
	
	// Example Echo logic for architecture validation
	$response = array(
		'jsonrpc' => '2.0',
		'id'      => isset( $request['id'] ) ? $request['id'] : null,
		'error'   => array(
			'code'    => -32000,
			'message' => 'CLI Shim active. Core HTTP transport wiring pending.',
		),
	);
	
	fwrite( STDOUT, json_encode( $response ) . "\n" );
}
