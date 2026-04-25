<?php
/**
 * Tests for the Budget Registry.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Budget Registry.
 */
class Test_WP_MCP_AI_Budget_Registry extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Budget_Registry::reset_instance();
		delete_option( WP_MCP_AI_Budget_Registry::PERSISTENT_OPTION );
		remove_all_actions( 'wp_mcp_ai_budget_warned' );
		remove_all_actions( 'wp_mcp_ai_budget_exceeded' );
		remove_all_actions( 'wp_mcp_ai_register_budgets' );
	}

	public function tearDown(): void {
		WP_MCP_AI_Budget_Registry::reset_instance();
		delete_option( WP_MCP_AI_Budget_Registry::PERSISTENT_OPTION );
		parent::tearDown();
	}

	public function test_register_and_retrieve() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$env = $reg->register(
			array( 'slug' => 'req_cost', 'metric_ids' => array( 'model.cost_usd' ), 'limit' => 0.5 )
		);
		$this->assertInstanceOf( 'WP_MCP_AI_Budget_Envelope', $env );
		$this->assertSame( $env, $reg->get( 'req_cost' ) );
		$this->assertCount( 1, $reg->all() );
	}

	public function test_invalid_register_returns_wp_error() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$res = $reg->register( array() );
		$this->assertInstanceOf( 'WP_Error', $res );
	}

	public function test_boot_fires_registration_hook_once() {
		$counter = 0;
		add_action(
			'wp_mcp_ai_register_budgets',
			static function () use ( &$counter ) {
				++$counter;
			}
		);
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$reg->boot();
		$reg->boot();
		$this->assertSame( 1, $counter );
	}

	public function test_consume_accumulates_and_fires_warn_then_exceeded() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$reg->register(
			array( 'slug' => 'req', 'metric_ids' => array( 'm' ), 'limit' => 1.0, 'warn_ratio' => 0.5 )
		);

		$warned   = 0;
		$exceeded = 0;
		add_action( 'wp_mcp_ai_budget_warned', static function () use ( &$warned ) { ++$warned; } );
		add_action( 'wp_mcp_ai_budget_exceeded', static function () use ( &$exceeded ) { ++$exceeded; } );

		$reg->consume( 'req', 0.3 );
		$this->assertSame( 0, $warned );
		$this->assertSame( 0, $exceeded );
		$this->assertEqualsWithDelta( 0.3, $reg->get_consumption( 'req' ), 0.0001 );

		$reg->consume( 'req', 0.3 );
		$this->assertSame( 1, $warned, 'warn fires once crossed' );
		$this->assertSame( 0, $exceeded );

		// Another bump still past warn but before limit — no new warn.
		$reg->consume( 'req', 0.1 );
		$this->assertSame( 1, $warned );

		$reg->consume( 'req', 0.5 );
		$this->assertSame( 1, $exceeded );
		// Idempotent within the same scope window.
		$reg->consume( 'req', 0.5 );
		$this->assertSame( 1, $exceeded );
	}

	public function test_skip_warn_fires_implicitly_when_exceeded_directly() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$reg->register(
			array( 'slug' => 'req', 'metric_ids' => array( 'm' ), 'limit' => 1.0, 'warn_ratio' => 0.5 )
		);
		$warned = 0;
		$exceeded = 0;
		add_action( 'wp_mcp_ai_budget_warned', static function () use ( &$warned ) { ++$warned; } );
		add_action( 'wp_mcp_ai_budget_exceeded', static function () use ( &$exceeded ) { ++$exceeded; } );

		$reg->consume( 'req', 2.0 ); // leap past warn straight into exceeded
		$this->assertSame( 1, $warned, 'Implicit warn still fires when we jump the limit' );
		$this->assertSame( 1, $exceeded );
	}

	public function test_on_metric_recorded_tracks_only_matching_ids() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$reg->boot();
		$reg->register(
			array( 'slug' => 'req', 'metric_ids' => array( 'model.cost_usd' ), 'limit' => 10.0 )
		);

		$reg->on_metric_recorded( array( 'id' => 'model.cost_usd', 'value' => 2.0 ) );
		$reg->on_metric_recorded( array( 'id' => 'unrelated', 'value' => 99.0 ) );
		$reg->on_metric_recorded( array( 'id' => 'model.cost_usd', 'value' => 3.0 ) );

		$this->assertEqualsWithDelta( 5.0, $reg->get_consumption( 'req' ), 0.0001 );
	}

	public function test_zero_and_non_numeric_values_are_ignored() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$reg->register( array( 'slug' => 'req', 'metric_ids' => array( 'm' ), 'limit' => 1.0 ) );

		$reg->on_metric_recorded( array( 'id' => 'm', 'value' => 0 ) );
		$reg->on_metric_recorded( array( 'id' => 'm', 'value' => 'nope' ) );
		$reg->on_metric_recorded( array( 'bad' => 'shape' ) );

		$this->assertSame( 0.0, $reg->get_consumption( 'req' ) );
	}

	public function test_persistent_scope_roundtrips_via_option() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$reg->register(
			array(
				'slug'       => 'daily',
				'metric_ids' => array( 'm' ),
				'limit'      => 10.0,
				'scope'      => WP_MCP_AI_Budget_Envelope::SCOPE_PERSISTENT,
			)
		);
		$reg->consume( 'daily', 4.0 );
		$this->assertEqualsWithDelta( 4.0, $reg->get_consumption( 'daily' ), 0.0001 );

		// Simulate a new request — reset singleton, same option.
		WP_MCP_AI_Budget_Registry::reset_instance();
		$reg2 = WP_MCP_AI_Budget_Registry::get_instance();
		$reg2->register(
			array(
				'slug'       => 'daily',
				'metric_ids' => array( 'm' ),
				'limit'      => 10.0,
				'scope'      => WP_MCP_AI_Budget_Envelope::SCOPE_PERSISTENT,
			)
		);
		$this->assertEqualsWithDelta( 4.0, $reg2->get_consumption( 'daily' ), 0.0001 );
	}

	public function test_reset_persistent_clears_accumulator() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$reg->register(
			array(
				'slug'       => 'daily',
				'metric_ids' => array( 'm' ),
				'limit'      => 10.0,
				'scope'      => WP_MCP_AI_Budget_Envelope::SCOPE_PERSISTENT,
			)
		);
		$reg->consume( 'daily', 7.0 );
		$this->assertTrue( $reg->reset_persistent( 'daily' ) );
		$this->assertSame( 0.0, $reg->get_consumption( 'daily' ) );
	}

	public function test_snapshot_shape() {
		$reg = WP_MCP_AI_Budget_Registry::get_instance();
		$reg->register(
			array( 'slug' => 'req', 'metric_ids' => array( 'm' ), 'limit' => 2.0, 'warn_ratio' => 0.5, 'unit' => 'usd' )
		);
		$reg->consume( 'req', 1.5 );
		$snap = $reg->snapshot();
		$this->assertCount( 1, $snap );
		$this->assertSame( 'req', $snap[0]['envelope']['slug'] );
		$this->assertEqualsWithDelta( 1.5, $snap[0]['consumed'], 0.0001 );
		$this->assertSame( 'warn', $snap[0]['state'] );
	}
}
