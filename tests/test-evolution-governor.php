<?php
/**
 * Tests for the Evolution Governor (Phase G.1).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test unified budget, rate limits, site cap and reporting.
 */
class Test_Evolution_Governor extends WP_UnitTestCase {

	/**
	 * Assistant post ID used across tests.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up an assistant post.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Evolution_Governor' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Evolution_Governor class not available.' );
		}

		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Remove governor filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_evolution_governor_enabled' );
		remove_all_filters( 'wp_mcp_ai_evolution_governor_rate_limit' );
		remove_all_filters( 'wp_mcp_ai_evolution_governor_site_max_mutations' );
		remove_all_filters( 'wp_mcp_ai_harness_evolution_budget_usd' );
		parent::tearDown();
	}

	/**
	 * Spend recorded on any path draws from the shared budget.
	 */
	public function test_budget_is_shared_across_paths() {
		WP_MCP_AI_Evolution_Governor::record_spend( $this->assistant_id, 1.0, 'search' );
		WP_MCP_AI_Evolution_Governor::record_spend( $this->assistant_id, 0.5, 'evolver' );

		$this->assertSame( 1.5, WP_MCP_AI_Evolution_Governor::budget_spent( $this->assistant_id ) );
		$this->assertSame(
			WP_MCP_AI_Evolution_Governor::budget_limit_usd( $this->assistant_id ) - 1.5,
			WP_MCP_AI_Evolution_Governor::budget_remaining( $this->assistant_id )
		);
	}

	/**
	 * Pre-existing Phase A spend transient carries over unchanged.
	 */
	public function test_legacy_spend_transient_carries_over() {
		set_transient( 'wp_mcp_ai_evolution_budget_' . $this->assistant_id, 2.5, HOUR_IN_SECONDS );

		$this->assertSame( 2.5, WP_MCP_AI_Evolution_Governor::budget_spent( $this->assistant_id ) );
	}

	/**
	 * Budget exhaustion blocks mutation with the budget_exhausted reason.
	 */
	public function test_budget_exhaustion_blocks() {
		add_filter( 'wp_mcp_ai_harness_evolution_budget_usd', '__return_zero' );

		$gate = WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'evolver', 0.01 );

		$this->assertFalse( $gate['allowed'] );
		$this->assertSame( 'budget_exhausted', $gate['reason'] );
	}

	/**
	 * Per-path rate limit blocks once the counter reaches the limit.
	 */
	public function test_rate_limit_blocks_per_path() {
		add_filter( 'wp_mcp_ai_evolution_governor_rate_limit', '__return_zero' );

		$gate = WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'search' );

		$this->assertFalse( $gate['allowed'] );
		$this->assertSame( 'rate_limited', $gate['reason'] );
	}

	/**
	 * Rate counters are per path — one path exhausting does not block another.
	 */
	public function test_rate_counters_are_isolated_per_path() {
		add_filter(
			'wp_mcp_ai_evolution_governor_rate_limit',
			static function ( $limit, $assistant_id, $path ) {
				return 'search' === $path ? 1 : 60;
			},
			10,
			3
		);

		WP_MCP_AI_Evolution_Governor::record_mutation( $this->assistant_id, 'search' );

		$this->assertFalse( WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'search' )['allowed'] );
		$this->assertTrue( WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'evolver' )['allowed'] );
	}

	/**
	 * The site-wide cap blocks every path once exhausted.
	 */
	public function test_site_cap_blocks_all_paths() {
		add_filter(
			'wp_mcp_ai_evolution_governor_site_max_mutations',
			static function () {
				return 1;
			}
		);

		WP_MCP_AI_Evolution_Governor::record_mutation( $this->assistant_id, 'evolver' );

		$gate = WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'proposer' );

		$this->assertFalse( $gate['allowed'] );
		$this->assertSame( 'site_cap_exceeded', $gate['reason'] );
	}

	/**
	 * A site max of zero means unlimited (default).
	 */
	public function test_site_cap_zero_is_unlimited() {
		add_filter( 'wp_mcp_ai_evolution_governor_site_max_mutations', '__return_zero' );

		WP_MCP_AI_Evolution_Governor::record_mutation( $this->assistant_id, 'evolver' );

		$this->assertTrue( WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'evolver' )['allowed'] );
	}

	/**
	 * Unknown paths are rejected.
	 */
	public function test_unknown_path_rejected() {
		$gate = WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'nonsense' );

		$this->assertFalse( $gate['allowed'] );
		$this->assertSame( 'unknown_path', $gate['reason'] );
	}

	/**
	 * Disabling the governor allows everything.
	 */
	public function test_disabled_governor_allows() {
		add_filter( 'wp_mcp_ai_evolution_governor_enabled', '__return_false' );

		$gate = WP_MCP_AI_Evolution_Governor::can_mutate( $this->assistant_id, 'evolver', 999.0 );

		$this->assertTrue( $gate['allowed'] );
		$this->assertSame( 'governor_disabled', $gate['reason'] );
	}

	/**
	 * Recording mutations increments the per-path and site counters.
	 */
	public function test_record_mutation_increments_counters() {
		WP_MCP_AI_Evolution_Governor::record_mutation( $this->assistant_id, 'evolver' );
		WP_MCP_AI_Evolution_Governor::record_mutation( $this->assistant_id, 'evolver' );

		$this->assertSame( 2, WP_MCP_AI_Evolution_Governor::mutations_this_hour( $this->assistant_id, 'evolver' ) );
		$this->assertSame( 2, WP_MCP_AI_Evolution_Governor::site_mutations_this_hour() );
	}

	/**
	 * The report exposes budget, site and per-path counters.
	 */
	public function test_report_shape() {
		WP_MCP_AI_Evolution_Governor::record_spend( $this->assistant_id, 0.25, 'search' );

		$report = WP_MCP_AI_Evolution_Governor::get_report( $this->assistant_id );

		$this->assertTrue( $report['enabled'] );
		$this->assertSame( 0.25, $report['budget_spent_usd'] );
		$this->assertArrayHasKey( 'evolver', $report['paths'] );
		$this->assertArrayHasKey( 'search', $report['paths'] );
		$this->assertSame( 0.25, $report['paths']['search']['spend_usd'] );
		$this->assertArrayHasKey( 'rate_limit', $report['paths']['search'] );
	}
}
