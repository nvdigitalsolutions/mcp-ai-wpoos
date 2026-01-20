<?php
/**
 * Manual test script for Simple Settings Saver
 * 
 * This script tests the Simple Settings Saver functionality without requiring
 * WordPress to be installed. Run from command line: php test-simple-settings-save-manual.php
 */

// Mock WordPress functions for testing
function sanitize_text_field( $str ) {
	return trim( strip_tags( $str ) );
}

function sanitize_textarea_field( $str ) {
	return trim( strip_tags( $str ) );
}

function sanitize_email( $str ) {
	return filter_var( $str, FILTER_SANITIZE_EMAIL );
}

function esc_url_raw( $str ) {
	return filter_var( $str, FILTER_SANITIZE_URL );
}

function absint( $val ) {
	return abs( (int) $val );
}

function apply_filters( $tag, $value ) {
	return $value;
}

// Load the Simple Settings Saver class
require_once __DIR__ . '/includes/admin/class-wp-mcp-ai-simple-settings-saver.php';

// Test 1: Initialize field types
echo "Test 1: Initialize field types\n";
WP_MCP_AI_Simple_Settings_Saver::init_field_types();
echo "✓ Field types initialized\n\n";

// Test 2: Get field type
echo "Test 2: Get field type\n";
$type = WP_MCP_AI_Simple_Settings_Saver::get_field_type( 'enable_logging' );
echo "Field type for 'enable_logging': " . $type . "\n";
assert( $type === 'checkbox', 'enable_logging should be checkbox' );
echo "✓ Field type retrieval works\n\n";

// Test 3: Sanitize text field
echo "Test 3: Sanitize text field\n";
$reflection = new ReflectionClass( 'WP_MCP_AI_Simple_Settings_Saver' );
$method = $reflection->getMethod( 'sanitize_field' );
$method->setAccessible( true );

$result = $method->invokeArgs( null, [ '<script>alert("xss")</script>Hello', 'text', 'test_key', [] ] );
echo "Sanitized text: " . $result . "\n";
assert( strpos( $result, '<script>' ) === false, 'XSS should be filtered' );
echo "✓ Text sanitization works\n\n";

// Test 4: Sanitize checkbox
echo "Test 4: Sanitize checkbox\n";
$result = $method->invokeArgs( null, [ '1', 'checkbox', 'enable_logging', [] ] );
echo "Checkbox value (should be true): " . ( $result ? 'true' : 'false' ) . "\n";
assert( $result === true, 'Checkbox should be true' );
echo "✓ Checkbox sanitization works\n\n";

// Test 5: Sanitize password (preserve existing)
echo "Test 5: Sanitize password (preserve existing)\n";
$existing = [ 'openai_api_key' => 'sk-existing-key-123' ];
$result = $method->invokeArgs( null, [ '', 'password', 'openai_api_key', $existing ] );
echo "Password value (should preserve existing): " . $result . "\n";
assert( $result === 'sk-existing-key-123', 'Empty password should preserve existing' );
echo "✓ Password preservation works\n\n";

// Test 6: Sanitize password (set new value)
echo "Test 6: Sanitize password (set new value)\n";
$existing = [ 'openai_api_key' => 'sk-old-key' ];
$result = $method->invokeArgs( null, [ 'sk-new-key-456', 'password', 'openai_api_key', $existing ] );
echo "Password value (should be new): " . $result . "\n";
assert( $result === 'sk-new-key-456', 'Non-empty password should set new value' );
echo "✓ Password update works\n\n";

// Test 7: Sanitize URL
echo "Test 7: Sanitize URL\n";
$result = $method->invokeArgs( null, [ 'http://localhost:11434', 'url', 'ollama_endpoint_url', [] ] );
echo "URL value: " . $result . "\n";
assert( $result === 'http://localhost:11434', 'URL should be preserved' );
echo "✓ URL sanitization works\n\n";

// Test 8: Sanitize email
echo "Test 8: Sanitize email\n";
$result = $method->invokeArgs( null, [ 'test@example.com', 'email', 'test_email', [] ] );
echo "Email value: " . $result . "\n";
assert( $result === 'test@example.com', 'Email should be valid' );
echo "✓ Email sanitization works\n\n";

// Test 9: Sanitize number
echo "Test 9: Sanitize number\n";
$result = $method->invokeArgs( null, [ '300', 'number', 'request_timeout', [] ] );
echo "Number value: " . $result . "\n";
assert( $result === 300, 'Number should be integer' );
echo "✓ Number sanitization works\n\n";

// Test 10: Sanitize float
echo "Test 10: Sanitize float\n";
$result = $method->invokeArgs( null, [ '7.5', 'float', 'cloudflare_image_guidance', [] ] );
echo "Float value: " . $result . "\n";
assert( $result === 7.5, 'Float should be preserved' );
echo "✓ Float sanitization works\n\n";

echo "\n=================================\n";
echo "All tests passed! ✓\n";
echo "=================================\n";
echo "\nThe Simple Settings Saver is working correctly.\n";
echo "It properly sanitizes all field types and preserves password fields.\n";
