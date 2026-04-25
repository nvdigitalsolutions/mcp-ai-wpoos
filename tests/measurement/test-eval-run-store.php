<?php
/**
 * Tests for `WP_MCP_AI_Eval_Run_Store` (PR 11).
 *
 * Validates persist / read / cap / delete behaviour. The store is a
 * thin wrapper over an option-backed JSON array, but it is the
 * single source of baseline data for the regression detector — any
 * silent corruption here would let regressions slip past CI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eval run store tests.
 */
class Test_WP_MCP_AI_Eval_Run_Store extends WP_UnitTestCase {

	/**
	 * Slug we use for assertions; any prior data is removed in setUp
	 * so tests are order-independent.
	 *
	 * @var string
	 */
	private $slug = 'phpunit_run_store_suite';

	/**
	 * Test fixture.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Eval_Run_Store::reset_instance();
		delete_option( WP_MCP_AI_Eval_Run_Store::option_name( $this->slug ) );
	}

	/**
	 * Test fixture.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Eval_Run_Store::option_name( $this->slug ) );
		WP_MCP_AI_Eval_Run_Store::reset_instance();
		parent::tearDown();
	}

	/**
	 * Test record and read roundtrip.
	 */
	public function test_record_and_read_roundtrip() {
		$store   = WP_MCP_AI_Eval_Run_Store::get_instance();
		$summary = array(
			'pass_rate'       => 0.91,
			'error_rate'      => 0.0,
			'abstention_rate' => 0.05,
		);
		$record  = $store->record( $this->slug, $summary, 1700000000 );

		$this->assertSame( $this->slug, $record['slug'] );
		$this->assertSame( 1700000000, $record['started_at'] );
		$this->assertSame( $summary, $record['summary'] );

		$all = $store->get_all( $this->slug );
		$this->assertCount( 1, $all );
		// Use loose comparison: JSON round-trip can normalise 0.0 → 0.
		$this->assertEquals( $summary, $all[0]['summary'] );
	}

	/**
	 * Test records preserve chronological order.
	 */
	public function test_records_preserve_chronological_order() {
		$store = WP_MCP_AI_Eval_Run_Store::get_instance();
		$store->record( $this->slug, array( 'pass_rate' => 0.10 ), 1000 );
		$store->record( $this->slug, array( 'pass_rate' => 0.20 ), 2000 );
		$store->record( $this->slug, array( 'pass_rate' => 0.30 ), 3000 );

		$all = $store->get_all( $this->slug );
		$this->assertSame( array( 1000, 2000, 3000 ), array_column( $all, 'started_at' ) );
	}

	/**
	 * Test get recent returns newest first.
	 */
	public function test_get_recent_returns_newest_first() {
		$store = WP_MCP_AI_Eval_Run_Store::get_instance();
		$store->record( $this->slug, array( 'pass_rate' => 0.10 ), 1000 );
		$store->record( $this->slug, array( 'pass_rate' => 0.20 ), 2000 );
		$store->record( $this->slug, array( 'pass_rate' => 0.30 ), 3000 );

		$recent = $store->get_recent( $this->slug, 2 );
		$this->assertCount( 2, $recent );
		$this->assertSame( 3000, $recent[0]['started_at'] );
		$this->assertSame( 2000, $recent[1]['started_at'] );
	}

	/**
	 * Test get recent zero returns empty.
	 */
	public function test_get_recent_zero_returns_empty() {
		$store = WP_MCP_AI_Eval_Run_Store::get_instance();
		$store->record( $this->slug, array( 'pass_rate' => 0.50 ) );
		$this->assertSame( array(), $store->get_recent( $this->slug, 0 ) );
	}

	/**
	 * Test retention cap drops oldest.
	 */
	public function test_retention_cap_drops_oldest() {
		add_filter(
			'wp_mcp_ai_eval_run_store_max_runs',
			static function () {
				return 3;
			}
		);
		$store = WP_MCP_AI_Eval_Run_Store::get_instance();
		for ( $i = 1; $i <= 5; $i++ ) {
			$store->record( $this->slug, array( 'pass_rate' => $i / 10 ), $i * 1000 );
		}
		$all = $store->get_all( $this->slug );
		$this->assertCount( 3, $all );
		// Oldest two (1000, 2000) dropped — newest three remain in order.
		$this->assertSame( array( 3000, 4000, 5000 ), array_column( $all, 'started_at' ) );
		remove_all_filters( 'wp_mcp_ai_eval_run_store_max_runs' );
	}

	/**
	 * Test delete clears history.
	 */
	public function test_delete_clears_history() {
		$store = WP_MCP_AI_Eval_Run_Store::get_instance();
		$store->record( $this->slug, array( 'pass_rate' => 0.5 ) );
		$this->assertNotEmpty( $store->get_all( $this->slug ) );
		$store->delete( $this->slug );
		$this->assertSame( array(), $store->get_all( $this->slug ) );
	}

	/**
	 * Test corrupted option returns empty array.
	 */
	public function test_corrupted_option_returns_empty_array() {
		// Something else clobbered the option with a non-JSON string.
		update_option( WP_MCP_AI_Eval_Run_Store::option_name( $this->slug ), 'not even json', false );
		$store = WP_MCP_AI_Eval_Run_Store::get_instance();
		$this->assertSame( array(), $store->get_all( $this->slug ) );
	}
}
