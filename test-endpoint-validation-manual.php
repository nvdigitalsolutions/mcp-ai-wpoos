<?php
/**
 * Manual test script for endpoint validation.
 *
 * This script demonstrates how to test the endpoint validation
 * by making example requests with various invalid parameters.
 *
 * Usage: wp eval-file test-endpoint-validation-manual.php
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	die( 'This script can only be run via WP-CLI.' );
}

WP_CLI::line( 'Testing REST API Endpoint Validation' );
WP_CLI::line( '=====================================' );
WP_CLI::line( '' );

/**
 * Make a REST API request and display the result.
 *
 * @param string $method  HTTP method.
 * @param string $route   REST route.
 * @param array  $data    Request data.
 * @param string $description Test description.
 */
function test_endpoint( $method, $route, $data, $description ) {
	WP_CLI::line( WP_CLI::colorize( "%B{$description}%n" ) );
	WP_CLI::line( "  Route: {$method} {$route}" );

	$request = new WP_REST_Request( $method, $route );
	$request->set_header( 'Content-Type', 'application/json' );
	
	if ( ! empty( $data ) ) {
		$request->set_body( wp_json_encode( $data ) );
	}

	// Set admin user for permission.
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( ! empty( $admin ) ) {
		wp_set_current_user( $admin[0]->ID );
	}

	$server   = rest_get_server();
	$response = $server->dispatch( $request );
	$status   = $response->get_status();
	$body     = $response->get_data();

	$status_color = $status >= 200 && $status < 300 ? '%G' : '%R';
	WP_CLI::line( WP_CLI::colorize( "  Status: {$status_color}{$status}%n" ) );

	if ( isset( $body['code'] ) ) {
		WP_CLI::line( "  Code: {$body['code']}" );
	}

	if ( isset( $body['message'] ) ) {
		WP_CLI::line( "  Message: {$body['message']}" );
	}

	if ( isset( $body['actions'] ) && is_array( $body['actions'] ) ) {
		WP_CLI::line( WP_CLI::colorize( '  %YActionable Guidance:%n' ) );
		foreach ( $body['actions'] as $key => $action ) {
			WP_CLI::line( "    - {$action}" );
		}
	}

	// For MCP errors.
	if ( isset( $body['error'] ) ) {
		WP_CLI::line( "  Error Code: {$body['error']['code']}" );
		WP_CLI::line( "  Error Message: {$body['error']['message']}" );
		
		if ( isset( $body['error']['data']['actions'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '  %YActionable Guidance:%n' ) );
			foreach ( $body['error']['data']['actions'] as $key => $action ) {
				WP_CLI::line( "    - {$action}" );
			}
		}
	}

	WP_CLI::line( '' );
}

// Test 1: Empty messages array.
test_endpoint(
	'POST',
	'/mcp-ai/v1/chat',
	array( 'messages' => array() ),
	'Test 1: Empty messages array'
);

// Test 2: Message without role.
test_endpoint(
	'POST',
	'/mcp-ai/v1/chat',
	array(
		'messages' => array(
			array( 'content' => 'Hello' ),
		),
	),
	'Test 2: Message without role'
);

// Test 3: Invalid role value.
test_endpoint(
	'POST',
	'/mcp-ai/v1/chat',
	array(
		'messages' => array(
			array(
				'role'    => 'invalid_role',
				'content' => 'Hello',
			),
		),
	),
	'Test 3: Invalid role value'
);

// Test 4: Tool message without tool_call_id.
test_endpoint(
	'POST',
	'/mcp-ai/v1/chat',
	array(
		'messages' => array(
			array(
				'role'    => 'tool',
				'content' => 'Tool result',
			),
		),
	),
	'Test 4: Tool message without tool_call_id'
);

// Test 5: Attachment without file reference.
test_endpoint(
	'POST',
	'/mcp-ai/v1/chat',
	array(
		'messages'    => array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		),
		'attachments' => array(
			array( 'name' => 'file.txt' ),
		),
	),
	'Test 5: Attachment without file_id or url'
);

// Test 6: Invalid URL in attachment.
test_endpoint(
	'POST',
	'/mcp-ai/v1/chat',
	array(
		'messages'    => array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		),
		'attachments' => array(
			array( 'url' => 'not-a-valid-url' ),
		),
	),
	'Test 6: Invalid URL in attachment'
);

// Test 7: MCP - Empty request body.
test_endpoint(
	'POST',
	'/mcp-ai/v1/mcp',
	null,
	'Test 7: MCP - Empty request body'
);

// Test 8: MCP - Missing jsonrpc field.
test_endpoint(
	'POST',
	'/mcp-ai/v1/mcp',
	array(
		'id'     => 1,
		'method' => 'initialize',
	),
	'Test 8: MCP - Missing jsonrpc field'
);

// Test 9: MCP - Unknown method.
test_endpoint(
	'POST',
	'/mcp-ai/v1/mcp',
	array(
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'unknown/method',
	),
	'Test 9: MCP - Unknown method'
);

// Test 10: MCP tools/call - Missing name parameter.
test_endpoint(
	'POST',
	'/mcp-ai/v1/mcp',
	array(
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'tools/call',
		'params'  => array(
			'arguments' => array( 'test' => 'value' ),
		),
	),
	'Test 10: MCP tools/call - Missing name parameter'
);

// Test 11: Valid request.
test_endpoint(
	'POST',
	'/mcp-ai/v1/chat',
	array(
		'messages' => array(
			array(
				'role'    => 'user',
				'content' => 'Hello, this is a valid message!',
			),
		),
	),
	'Test 11: Valid chat request (should succeed or fail with different error)'
);

WP_CLI::success( 'Endpoint validation tests completed!' );
