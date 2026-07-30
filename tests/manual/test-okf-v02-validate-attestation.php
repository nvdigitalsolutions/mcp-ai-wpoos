<?php
/**
 * Smoke-test for okf_validate_attestation tool logic.
 * Tests the validation helpers in isolation.
 */
define( 'ABSPATH', true );

if ( ! function_exists( '__' ) ) { function __( $s, $d ) { return $s; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public $errors = array(); public function __construct( $c = '', $m = '' ) { $this->errors[ $c ][] = $m; } public function get_error_message() { $k = array_keys( $this->errors ); return isset( $this->errors[ $k[0] ] ) ? $this->errors[ $k[0] ][0] : ''; } } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() ); } }
if ( ! function_exists( 'wp_normalize_path' ) ) { function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); } }

// Stub tool traits.
if ( ! trait_exists( 'WP_MCP_AI_Tool_Envelope' ) ) {
	trait WP_MCP_AI_Tool_Envelope {
		public function format_success_response( $message, $data = array() ) {
			return array_merge( array( 'message' => $message ), $data );
		}
	}
}
if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
	trait WP_MCP_AI_Tool_Chat_Response { use WP_MCP_AI_Tool_Envelope; }
}
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	interface WP_MCP_AI_Tool_Interface {}
}

require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-parser.php';
require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-reader.php';
require_once __DIR__ . '/../../includes/tools/okf/class-wp-mcp-ai-tool-okf-validate-attestation.php';

// Can't fully instantiate the tool (needs WP_MCP_AI_Tool_Interface + trait),
// but we can test the private helpers via reflection.

$ref  = new ReflectionClass( 'WP_MCP_AI_Tool_OKF_Validate_Attestation' );
$tool = $ref->newInstanceWithoutConstructor();

$validate_type    = $ref->getMethod( 'validate_type' );
$validate_struct  = $ref->getMethod( 'validate_structure' );
$build_verdict    = $ref->getMethod( 'build_verdict' );
$extract_body     = $ref->getMethod( 'extract_computation_body' );

$validate_type->setAccessible( true );
$validate_struct->setAccessible( true );
$build_verdict->setAccessible( true );
$extract_body->setAccessible( true );

$passed = 0;
$failed = 0;

// Test 1: type validation — correct type
$r = $validate_type->invoke( $tool, array( 'type' => 'Attested Computation' ), 'test' );
echo 'Test 1 (correct type): ' . ( true === $r ? 'PASS' : 'FAIL' ) . "\n";
true === $r ? $passed++ : $failed++;

// Test 2: type validation — wrong type
$r = $validate_type->invoke( $tool, array( 'type' => 'Metric' ), 'test' );
echo 'Test 2 (wrong type): ' . ( is_wp_error( $r ) ? 'PASS' : 'FAIL' ) . "\n";
is_wp_error( $r ) ? $passed++ : $failed++;

// Test 3: structure — valid
$r = $validate_struct->invoke( $tool, array(
	'runtime'  => 'bigquery',
	'executor' => array( 'resource' => 'skills/run.sql' ),
	'attester' => array( 'resource' => 'attesters/check.py' ),
), 'test' );
echo 'Test 3 (valid structure): ' . ( empty( $r ) ? 'PASS' : 'FAIL' ) . "\n";
empty( $r ) ? $passed++ : $failed++;

// Test 4: structure — missing executor
$r = $validate_struct->invoke( $tool, array(
	'runtime'  => 'bigquery',
	'attester' => array( 'resource' => 'attesters/check.py' ),
), 'test' );
echo 'Test 4 (missing executor): ' . ( ! empty( $r ) ? 'PASS' : 'FAIL' ) . "\n";
! empty( $r ) ? $passed++ : $failed++;

// Test 5: structure — executor missing resource sub-key
$r = $validate_struct->invoke( $tool, array(
	'runtime'  => 'bigquery',
	'executor' => array(),
	'attester' => array( 'resource' => 'attesters/check.py' ),
), 'test' );
echo 'Test 5 (executor no resource): ' . ( ! empty( $r ) ? 'PASS' : 'FAIL' ) . "\n";
! empty( $r ) ? $passed++ : $failed++;

// Test 6: verdict — ready (valid + human-reviewed + stable)
$v = $build_verdict->invoke( $tool, array(), 'human-reviewed', false, 'stable' );
echo 'Test 6 (verdict ready): ' . ( $v['ready'] ? 'PASS' : 'FAIL' ) . "\n";
$v['ready'] ? $passed++ : $failed++;

// Test 7: verdict — deprecated blocks
$v = $build_verdict->invoke( $tool, array(), 'human-reviewed', false, 'deprecated' );
echo 'Test 7 (verdict deprecated): ' . ( ! $v['ready'] ? 'PASS' : 'FAIL' ) . "\n";
! $v['ready'] ? $passed++ : $failed++;

// Test 8: verdict — stale blocks
$v = $build_verdict->invoke( $tool, array(), 'human-reviewed', true, 'stable' );
echo 'Test 8 (verdict stale): ' . ( ! $v['ready'] ? 'PASS' : 'FAIL' ) . "\n";
! $v['ready'] ? $passed++ : $failed++;

// Test 9: verdict — draft blocks
$v = $build_verdict->invoke( $tool, array(), 'human-reviewed', false, 'draft' );
echo 'Test 9 (verdict draft): ' . ( ! $v['ready'] ? 'PASS' : 'FAIL' ) . "\n";
! $v['ready'] ? $passed++ : $failed++;

// Test 10: verdict — unverified is ready with warning
$v = $build_verdict->invoke( $tool, array(), 'unverified', false, 'stable' );
echo 'Test 10 (verdict unverified): ' . ( $v['ready'] ? 'PASS' : 'FAIL' ) . "\n";
$v['ready'] ? $passed++ : $failed++;

// Test 11: computation body extraction
$body = "# Schema\n\ncolumns here\n\n# Computation\n\nSELECT * FROM orders;\n";
$r = $extract_body->invoke( $tool, $body, array() );
echo 'Test 11 (extract computation): ' . ( 'SELECT * FROM orders;' === $r ? 'PASS' : 'FAIL: ' . $r ) . "\n";
'SELECT * FROM orders;' === $r ? $passed++ : $failed++;

// Test 12: computation body fallback
$r = $extract_body->invoke( $tool, "SELECT 1;", array() );
echo 'Test 12 (extract fallback): ' . ( 'SELECT 1;' === $r ? 'PASS' : 'FAIL: ' . $r ) . "\n";
'SELECT 1;' === $r ? $passed++ : $failed++;

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit( $failed > 0 ? 1 : 0 );
