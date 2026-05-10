<?php
/**
 * Tests for WP_MCP_AI_Data_Budget_Tracker.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Phase 3 — agentic-loop output guard byte-budget tracker.
 *
 * @covers WP_MCP_AI_Data_Budget_Tracker
 */
class Test_Data_Budget_Tracker extends WP_UnitTestCase {

	/**
	 * Reset filters between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'wp_mcp_ai_agentic_loop_byte_budget' );
		remove_all_filters( 'wp_mcp_ai_agentic_loop_per_message_byte_budget' );
		parent::tear_down();
	}

	/**
	 * Default budgets are sane.
	 */
	public function test_default_budgets() {
		$tracker = new WP_MCP_AI_Data_Budget_Tracker( 'req-1' );
		$this->assertSame( 1048576, $tracker->get_request_budget() );
		$this->assertSame( 65536, $tracker->get_per_message_budget() );
		$this->assertSame( 0, $tracker->consumed() );
		$this->assertSame( 0, $tracker->spill_count() );
		$this->assertSame( 'req-1', $tracker->request_id() );
	}

	/**
	 * The record() helper accumulates bytes; remaining() reflects budget.
	 */
	public function test_record_and_remaining() {
		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$tracker->record( 1000 );
		$tracker->record( 500 );
		$this->assertSame( 1500, $tracker->consumed() );
		$this->assertSame( 1048576 - 1500, $tracker->remaining() );
		$this->assertFalse( $tracker->is_exhausted() );
	}

	/**
	 * The record() helper ignores negative inputs.
	 */
	public function test_record_negative_is_clamped_to_zero() {
		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$tracker->record( -100 );
		$this->assertSame( 0, $tracker->consumed() );
	}

	/**
	 * The should_spill() check triggers on per-message ceiling.
	 */
	public function test_should_spill_per_message_ceiling() {
		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$this->assertFalse( $tracker->should_spill( 1000 ) );
		$this->assertTrue( $tracker->should_spill( 70000 ) ); // > 64 KiB.
	}

	/**
	 * The should_spill() check triggers when cumulative usage would exceed request budget.
	 */
	public function test_should_spill_request_budget_exceeded() {
		add_filter(
			'wp_mcp_ai_agentic_loop_byte_budget',
			static function () {
				return 10000;
			}
		);

		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$tracker->record( 9000 );
		$this->assertTrue( $tracker->should_spill( 2000 ) ); // 9k + 2k > 10k.
		$this->assertFalse( $tracker->should_spill( 500 ) );
	}

	/**
	 * The is_exhausted() flag flips once consumed >= request budget.
	 */
	public function test_is_exhausted() {
		add_filter(
			'wp_mcp_ai_agentic_loop_byte_budget',
			static function () {
				return 1024;
			}
		);

		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$tracker->record( 1024 );
		$this->assertTrue( $tracker->is_exhausted() );
	}

	/**
	 * The note_spill() helper increments the spill counter.
	 */
	public function test_note_spill_counter() {
		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$tracker->note_spill();
		$tracker->note_spill();
		$this->assertSame( 2, $tracker->spill_count() );
	}

	/**
	 * The reset() helper clears state.
	 */
	public function test_reset_clears_state() {
		$tracker = new WP_MCP_AI_Data_Budget_Tracker( 'old' );
		$tracker->record( 9999 );
		$tracker->note_spill();
		$tracker->reset( 'new' );
		$this->assertSame( 0, $tracker->consumed() );
		$this->assertSame( 0, $tracker->spill_count() );
		$this->assertSame( 'new', $tracker->request_id() );
	}

	/**
	 * Filter overrides apply to the per-request budget.
	 */
	public function test_request_budget_filter_override() {
		add_filter(
			'wp_mcp_ai_agentic_loop_byte_budget',
			static function () {
				return 2048;
			}
		);

		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$this->assertSame( 2048, $tracker->get_request_budget() );
	}

	/**
	 * Per-message filter override applies.
	 */
	public function test_per_message_budget_filter_override() {
		add_filter(
			'wp_mcp_ai_agentic_loop_per_message_byte_budget',
			static function () {
				return 1024;
			}
		);

		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$this->assertSame( 1024, $tracker->get_per_message_budget() );
		$this->assertTrue( $tracker->should_spill( 2048 ) );
	}

	/**
	 * Filter values below the safety floor are clamped up.
	 */
	public function test_floors_enforced() {
		add_filter(
			'wp_mcp_ai_agentic_loop_byte_budget',
			static function () {
				return 1;
			}
		);
		add_filter(
			'wp_mcp_ai_agentic_loop_per_message_byte_budget',
			static function () {
				return 1;
			}
		);

		$tracker = new WP_MCP_AI_Data_Budget_Tracker();
		$this->assertGreaterThanOrEqual( 1024, $tracker->get_request_budget() );
		$this->assertGreaterThanOrEqual( 512, $tracker->get_per_message_budget() );
	}
}
