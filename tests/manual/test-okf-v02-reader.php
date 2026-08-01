<?php
// phpcs:ignoreFile -- Standalone manual test with WordPress stubs for offline execution.
define( 'ABSPATH', true );

// Stubs.
if ( ! function_exists( '__' ) ) { function __( $s, $d ) { return $s; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return false; } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function __construct( $c = '', $m = '' ) {} } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); } }

require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-parser.php';
require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-reader.php';

$reader = new WP_MCP_AI_OKF_Reader( '/tmp' );

// Trust tier: unverified
$tier = $reader->get_trust_tier( array() );
echo 'unverified: ' . ( $tier === 'unverified' ? 'PASS' : 'FAIL: ' . $tier ) . "\n";

// Trust tier: human-reviewed
$tier = $reader->get_trust_tier( array( 'verified' => array(
	array( 'by' => 'human:jsmith@acme', 'at' => '2026-07-01' )
) ) );
echo 'human-reviewed: ' . ( $tier === 'human-reviewed' ? 'PASS' : 'FAIL: ' . $tier ) . "\n";

// Trust tier: machine-confirmed
$tier = $reader->get_trust_tier( array( 'verified' => array(
	array( 'by' => 'nightly-checks', 'at' => '2026-07-01' )
) ) );
echo 'machine-confirmed: ' . ( $tier === 'machine-confirmed' ? 'PASS' : 'FAIL: ' . $tier ) . "\n";

// Stale checks
echo 'stale (past date): ' . ( $reader->is_stale( array( 'stale_after' => '2000-01-01' ) ) ? 'PASS' : 'FAIL' ) . "\n";
echo 'not stale (future): ' . ( ! $reader->is_stale( array( 'stale_after' => '2099-12-31' ) ) ? 'PASS' : 'FAIL' ) . "\n";
echo 'not stale (no field): ' . ( ! $reader->is_stale( array() ) ? 'PASS' : 'FAIL' ) . "\n";
