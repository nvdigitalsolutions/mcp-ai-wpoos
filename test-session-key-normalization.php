<?php
/**
 * Test script to verify session_key normalization consistency
 * 
 * This script tests whether different session key sanitization methods
 * produce identical output.
 */

require_once __DIR__ . '/includes/class-wp-mcp-ai-chat-transcript-recorder.php';
require_once __DIR__ . '/includes/rest/class-wp-mcp-ai-rest-validator.php';
require_once __DIR__ . '/includes/class-wp-mcp-ai-rest.php';

// Test various session key values
$test_keys = array(
	'simple-key',
	'simple_key',
	'wp-mcp-ai-session-12345',
	'test session with spaces',
	'test!@#$%^&*()key',
	'UPPERCASE-key',
	'mixedCASE_key-123',
	'  leading-and-trailing-spaces  ',
	'key with\nnewlines\rand\ttabs',
	str_repeat('x', 100), // Long key
);

echo "Testing session_key normalization consistency\n";
echo str_repeat('=', 80) . "\n\n";

foreach ($test_keys as $original) {
	echo "Original: " . var_export($original, true) . "\n";
	
	// Method 1: sanitize_session_key_param (used in SAVE endpoint)
	$validator = new WP_MCP_AI_REST_Validator();
	$method1 = $validator->sanitize_session_key_param($original);
	
	// Method 2: normalise_transcript_session_key (used in RETRIEVE endpoint)
	$rest = new WP_MCP_AI_REST();
	$method2 = $rest->normalise_transcript_session_key($original);
	
	// Method 3: normalise_session_key in recorder (used when STORING)
	// We can't call this directly as it's protected, but we can simulate it
	$trimmed = trim((string) $original);
	$method3 = preg_replace('/[^a-zA-Z0-9_-]/', '', $trimmed);
	$method3 = substr($method3, 0, 96);
	
	echo "Method 1 (sanitize_session_key_param):     '$method1'\n";
	echo "Method 2 (normalise_transcript_session_key): '$method2'\n";
	echo "Method 3 (normalise_session_key simulation): '$method3'\n";
	
	if ($method1 === $method2 && $method2 === $method3) {
		echo "✓ All methods produce identical output\n";
	} else {
		echo "✗ MISMATCH DETECTED!\n";
		if ($method1 !== $method2) {
			echo "  - Method 1 != Method 2\n";
		}
		if ($method2 !== $method3) {
			echo "  - Method 2 != Method 3\n";
		}
		if ($method1 !== $method3) {
			echo "  - Method 1 != Method 3\n";
		}
	}
	
	echo "\n";
}

echo str_repeat('=', 80) . "\n";
echo "Test complete\n";
