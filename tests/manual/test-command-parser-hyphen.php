<?php
/**
 * Manual Test Script for Command Parser Hyphen Fix
 *
 * Run this from command line to verify the hyphen parsing fix.
 * Usage: php tests/manual/test-command-parser-hyphen.php
 */

// Define WordPress constants if not already defined.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __FILE__ ) . '/../../' );
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
	define( 'WP_MCP_AI_PATH', dirname( __FILE__ ) . '/../../' );
}

// Load the parser class.
require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';

// Initialize parser.
$parser = new WP_MCP_AI_Slash_Command_Parser();

echo "=== Command Parser Hyphen Fix Test ===\n\n";

// Test cases.
$test_cases = array(
	'/optimize-perf'                       => 'optimize-perf',
	'/optimize-perf --dry-run'             => 'optimize-perf',
	'/clean-content --post-id=123'         => 'clean-content',
	'/sync-docs'                           => 'sync-docs',
	'/next-task --filter=drafts'           => 'next-task',
	'/help'                                => 'help',
	'/test_command'                        => 'test_command',
	'/my-multi-word-command'               => 'my-multi-word-command',
	'/test-123-command'                    => 'test-123-command',
);

$passed = 0;
$failed = 0;

foreach ( $test_cases as $input => $expected_command ) {
	$result = $parser->parse( $input );
	
	if ( is_wp_error( $result ) ) {
		echo "❌ FAIL: $input\n";
		echo "   Error: " . $result->get_error_message() . "\n";
		$failed++;
	} elseif ( $result['command'] !== $expected_command ) {
		echo "❌ FAIL: $input\n";
		echo "   Expected: $expected_command\n";
		echo "   Got: " . $result['command'] . "\n";
		$failed++;
	} else {
		echo "✅ PASS: $input => {$result['command']}\n";
		$passed++;
		
		// Show details for commands with arguments.
		if ( ! empty( $result['flags'] ) || ! empty( $result['args'] ) ) {
			if ( ! empty( $result['flags'] ) ) {
				echo "   Flags: " . json_encode( $result['flags'] ) . "\n";
			}
			if ( ! empty( $result['args'] ) ) {
				echo "   Args: " . json_encode( $result['args'] ) . "\n";
			}
		}
	}
}

echo "\n=== Test Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total:  " . ( $passed + $failed ) . "\n";

if ( $failed === 0 ) {
	echo "\n✅ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n❌ Some tests failed!\n";
	exit( 1 );
}

/**
 * Stub is_wp_error function for standalone testing.
 *
 * @param mixed $thing Thing to check.
 * @return bool True if WP_Error.
 */
function is_wp_error( $thing ) {
	return ( $thing instanceof WP_Error );
}

/**
 * Stub WP_Error class for standalone testing.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		
		public function __construct( $code, $message ) {
			$this->code = $code;
			$this->message = $message;
		}
		
		public function get_error_code() {
			return $this->code;
		}
		
		public function get_error_message() {
			return $this->message;
		}
	}
}

/**
 * Stub translation function.
 *
 * @param string $text Text to translate.
 * @param string $domain Text domain.
 * @return string Translated text.
 */
function __( $text, $domain = 'default' ) {
	return $text;
}
