<?php
/**
 * Manual test script for agentic loop parameter validation.
 *
 * This script demonstrates how the agentic loop handles various
 * tool argument scenarios including malformed JSON and invalid parameters.
 *
 * Usage: wp eval-file test-agentic-parameters-manual.php
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	die( 'This script can only be run via WP-CLI.' );
}

WP_CLI::line( 'Testing Agentic Loop Parameter Validation' );
WP_CLI::line( '=========================================' );
WP_CLI::line( '' );

// Get or create a test assistant.
$assistants = get_posts(
	array(
		'post_type'      => 'mcp_ai_assistant',
		'posts_per_page' => 1,
		'post_status'    => 'publish',
	)
);

if ( empty( $assistants ) ) {
	WP_CLI::error( 'No assistants found. Please create an assistant first.' );
}

$assistant = $assistants[0];
$assistant_id = $assistant->ID;

WP_CLI::line( WP_CLI::colorize( "%GUsing assistant: {$assistant->post_title} (ID: {$assistant_id})%n" ) );
WP_CLI::line( '' );

// Set admin user for permission.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! empty( $admin ) ) {
	wp_set_current_user( $admin[0]->ID );
	WP_CLI::line( WP_CLI::colorize( "%GLogged in as: {$admin[0]->user_login}%n" ) );
	WP_CLI::line( '' );
}

/**
 * Test parameter handling scenario.
 *
 * @param string $description Test description.
 * @param array  $tool_call   Tool call data with arguments.
 * @param int    $assistant_id Assistant ID.
 */
function test_parameter_scenario( $description, $tool_call, $assistant_id ) {
	WP_CLI::line( WP_CLI::colorize( "%B{$description}%n" ) );
	
	// Get the REST controller instance.
	$rest = WP_MCP_AI_REST::get_instance();
	
	// Use reflection to access protected method.
	$reflection = new ReflectionClass( $rest );
	$method = $reflection->getMethod( 'execute_tool_call_internal' );
	$method->setAccessible( true );
	
	// Get assistant config.
	$assistant_config = array(
		'tools' => get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true ),
	);
	
	if ( empty( $assistant_config['tools'] ) ) {
		$assistant_config['tools'] = array( 'get_open_meteo_forecast', 'get_current_time' );
	}
	
	// Create a dummy request.
	$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
	
	// Execute the tool call.
	try {
		$result = $method->invoke(
			$rest,
			$tool_call,
			$assistant_id,
			$assistant_config,
			get_current_user_id(),
			$request
		);
		
		if ( is_wp_error( $result ) ) {
			WP_CLI::line( WP_CLI::colorize( "  %RResult: ERROR%n" ) );
			WP_CLI::line( "  Code: " . $result->get_error_code() );
			WP_CLI::line( "  Message: " . $result->get_error_message() );
		} elseif ( is_string( $result ) && strpos( $result, 'invalid' ) !== false ) {
			WP_CLI::line( WP_CLI::colorize( "  %RResult: ERROR (string)%n" ) );
			WP_CLI::line( "  Message: " . substr( $result, 0, 200 ) );
		} else {
			WP_CLI::line( WP_CLI::colorize( "  %GResult: SUCCESS%n" ) );
			if ( is_string( $result ) ) {
				WP_CLI::line( "  Output: " . substr( $result, 0, 200 ) . ( strlen( $result ) > 200 ? '...' : '' ) );
			} elseif ( is_array( $result ) ) {
				WP_CLI::line( "  Output: " . wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			}
		}
	} catch ( Exception $e ) {
		WP_CLI::line( WP_CLI::colorize( "  %RException: {$e->getMessage()}%n" ) );
	}
	
	WP_CLI::line( '' );
}

// Test 1: Malformed JSON.
WP_CLI::line( WP_CLI::colorize( "%Y--- Test 1: Malformed JSON Arguments ---%n" ) );
test_parameter_scenario(
	'Tool with malformed JSON (should fail with clear error)',
	array(
		'id'       => 'call_test_123',
		'type'     => 'function',
		'function' => array(
			'name'      => 'get_open_meteo_forecast',
			'arguments' => '{invalid json here}',
		),
	),
	$assistant_id
);

// Test 2: Empty string arguments (valid).
WP_CLI::line( WP_CLI::colorize( "%Y--- Test 2: Empty String Arguments ---%n" ) );
test_parameter_scenario(
	'Tool with empty string arguments (should succeed - no args needed)',
	array(
		'id'       => 'call_test_456',
		'type'     => 'function',
		'function' => array(
			'name'      => 'get_current_time',
			'arguments' => '',
		),
	),
	$assistant_id
);

// Test 3: Valid JSON but not an object.
WP_CLI::line( WP_CLI::colorize( "%Y--- Test 3: Non-Object JSON Arguments ---%n" ) );
test_parameter_scenario(
	'Tool with JSON string instead of object (should fail with clear error)',
	array(
		'id'       => 'call_test_789',
		'type'     => 'function',
		'function' => array(
			'name'      => 'get_open_meteo_forecast',
			'arguments' => '"just a string"',
		),
	),
	$assistant_id
);

// Test 4: Valid JSON object.
WP_CLI::line( WP_CLI::colorize( "%Y--- Test 4: Valid JSON Object Arguments ---%n" ) );
test_parameter_scenario(
	'Tool with valid JSON object (should succeed)',
	array(
		'id'       => 'call_test_abc',
		'type'     => 'function',
		'function' => array(
			'name'      => 'get_open_meteo_forecast',
			'arguments' => wp_json_encode(
				array(
					'latitude'  => 48.8566,
					'longitude' => 2.3522,
					'hourly'    => 'temperature_2m',
				)
			),
		),
	),
	$assistant_id
);

// Test 5: Arguments already as array (not JSON string).
WP_CLI::line( WP_CLI::colorize( "%Y--- Test 5: Array Arguments (Not JSON String) ---%n" ) );
test_parameter_scenario(
	'Tool with arguments already as array (should succeed)',
	array(
		'id'       => 'call_test_def',
		'type'     => 'function',
		'function' => array(
			'name'      => 'get_open_meteo_forecast',
			'arguments' => array(
				'latitude'  => 48.8566,
				'longitude' => 2.3522,
				'hourly'    => 'temperature_2m',
			),
		),
	),
	$assistant_id
);

// Test 6: Whitespace-only string (should be treated as empty/no args).
WP_CLI::line( WP_CLI::colorize( "%Y--- Test 6: Whitespace-Only Arguments ---%n" ) );
test_parameter_scenario(
	'Tool with whitespace-only arguments (should succeed - treated as no args)',
	array(
		'id'       => 'call_test_ghi',
		'type'     => 'function',
		'function' => array(
			'name'      => 'get_current_time',
			'arguments' => '   ',
		),
	),
	$assistant_id
);

WP_CLI::success( 'All parameter validation tests completed!' );
WP_CLI::line( '' );
WP_CLI::line( 'Check the logs with: wp option get wp_mcp_ai_recent_errors' );
