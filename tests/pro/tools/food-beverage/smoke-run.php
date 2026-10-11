<?php
/**
 * Standalone smoke harness: runs the F&B metric engine against the CSV
 * fixtures and the Check-totals oracle WITHOUT WordPress, via minimal stubs.
 * Usage: php tests/pro/tools/food-beverage/smoke-run.php
 */

// --- Minimal WordPress stubs -------------------------------------------------
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

function get_option( $key, $default = array() ) {
	return $default;
}

function get_transient( $key ) {
	return isset( $GLOBALS['_stub_transients'][ $key ] ) ? $GLOBALS['_stub_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['_stub_transients'][ $key ] = $value;

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

function absint( $value ) {
	return abs( (int) $value );
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return bin2hex( random_bytes( max( 1, (int) ceil( $length / 2 ) ) ) );
}

function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function wp_mkdir_p( $dir ) {
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}

	return is_dir( $dir );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function sanitize_title( $title ) {
	return preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( (string) $title ) );
}

// --- Load toolkit (fixture adapter, no network) ------------------------------
define( 'ABSPATH', __DIR__ . '/../../../../../' );
define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );

$toolkit = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

require_once $toolkit . '/class-wp-mcp-ai-fnb-settings.php';
require_once $toolkit . '/class-wp-mcp-ai-fnb-table-reader.php';
require_once $toolkit . '/class-wp-mcp-ai-fnb-data-source.php';
require_once $toolkit . '/class-wp-mcp-ai-fnb-fixture-reader.php';
require_once $toolkit . '/class-wp-mcp-ai-fnb-metrics.php';
require_once $toolkit . '/class-wp-mcp-ai-fnb-report-builder.php';

// Report-builder smoke: R-03 weekend plan and R-08 cost review build cleanly.
$r03 = WP_MCP_AI_Fnb_Report_Builder::build( 'R-03', array( 'date' => '2026-10-09' ) );
$r08 = WP_MCP_AI_Fnb_Report_Builder::build( 'R-08', array( 'month' => '2026-09', 'prev_month' => '2026-08' ) );
if ( is_wp_error( $r03 ) || empty( $r03['sections'] ) ) {
	echo "[FAIL] R-03 weekend prep plan did not build\n";
	++$failures;
} else {
	echo "[PASS] R-03 weekend prep plan built (" . count( $r03['sections'] ) . " sections)\n";
}
if ( is_wp_error( $r08 ) || empty( $r08['sections'] ) ) {
	echo "[FAIL] R-08 monthly cost review did not build\n";
	++$failures;
} else {
	echo "[PASS] R-08 monthly cost review built (" . count( $r08['sections'] ) . " sections)\n";
}

$oracle = require $toolkit . '/tests/expected-values.php';
$num    = static function ( $key ) use ( $oracle ) {
	return isset( $oracle[ $key ] ) ? (float) $oracle[ $key ] : null;
};

$failures = 0;
$check    = static function ( $label, $expected, $actual, $tolerance = 0.01 ) use ( &$failures ) {
	$delta = abs( $expected - $actual );
	$ok    = $delta <= $tolerance;
	if ( ! $ok ) {
		++$failures;
	}
	printf(
		"[%s] %-48s expected %-14s actual %-14s\n",
		$ok ? 'PASS' : 'FAIL',
		$label,
		round( $expected, 4 ),
		round( $actual, 4 )
	);
};

echo "=== F&B metric engine smoke test (Check totals oracle) ===\n";

foreach ( array( '2026-07', '2026-08', '2026-09' ) as $m ) {
	$covers = WP_MCP_AI_Fnb_Metrics::m02_covers( $m );
	$check( "M-02 covers $m", $num( 'covers_' . str_replace( '-', '_', $m ) ), $covers['value'], 0.5 );
}

$food = WP_MCP_AI_Fnb_Metrics::m01_revenue( '2026-09', 'Food' );
$bev  = WP_MCP_AI_Fnb_Metrics::m01_revenue( '2026-09', 'Beverage' );
$check( 'M-01 food revenue Sep', $num( 'food_revenue_2026_09' ), $food['value'], 1 );
$check( 'M-01 beverage revenue Sep', $num( 'beverage_revenue_2026_09' ), $bev['value'], 1 );
$check( 'M-01 total revenue Sep', $num( 'total_revenue_2026_09' ), $food['value'] + $bev['value'], 1 );

