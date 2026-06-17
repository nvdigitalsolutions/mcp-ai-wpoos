<?php
/**
 * Tests for WP_MCP_AI_Team_Budget_Manager.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Test_Team_Budget_Manager.
 */
class Test_Team_Budget_Manager extends WP_UnitTestCase {

	/**
	 * Test team post id.
	 *
	 * @var int
	 */
	protected $team_id = 0;

	/** Set up test. */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The CPT may not be registered in tests; use a generic post.
		$this->team_id = self::factory()->post->create(
			array(
				'post_type'  => 'post',
				'post_title' => 'Team Alpha',
			)
		);

		// Clear today's usage.
		$key = 'wp_mcp_ai_team_usage_' . gmdate( 'Ymd' );
		delete_option( $key );

		// Re-fetch instance to ensure hooks are registered.
		WP_MCP_AI_Team_Budget_Manager::get_instance();
	}

	/** Test default budget is zero.
	 */
	public function test_default_budget_is_zero() {
		$mgr    = WP_MCP_AI_Team_Budget_Manager::get_instance();
		$budget = $mgr->get_team_budget( $this->team_id );
		$this->assertSame( 0.0, $budget['max_cost_usd_daily'] );
		$this->assertSame( 0, $budget['max_tokens_daily'] );
		$this->assertSame( 0, $budget['max_runs_daily'] );
	}

	/** Test set and get team budget.
	 */
	public function test_set_and_get_team_budget() {
		$mgr = WP_MCP_AI_Team_Budget_Manager::get_instance();
		$this->assertTrue(
			$mgr->set_team_budget(
				$this->team_id,
				array(
					'max_cost_usd_daily' => 12.50,
					'max_tokens_daily'   => 1000,
					'max_runs_daily'     => 50,
				)
			)
		);

		$budget = $mgr->get_team_budget( $this->team_id );
		$this->assertEqualsWithDelta( 12.50, $budget['max_cost_usd_daily'], 0.001 );
		$this->assertSame( 1000, $budget['max_tokens_daily'] );
		$this->assertSame( 50, $budget['max_runs_daily'] );
	}

	/** Test record usage increments totals.
	 */
	public function test_record_usage_increments_totals() {
		$mgr = WP_MCP_AI_Team_Budget_Manager::get_instance();
		$mgr->record_usage( $this->team_id, 1.25, 100, 1 );
		$mgr->record_usage( $this->team_id, 0.75, 50, 2 );

		$usage = $mgr->get_team_usage_today( $this->team_id );
		$this->assertEqualsWithDelta( 2.00, $usage['cost_usd'], 0.001 );
		$this->assertSame( 150, $usage['tokens'] );
		$this->assertSame( 3, $usage['runs'] );
	}

	/** Test check budget returns error when cost exceeded.
	 */
	public function test_check_budget_returns_error_when_cost_exceeded() {
		$mgr = WP_MCP_AI_Team_Budget_Manager::get_instance();
		$mgr->set_team_budget( $this->team_id, array( 'max_cost_usd_daily' => 1.0 ) );
		$mgr->record_usage( $this->team_id, 1.5, 0, 0 );

		$result = $mgr->check_budget( $this->team_id );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_team_budget_exceeded', $result->get_error_code() );
	}

	/** Test check budget fires action.
	 */
	public function test_check_budget_fires_action() {
		$mgr = WP_MCP_AI_Team_Budget_Manager::get_instance();
		$mgr->set_team_budget( $this->team_id, array( 'max_tokens_daily' => 100 ) );
		$mgr->record_usage( $this->team_id, 0.0, 200, 0 );

		$captured = array();
		$cb       = function ( $team_id, $violation ) use ( &$captured ) {
			$captured = array( $team_id, $violation );
		};
		add_action( 'wp_mcp_ai_team_budget_exceeded', $cb, 10, 2 );

		$mgr->check_budget( $this->team_id );

		remove_action( 'wp_mcp_ai_team_budget_exceeded', $cb, 10 );

		$this->assertSame( $this->team_id, $captured[0] );
		$this->assertSame( 'tokens', $captured[1] );
	}

	/** Test set and get team namespace.
	 */
	public function test_set_and_get_team_namespace() {
		$mgr = WP_MCP_AI_Team_Budget_Manager::get_instance();
		$this->assertTrue( $mgr->set_team_namespace( $this->team_id, 'team-alpha' ) );
		$this->assertSame( 'team-alpha', $mgr->get_team_namespace( $this->team_id ) );
	}

	/** Test namespace filter prepends team namespace.
	 */
	public function test_namespace_filter_prepends_team_namespace() {
		$mgr = WP_MCP_AI_Team_Budget_Manager::get_instance();
		$mgr->set_team_namespace( $this->team_id, 'team-alpha' );

		$team_id = $this->team_id;
		$cb      = function () use ( $team_id ) {
			return array( 'team_id' => $team_id );
		};
		add_filter( 'wp_mcp_ai_current_request_context', $cb );

		$result = apply_filters( 'wp_mcp_ai_vector_store_namespace', 'docs' );

		remove_filter( 'wp_mcp_ai_current_request_context', $cb );

		$this->assertSame( 'team-alpha/docs', $result );
	}
}
