<?php
/**
 * GTmetrix Score Engine Oracle Test — reproduces the expected-values oracle.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: the score engine reproduces every oracle row.
 */
class Test_GTmetrix_Score_Engine extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES', true );
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/gtmetrix';

		if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Score_Engine' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-settings.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-fixture-reader.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-score-engine.php';
		}
	}

	/**
	 * Skip when the toolkit files are unavailable.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Score_Engine' ) ) {
			$this->markTestSkipped( 'GTmetrix toolkit not available' );
		}
	}

	/**
	 * Test: CWV metric classification boundaries.
	 */
	public function test_cwv_thresholds() {
		$this->maybe_skip();

		$this->assertSame( 'good', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'lcp', 1800 )['status'] );
		$this->assertSame( 'good', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'lcp', 2500 )['status'] );
		$this->assertSame( 'needs-improvement', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'lcp', 2501 )['status'] );
		$this->assertSame( 'poor', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'lcp', 4000 )['status'] );
		$this->assertSame( 'good', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'cls', 0.10 )['status'] );
		$this->assertSame( 'needs-improvement', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'cls', 0.11 )['status'] );
		$this->assertSame( 'poor', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'cls', 0.25 )['status'] );
		$this->assertSame( 'good', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'tbt', 200 )['status'] );
		$this->assertSame( 'poor', WP_MCP_AI_GTmetrix_Score_Engine::assess_metric( 'tbt', 600 )['status'] );
	}

	/**
	 * Test: overall CWV assessment on the fixture reports.
	 */
	public function test_cwv_overall() {
		$this->maybe_skip();

		$good = WP_MCP_AI_GTmetrix_Fixture_Reader::get_report( 'RGOOD' );
		$poor = WP_MCP_AI_GTmetrix_Fixture_Reader::get_report( 'RPOOR' );

		$this->assertSame( 'pass', WP_MCP_AI_GTmetrix_Score_Engine::assess_cwv( WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $good ) )['overall'] );
		$this->assertSame( 'fail', WP_MCP_AI_GTmetrix_Score_Engine::assess_cwv( WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $poor ) )['overall'] );
	}

	/**
	 * Test: grade band boundaries (inclusive floors).
	 */
	public function test_grade_bands() {
		$this->maybe_skip();

		$cases = array(
			array( 0.90, 'A' ),
			array( 0.80, 'B' ),
			array( 0.70, 'C' ),
			array( 0.60, 'D' ),
			array( 0.50, 'E' ),
			array( 0.49, 'F' ),
		);
		foreach ( $cases as $case ) {
			$this->assertSame( $case[1], WP_MCP_AI_GTmetrix_Score_Engine::band_grade( $case[0] ) );
		}
	}

	/**
	 * Test: budget class boundaries.
	 */
	public function test_budget_classes() {
		$this->maybe_skip();

		$this->assertSame( 'lean', WP_MCP_AI_GTmetrix_Score_Engine::budget_class( 911360, 46 )['class'] );
		$this->assertSame( 'acceptable', WP_MCP_AI_GTmetrix_Score_Engine::budget_class( 2516582, 89 )['class'] );
		$this->assertSame( 'heavy', WP_MCP_AI_GTmetrix_Score_Engine::budget_class( 2621440, 91 )['class'] );
		$this->assertSame( 'very_heavy', WP_MCP_AI_GTmetrix_Score_Engine::budget_class( 5347737, 150 )['class'] );
	}

	/**
	 * Test: LCP phase breakdown sums to the LCP value.
	 */
	public function test_lcp_phases_sum() {
		$this->maybe_skip();

		$lh    = WP_MCP_AI_GTmetrix_Fixture_Reader::get_resource( 'RGOOD', 'lighthouse.json' );
		$lcp   = WP_MCP_AI_GTmetrix_Score_Engine::lcp_phases( $lh );
		$total = $lcp['ttfb'] + $lcp['load_delay'] + $lcp['load_time'] + $lcp['render_delay'];

		$this->assertEqualsWithDelta( 1800, $total, 1.0 );
	}

	/**
	 * Test: impact ordering puts the highest-impact audit first.
	 */
	public function test_impact_order() {
		$this->maybe_skip();

		$lh     = WP_MCP_AI_GTmetrix_Fixture_Reader::get_resource( 'RGOOD', 'lighthouse.json' );
		$audits = WP_MCP_AI_GTmetrix_Score_Engine::impact_order( $lh['audits'] );

		$this->assertSame( 'largest-contentful-paint', $audits[0]['id'] );
		$this->assertSame( 'None', WP_MCP_AI_GTmetrix_Score_Engine::impact_band( null ) );
	}

	/**
	 * Test: before/after delta verdicts.
	 */
	public function test_report_delta() {
		$this->maybe_skip();

		$good = WP_MCP_AI_GTmetrix_Fixture_Reader::get_report( 'RGOOD' );
		$poor = WP_MCP_AI_GTmetrix_Fixture_Reader::get_report( 'RPOOR' );

		$deltas = WP_MCP_AI_GTmetrix_Score_Engine::report_delta(
			WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $good ),
			WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $poor )
		);

		$this->assertSame( 'regressed', $deltas['performance_score']['status'] );
		$this->assertSame( 'regressed', $deltas['largest_contentful_paint']['status'] );
		$this->assertSame( 'regressed', $deltas['cumulative_layout_shift']['status'] );
		$this->assertSame( 'regressed', $deltas['page_bytes']['status'] );

		// Small LCP change stays steady.
		$before                            = WP_MCP_AI_GTmetrix_Score_Engine::flat_report( $good );
		$after                             = $before;
		$after['largest_contentful_paint'] = 2100;
		$this->assertSame( 'steady', WP_MCP_AI_GTmetrix_Score_Engine::report_delta( $before, $after )['largest_contentful_paint']['status'] );
	}
}