$m03 = WP_MCP_AI_Fnb_Metrics::m03_avg_spend( '2026-09' );
$check( 'M-03 avg spend Sep', $num( 'average_spend_per_cover_2026_09' ), $m03['value'], 0.1 );

$m04 = WP_MCP_AI_Fnb_Metrics::m04_food_cost_pct( '2026-09' );
$m05 = WP_MCP_AI_Fnb_Metrics::m05_beverage_cost_pct( '2026-09' );
$check( 'M-04 food stock used Sep', $num( 'food_cost_stock_used_2026_09' ), $m04['stock_used']['value'], 1 );
$check( 'M-05 bev stock used Sep', $num( 'beverage_cost_stock_used_2026_09' ), $m05['stock_used']['value'], 1 );
$check( 'M-04 food cost % Sep', $num( 'food_cost_2026_09' ), $m04['value'], 0.0002 );
$check( 'M-05 bev cost % Sep', $num( 'beverage_cost_2026_09' ), $m05['value'], 0.0002 );

$opex = WP_MCP_AI_Fnb_Metrics::total_operating_cost( '2026-09' );
$check( 'Total opex Sep', $num( 'total_operating_expenses_2026_09' ), $opex['total'], 0.2 );
$m06 = WP_MCP_AI_Fnb_Metrics::m06_cost_per_cover( '2026-09' );
$check( 'M-06 cost per cover Sep', $num( 'cost_per_cover_2026_09' ), $m06['value'], 0.1 );

$sep_p  = WP_MCP_AI_Fnb_Metrics::payroll_total( '2026-09' );
$aug_p  = WP_MCP_AI_Fnb_Metrics::payroll_total( '2026-08' );
$c_sep  = WP_MCP_AI_Fnb_Metrics::m02_covers( '2026-09' );
$c_aug  = WP_MCP_AI_Fnb_Metrics::m02_covers( '2026-08' );
$volume = WP_MCP_AI_Fnb_Metrics::m07_volume_effect( $aug_p['value'], $c_aug['value'], $c_sep['value'] );
$pce    = WP_MCP_AI_Fnb_Metrics::m08_per_cover_effect( $sep_p['value'], $aug_p['value'], $volume );
$check( 'M-07 payroll volume effect', $num( 'payroll_volume_effect' ), $volume, 0.1 );
$check( 'M-08 payroll per-cover effect', $num( 'payroll_per_cover_effect' ), $pce, 0.1 );

$m09 = WP_MCP_AI_Fnb_Metrics::m09_overtime_per_100_covers( '2026-09' );
$check( 'M-09 overtime/100 covers Sep', $num( 'overtime_hours_per_100_covers_2026_09' ), $m09['value'], 0.02 );

$m11 = WP_MCP_AI_Fnb_Metrics::m11_electricity_per_cover( '2026-09' );
$check( 'M-11 kWh per cover Sep', $num( 'kwh_per_cover_2026_09' ), $m11['value'], 0.002 );

$m18 = WP_MCP_AI_Fnb_Metrics::m18_waste_pct( '2026-09' );
$check( 'M-18 waste value Sep', $num( 'waste_logged_value_2026_09' ), $m18['waste_value'], 0.2 );

$m20 = WP_MCP_AI_Fnb_Metrics::m20_budget_variance( '2026-09' );
$by_line = array();
foreach ( $m20['rows'] as $row ) {
	$by_line[ $row['line'] ] = $row;
}
$check( 'M-20 food revenue vs budget', $num( 'food_revenue_sep_vs_budget' ), $by_line['Food revenue']['variance'], 0.2 );
$check( 'M-20 maintenance vs budget', $num( 'maintenance_sep_vs_budget' ), $by_line['Maintenance']['variance'], 0.2 );

$eng = WP_MCP_AI_Fnb_Metrics::menu_engineering( '2026-09' );
$quadrants = array_count_values( array_column( $eng['rows'], 'quadrant' ) );
echo 'Menu-engineering quadrants (Sep): ' . wp_json_encode( $quadrants ) . "\n";

echo $failures === 0 ? "=== ALL CHECKS PASSED ===\n" : "=== $failures CHECK(S) FAILED ===\n";
exit( $failures === 0 ? 0 : 1 );
