<?php
/**
 * F&B Benchmarks Tests — industry-standard threshold ranges + flagging.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B benchmarks.
 */
class Test_Fnb_Benchmarks extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Benchmarks' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-benchmarks.php';
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_fnb_benchmarks' );
		parent::tearDown();
	}

	/**
	 * Test: default ranges carry the industry-standard cost targets.
	 */
	public function test_default_ranges_exist() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Benchmarks' ) ) {
			$this->markTestSkipped( 'F&B benchmarks not available' );
		}

		$ranges = WP_MCP_AI_Fnb_Benchmarks::ranges();
		$this->assertArrayHasKey( 'food_cost_pct', $ranges );
		$this->assertArrayHasKey( 'beverage_cost_pct', $ranges );
		$this->assertArrayHasKey( 'labour_pct', $ranges );
		$this->assertArrayHasKey( 'prime_cost_pct', $ranges );
		$this->assertArrayHasKey( 'inventory_variance', $ranges );
	}

	/**
	 * Test: flag_threshold() returns low/in_range/high correctly.
	 */
	public function test_flag_threshold() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Benchmarks' ) ) {
			$this->markTestSkipped( 'F&B benchmarks not available' );
		}

		$this->assertSame( 'in_range', WP_MCP_AI_Fnb_Benchmarks::flag_threshold( 'food_cost_pct', 0.30 ) );
		$this->assertSame( 'low', WP_MCP_AI_Fnb_Benchmarks::flag_threshold( 'food_cost_pct', 0.20 ) );
		$this->assertSame( 'high', WP_MCP_AI_Fnb_Benchmarks::flag_threshold( 'food_cost_pct', 0.45 ) );
		$this->assertSame( 'unknown', WP_MCP_AI_Fnb_Benchmarks::flag_threshold( 'not_a_metric', 0.5 ) );
	}

	/**
	 * Test: the benchmark table is filterable.
	 */
	public function test_benchmarks_are_filterable() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Benchmarks' ) ) {
			$this->markTestSkipped( 'F&B benchmarks not available' );
		}

		add_filter(
			'wp_mcp_ai_fnb_benchmarks',
			static function ( $ranges ) {
				$ranges['food_cost_pct']['max'] = 0.30;
				return $ranges;
			}
		);

		$this->assertSame( 'high', WP_MCP_AI_Fnb_Benchmarks::flag_threshold( 'food_cost_pct', 0.32 ) );
	}
}
