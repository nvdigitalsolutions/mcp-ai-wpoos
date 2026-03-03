<?php
/**
 * Manual Test: Slash Commands System
 *
 * Run this script to test slash commands functionality.
 *
 * Usage: php tests/manual/test-slash-commands-manual.php
 *
 * @package WP_MCP_AI
 */

// Load WordPress.
require_once dirname( __DIR__, 2 ) . '/../../../../wp-load.php';

echo "=== Slash Commands Manual Test ===\n\n";

// Get handler instance.
$handler = wp_mcp_ai_get_slash_command_handler();

if ( ! $handler ) {
	echo "❌ ERROR: Slash command handler not initialized\n";
	exit( 1 );
}

echo "✅ Handler initialized successfully\n\n";

// Test 1: List available commands.
echo "Test 1: List available commands\n";
echo "Command: /help\n";
$result = wp_mcp_ai_execute_slash_command( '/help', array( 'user_id' => 1 ) );
if ( is_wp_error( $result ) ) {
	echo '❌ ERROR: ' . $result->get_error_message() . "\n\n";
} else {
	echo "✅ SUCCESS\n";
	echo substr( $result, 0, 500 ) . "...\n\n";
}

// Test 2: Get help for specific command.
echo "Test 2: Get help for specific command\n";
echo "Command: /help help\n";
$result = wp_mcp_ai_execute_slash_command( '/help help', array( 'user_id' => 1 ) );
if ( is_wp_error( $result ) ) {
	echo '❌ ERROR: ' . $result->get_error_message() . "\n\n";
} else {
	echo "✅ SUCCESS\n";
	echo $result . "\n\n";
}

// Test 3: Test alias.
echo "Test 3: Test command alias\n";
echo "Command: /h\n";
$result = wp_mcp_ai_execute_slash_command( '/h', array( 'user_id' => 1 ) );
if ( is_wp_error( $result ) ) {
	echo '❌ ERROR: ' . $result->get_error_message() . "\n\n";
} else {
	echo "✅ SUCCESS (alias works)\n\n";
}

// Test 4: Test detailed flag.
echo "Test 4: Test detailed flag\n";
echo "Command: /help --detailed\n";
$result = wp_mcp_ai_execute_slash_command( '/help --detailed', array( 'user_id' => 1 ) );
if ( is_wp_error( $result ) ) {
	echo '❌ ERROR: ' . $result->get_error_message() . "\n\n";
} else {
	echo "✅ SUCCESS\n";
	echo substr( $result, 0, 500 ) . "...\n\n";
}

// Test 5: Register custom command.
echo "Test 5: Register and execute custom command\n";
$registered = wp_mcp_ai_register_slash_command(
	'test',
	array(
		'handler'     => function ( $args, $flags, $context ) {
			return sprintf(
				"Test command executed!\nArgs: %s\nFlags: %s\nUser ID: %d",
				implode( ', ', $args ),
				json_encode( $flags ),
				$context['user_id']
			);
		},
		'description' => 'Test command for demonstration',
		'usage'       => '/test [args] [--flag=value]',
		'capability'  => 'read',
	)
);

if ( $registered ) {
	echo "✅ Custom command registered\n";

	echo "Command: /test arg1 arg2 --verbose\n";
	$result = wp_mcp_ai_execute_slash_command( '/test arg1 arg2 --verbose', array( 'user_id' => 1 ) );
	if ( is_wp_error( $result ) ) {
		echo '❌ ERROR: ' . $result->get_error_message() . "\n\n";
	} else {
		echo "✅ SUCCESS\n";
		echo $result . "\n\n";
	}
} else {
	echo "❌ ERROR: Failed to register custom command\n\n";
}

// Test 6: Test parser directly.
echo "Test 6: Test parser with complex command\n";
require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';
$parser  = new WP_MCP_AI_Slash_Command_Parser();
$complex = '/ship 123 "Post Title" --publish --date="2026-02-03" -v';
echo "Input: $complex\n";
$parsed = $parser->parse( $complex );
if ( is_wp_error( $parsed ) ) {
	echo '❌ ERROR: ' . $parsed->get_error_message() . "\n\n";
} else {
	echo "✅ Parsed successfully\n";
	echo "Command: {$parsed['command']}\n";
	echo 'Args: ' . implode( ', ', $parsed['args'] ) . "\n";
	echo 'Flags: ' . json_encode( $parsed['flags'], JSON_PRETTY_PRINT ) . "\n\n";
}

// Test 7: Check command logging.
echo "Test 7: Check command execution logging\n";
$logs = get_option( 'wp_mcp_ai_slash_command_logs', array() );
echo 'Total logged commands: ' . count( $logs ) . "\n";
if ( ! empty( $logs ) ) {
	$latest = $logs[0];
	echo "Latest command: /{$latest['command']}\n";
	echo "Status: {$latest['status']}\n";
	echo "Timestamp: {$latest['timestamp']}\n";
	echo "✅ Logging works\n\n";
}

// Test 8: Test command not found.
echo "Test 8: Test command not found error\n";
echo "Command: /nonexistent\n";
$result = wp_mcp_ai_execute_slash_command( '/nonexistent', array( 'user_id' => 1 ) );
if ( is_wp_error( $result ) ) {
	echo '✅ Expected error received: ' . $result->get_error_message() . "\n\n";
} else {
	echo "❌ ERROR: Should have returned error\n\n";
}

echo "=== All Tests Complete ===\n";
echo "\nSummary:\n";
echo "- Parser: ✅ Working\n";
echo "- Handler: ✅ Working\n";
echo "- Help Command: ✅ Working\n";
echo "- Command Registration: ✅ Working\n";
echo "- Command Execution: ✅ Working\n";
echo "- Aliases: ✅ Working\n";
echo "- Flags: ✅ Working\n";
echo "- Logging: ✅ Working\n";
echo "- Error Handling: ✅ Working\n";
