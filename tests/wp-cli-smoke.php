<?php
/**
 * WP-CLI Smoke Test — zero-argument tool regression check.
 *
 * Tests all registered tools with empty arguments and reports which
 * pass (return a valid result) vs. fail. Designed to run in ~30 seconds
 * on every deploy as a fast regression gate.
 *
 * Usage:
 *   studio wp --user=admin eval-file tests/wp-cli-smoke.php
 *
 * @package WP_MCP_AI
 * @since  1.1.34
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script must be run via WP-CLI.\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Bootstrap: ensure the tool registry is loaded.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
	WP_CLI::error( 'WP_MCP_AI_Tool_Registry not found. Is the plugin active?' );
}

$registry = WP_MCP_AI_Tool_Registry::instance();
$all_tools = $registry->get_tools();
$total = count( $all_tools );

WP_CLI::log( sprintf( 'Smoke testing %d tools with empty arguments...', $total ) );

$results = array(
	'pass' => array(),
	'fail' => array(),
	'fatal' => array(),
	'skipped' => array(),
);

foreach ( $all_tools as $slug => $tool ) {
	// Skip tools that are not available (e.g. missing dependencies).
	if ( method_exists( $tool, 'is_available' ) && ! $tool->is_available() ) {
		$results['skipped'][] = $slug;
		continue;
	}

	try {
		$result = $tool->execute( array(), array( 'user_id' => get_current_user_id() ) );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$results['fail'][] = array(
				'slug'  => $slug,
				'code'  => $code,
				'msg'   => $result->get_error_message(),
			);
		} else {
			$results['pass'][] = $slug;
		}
	} catch ( \Throwable $e ) {
		$results['fatal'][] = array(
			'slug'  => $slug,
			'error' => $e->getMessage(),
			'file'  => $e->getFile(),
			'line'  => $e->getLine(),
		);
	}
}

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
$pass_count  = count( $results['pass'] );
$fail_count  = count( $results['fail'] );
$fatal_count = count( $results['fatal'] );
$skip_count  = count( $results['skipped'] );

WP_CLI::log( '' );
WP_CLI::log( '========================================' );
WP_CLI::log( '           SMOKE TEST RESULTS' );
WP_CLI::log( '========================================' );
WP_CLI::log( sprintf( 'Total tools:      %d', $total ) );
WP_CLI::log( sprintf( 'Pass (no error):  %d', $pass_count ) );
WP_CLI::log( sprintf( 'Fail (WP_Error):  %d', $fail_count ) );
WP_CLI::log( sprintf( 'Fatal (throw):    %d', $fatal_count ) );
WP_CLI::log( sprintf( 'Skipped:          %d', $skip_count ) );
WP_CLI::log( '========================================' );

// Print failures with codes for triage.
if ( $fail_count > 0 ) {
	WP_CLI::log( '' );
	WP_CLI::log( 'Failures (WP_Error):' );
	$by_code = array();
	foreach ( $results['fail'] as $f ) {
		$by_code[ $f['code'] ][] = $f['slug'];
	}
	foreach ( $by_code as $code => $slugs ) {
		WP_CLI::log( sprintf( '  [%s] %d tools: %s', $code, count( $slugs ), implode( ', ', array_slice( $slugs, 0, 10 ) ) ) );
	}
}

// Print fatals — these are the critical regressions.
if ( $fatal_count > 0 ) {
	WP_CLI::log( '' );
	WP_CLI::warning( sprintf( '%d FATAL ERRORS detected:', $fatal_count ) );
	foreach ( $results['fatal'] as $f ) {
		WP_CLI::log( sprintf( '  %s: %s (%s:%d)', $f['slug'], $f['error'], $f['file'], $f['line'] ) );
	}
}

// Write JSON report.
$report_file = WP_CONTENT_DIR . '/uploads/wp-mcp-ai-smoke-report.json';
$report = array(
	'timestamp'  => gmdate( 'c' ),
	'total'      => $total,
	'pass'       => $pass_count,
	'fail'       => $fail_count,
	'fatal'      => $fatal_count,
	'skipped'    => $skip_count,
	'pass_slugs' => $results['pass'],
	'failures'   => $results['fail'],
	'fatals'     => $results['fatal'],
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
file_put_contents( $report_file, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Report written to %s', $report_file ) );

// Exit code: non-zero if fatals found.
if ( $fatal_count > 0 ) {
	exit( 1 );
}
