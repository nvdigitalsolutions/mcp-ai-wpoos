<?php
/**
 * Standalone smoke harness: runs the GTmetrix score engine, fix engine and
 * fixture-backed client reads WITHOUT WordPress, via minimal stubs, and
 * asserts every row of the expected-values oracle.
 *
 * Usage: php addons/pro/includes/tools/gtmetrix/tests/smoke-run.php
 */

// --- Define ABSPATH FIRST (repo files exit silently without it) ------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../../../../' );
}

if ( ! defined( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES' ) ) {
	define( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES', true );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// --- Minimal WordPress stubs ------------------------------------------------
class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

$GLOBALS['_stub_transients'] = array();

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_option( $key, $default = false ) {
	return $default;
}

function get_transient( $key ) {
	return isset( $GLOBALS['_stub_transients'][ $key ] ) ? $GLOBALS['_stub_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['_stub_transients'][ $key ] = $value;

	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['_stub_transients'][ $key ] );

	return true;
}

function __( $text, $domain = null ) {
	return $text;
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}

function sanitize_file_name( $value ) {
	return preg_replace( '/[^a-z0-9._\-]/i', '', (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function esc_url_raw( $url, $protocols = null ) {
	return (string) $url;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}

// --- Load services -----------------------------------------------------------
require_once __DIR__ . '/../class-wp-mcp-ai-gtmetrix-settings.php';
require_once __DIR__ . '/../class-wp-mcp-ai-gtmetrix-fixture-reader.php';
require_once __DIR__ . '/../class-wp-mcp-ai-gtmetrix-client.php';
require_once __DIR__ . '/../class-wp-mcp-ai-gtmetrix-score-engine.php';
require_once __DIR__ . '/../class-wp-mcp-ai-gtmetrix-fix-engine.php';

// --- Harness ------------------------------------------------------------------
$pass  = 0;
$fail  = 0;
$fails = array();

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition result.
 * @param string $label     Check label.
 */
function check( $condition, $label ) {
	global $pass, $fail, $fails;
	if ( $condition ) {
		++$pass;
		echo 'PASS: ' . $label . PHP_EOL;
	} else {
		++$fail;
		$fails[] = $label;
		echo 'FAIL: ' . $label . PHP_EOL;
	}
}

$oracle = require __DIR__ . '/expected-values.php';
$engine = 'WP_MCP_AI_GTmetrix_Score_Engine';

// CWV thresholds.
check( 'good' === $engine::assess_metric( 'lcp', 1800 )['status'], 'oracle cwv.lcp.good' );
check( 'good' === $engine::assess_metric( 'lcp', 2500 )['status'], 'oracle cwv.lcp.boundary.good (2500 inclusive)' );
check( 'needs-improvement' === $engine::assess_metric( 'lcp', 2501 )['status'], 'oracle cwv.lcp.ni' );
check( 'poor' === $engine::assess_metric( 'lcp', 4000 )['status'], 'oracle cwv.lcp.poor' );
check( 'good' === $engine::assess_metric( 'cls', 0.10 )['status'], 'oracle cwv.cls.good' );
check( 'needs-improvement' === $engine::assess_metric( 'cls', 0.11 )['status'], 'oracle cwv.cls.ni' );
check( 'poor' === $engine::assess_metric( 'cls', 0.25 )['status'], 'oracle cwv.cls.poor' );
check( 'good' === $engine::assess_metric( 'tbt', 200 )['status'], 'oracle cwv.tbt.good' );
check( 'poor' === $engine::assess_metric( 'tbt', 600 )['status'], 'oracle cwv.tbt.poor' );

// Overall CWV on fixture reports.
$good = WP_MCP_AI_GTmetrix_Fixture_Reader::get_report( 'RGOOD' );
$poor = WP_MCP_AI_GTmetrix_Fixture_Reader::get_report( 'RPOOR' );
check( 'pass' === $engine::assess_cwv( $engine::flat_report( $good ) )['overall'], 'oracle cwv.overall.good' );
check( 'fail' === $engine::assess_cwv( $engine::flat_report( $poor ) )['overall'], 'oracle cwv.overall.fail' );

// Grade bands.
check( 'A' === $engine::band_grade( 0.90 ), 'oracle grade.A' );
check( 'B' === $engine::band_grade( 0.80 ), 'oracle grade.B' );
check( 'C' === $engine::band_grade( 0.70 ), 'oracle grade.C' );
check( 'D' === $engine::band_grade( 0.60 ), 'oracle grade.D' );
check( 'E' === $engine::band_grade( 0.50 ), 'oracle grade.E' );
check( 'F' === $engine::band_grade( 0.49 ), 'oracle grade.F' );

// Budget classes.
check( 'lean' === $engine::budget_class( 911360, 46 )['class'], 'oracle budget.lean' );
check( 'acceptable' === $engine::budget_class( 2516582, 89 )['class'], 'oracle budget.acceptable' );
check( 'heavy' === $engine::budget_class( 2621440, 91 )['class'], 'oracle budget.heavy' );
check( 'very_heavy' === $engine::budget_class( 5347737, 150 )['class'], 'oracle budget.very_heavy' );

// LCP phases sum ≈ LCP.
$lh  = WP_MCP_AI_GTmetrix_Fixture_Reader::get_resource( 'RGOOD', 'lighthouse.json' );
$lcp = $engine::lcp_phases( $lh );
$sum = $lcp['ttfb'] + $lcp['load_delay'] + $lcp['load_time'] + $lcp['render_delay'];
check( 1800 === $sum, 'oracle lcp.phases.sum' );

// Impact ordering.
$audits = $engine::impact_order( $lh['audits'] );
check( 'largest-contentful-paint' === $audits[0]['id'], 'oracle impact.first' );
check( 'None' === $engine::impact_band( null ), 'oracle impact.none_band' );

// Deltas.
$deltas = $engine::report_delta( $engine::flat_report( $good ), $engine::flat_report( $poor ) );
check( 'regressed' === $deltas['performance_score']['status'], 'oracle delta.score.regressed' );
check( 'regressed' === $deltas['largest_contentful_paint']['status'], 'oracle delta.lcp.regressed' );
check( 'regressed' === $deltas['cumulative_layout_shift']['status'], 'oracle delta.cls.regressed' );
check( 'regressed' === $deltas['page_bytes']['status'], 'oracle delta.bytes.regressed' );
$steady = $engine::report_delta(
	array_merge( $engine::flat_report( $good ), array( 'id' => 'A' ) ),
	array_merge(
		$engine::flat_report( $good ),
		array(
			'id'                       => 'B',
			'largest_contentful_paint' => 2100,
		)
	)
);
check( 'steady' === $steady['largest_contentful_paint']['status'], 'oracle delta.lcp.steady' );

// Fix engine: matcher maps fixture audits without inventing blocks.
$matched = WP_MCP_AI_GTmetrix_Fix_Engine::match( $audits );
check( count( $matched['fixes'] ) > 0, 'fix engine matches at least one audit' );
$known_blocks = array( 'lcp-eager-hero', 'image-weight', 'font-display-swap', 'dequeue-fa4-shims', 'conditional-plugin-css', 'lazy-recaptcha', 'filemtime-cache-busting', 'cls-stabilize' );
foreach ( $matched['fixes'] as $fix ) {
	check( in_array( $fix['block'], $known_blocks, true ) || '' === $fix['block'], 'fix block known: ' . $fix['matched_audit'] );
}
$snippet = WP_MCP_AI_GTmetrix_Fix_Engine::generate_snippet(
	$matched['fixes'],
	array(
		'report_id' => 'RGOOD',
		'url'       => 'https://example.test/',
	)
);
check( false !== strpos( $snippet, "defined( 'ABSPATH' )" ), 'snippet carries the ABSPATH guard' );
check( false !== strpos( $snippet, 'TEST ON STAGING' ) || false === strpos( $snippet, 'high' ), 'snippet staging-warns risky blocks' );

// Fixture lifecycle: start → poll → completed with report.
$client = new WP_MCP_AI_GTmetrix_Client();
$start  = $client->start_test(
	array(
		'url'    => 'https://example.test/',
		'report' => 'lighthouse',
	)
);
check( ! is_wp_error( $start ) && 'queued' === $start['attributes']['state'], 'fixture start_test returns queued test' );
$t1 = $client->get_test( $start['id'] );
check( 'queued' === $t1['attributes']['state'], 'fixture first poll: queued' );
$t2 = $client->get_test( $start['id'] );
check( 'started' === $t2['attributes']['state'], 'fixture second poll: started' );
$t3 = $client->get_test( $start['id'] );
check( 'completed' === $t3['attributes']['state'] && 'RGOOD' === $t3['attributes']['report'], 'fixture third poll: completed with report' );

// Client error taxonomy + blocked resources (pure client, no HTTP in fixture mode).
check( is_wp_error( $client->get_resource( 'RGOOD', 'analysis-options' ) ), 'sensitive resource blocked' );
check( is_wp_error( $client->get_resource( 'RGOOD', 'net.har' ) ), 'HAR resource blocked' );

echo PHP_EOL . sprintf( '%d passed, %d failed', $pass, $fail ) . PHP_EOL;
if ( $fail > 0 ) {
	echo 'Failing checks:' . PHP_EOL;
	foreach ( $fails as $f ) {
		echo '  - ' . $f . PHP_EOL;
	}
	exit( 1 );
}

echo 'SMOKE OK' . PHP_EOL;
exit( 0 );
