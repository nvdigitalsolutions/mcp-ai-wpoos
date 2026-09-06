<?php
/**
 * Cost tracker port tests (Wave D4h).
 *
 * Characterization suite for `CostTracker`, `CostTrackerSubscriber`, and
 * the `CostBudgetExceeded` exception. Assertions mirror the base plugin's
 * cost tracker: pricing map constants, per-category estimates (image /
 * video / music / speech / embedding / text), budget enforcement via post
 * meta, hourly + cumulative recording with pruning, report shapes, fuzzy
 * model pricing, the subscriber lifecycle, and the exception's WP_Error
 * envelope.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\CostTracker;
use NvoosContentGraphAi\Security\CostTrackerSubscriber;
use NvoosContentGraphAi\Security\Exceptions\CostBudgetExceeded;

/**
 * @group security
 */
class Test_Cost_Tracker extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\remove_all_actions( 'wp_mcp_ai_before_tool_execution' );
		\remove_all_actions( 'wp_mcp_ai_after_tool_execution' );

		\delete_option( CostTracker::SPEND_OPTION );
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_model_pricing' );
		\remove_all_filters( 'wp_mcp_ai_default_hourly_budget' );
		\remove_all_actions( 'wp_mcp_ai_before_tool_execution' );
		\remove_all_actions( 'wp_mcp_ai_after_tool_execution' );

		\delete_option( CostTracker::SPEND_OPTION );

		parent::tearDown();
	}

	public function test_constants_match_base(): void {
		$this->assertSame( 'wp_mcp_ai_cost_tracker_spend', CostTracker::SPEND_OPTION );
		$this->assertSame( 'wp_mcp_ai_cost_hourly_', CostTracker::HOURLY_PREFIX );
		$this->assertSame( 0.60, CostTracker::MODEL_PRICING['gpt-4o-mini']['output'] );
		$this->assertSame( 5.00, CostTracker::MODEL_PRICING['default']['input'] );
	}

	public function test_image_estimates(): void {
		$this->assertEqualsWithDelta( 0.04, CostTracker::estimate( 'generate_openai_image' ), 1e-9 );
		$this->assertEqualsWithDelta( 0.08, CostTracker::estimate( 'generate_openai_image', array( 'size' => '1792x1024' ) ), 1e-9 );
		$this->assertEqualsWithDelta( 0.12, CostTracker::estimate( 'generate_image', array( 'n' => 3 ) ), 1e-9 );
	}

	public function test_media_estimates(): void {
		$this->assertEqualsWithDelta( 0.50, CostTracker::estimate( 'generate_veo_video' ), 1e-9 );
		$this->assertEqualsWithDelta( 0.10, CostTracker::estimate( 'generate_music' ), 1e-9 );
		$this->assertEqualsWithDelta( 0.015, CostTracker::estimate( 'text_to_speech' ), 1e-9 );
		$this->assertEqualsWithDelta( 0.0001, CostTracker::estimate( 'create_text_embeddings' ), 1e-9 );
	}

	public function test_text_estimate_uses_model_pricing(): void {
		// 100 tokens minimum × gpt-4o-mini output 0.60/1M.
		$estimate = CostTracker::estimate( 'some_text_tool', array( 'model' => 'gpt-4o-mini' ) );
		$this->assertEqualsWithDelta( 0.00006, $estimate, 1e-9 );

		// Unknown model → default pricing (20.00/1M).
		$default = CostTracker::estimate( 'some_text_tool', array( 'model' => 'unknown-model-x' ) );
		$this->assertEqualsWithDelta( 0.002, $default, 1e-9 );
	}

	public function test_fuzzy_model_pricing_match(): void {
		// Byte-identical base behaviour: the fuzzy loop matches the FIRST
		// pricing key contained in the model name — 'gpt-4o' wins over
		// 'gpt-4o-mini' for this model string.
		$estimate = CostTracker::estimate( 'some_text_tool', array( 'model' => 'gpt-4o-mini-2024-07-18' ) );
		$this->assertEqualsWithDelta( 0.001, $estimate, 1e-9 );
	}

	public function test_check_budget_without_budget_allows(): void {
		$this->assertTrue( CostTracker::check_budget( 999999, 50.0 ) );
	}

	public function test_check_budget_enforces_post_meta_budget(): void {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, 'wp_mcp_ai_hourly_budget', 1.0 );

		$this->assertTrue( CostTracker::check_budget( $post_id, 0.5 ) );

		// Record spend, then a further call that tips over the budget.
		CostTracker::record( $post_id, 0.5 );
		$check = CostTracker::check_budget( $post_id, 0.9 );
		$this->assertWPError( $check );
		$this->assertSame( 'cost_budget_exceeded', $check->get_error_code() );
	}

	public function test_record_updates_hourly_and_cumulative(): void {
		$post_id = self::factory()->post->create();

		CostTracker::record( $post_id, 0.25 );
		CostTracker::record( $post_id, 0.35 );

		$this->assertEqualsWithDelta( 0.60, CostTracker::get_hourly_spend( $post_id ), 1e-9 );

		$report = CostTracker::get_report( $post_id );
		$this->assertSame( $post_id, $report['assistant_id'] );
		$this->assertEqualsWithDelta( 0.60, $report['total'], 1e-9 );
		$this->assertSame( 1, $report['days'] );

		$totals = CostTracker::get_report();
		$this->assertEqualsWithDelta( 0.60, $totals['totals'][ (string) $post_id ], 1e-9 );
	}

	public function test_record_prunes_entries_older_than_90_days(): void {
		$post_id = self::factory()->post->create();

		CostTracker::record( $post_id, 0.10 );

		$spend = \get_option( CostTracker::SPEND_OPTION, array() );
		$old   = gmdate( 'Y-m-d', strtotime( '-100 days' ) );
		$spend[ $old ] = array( (string) $post_id => 99.0 );
		\update_option( CostTracker::SPEND_OPTION, $spend );

		CostTracker::record( $post_id, 0.10 );

		$after = \get_option( CostTracker::SPEND_OPTION, array() );
		$this->assertArrayNotHasKey( $old, $after );
	}

	public function test_record_ignores_invalid_inputs(): void {
		CostTracker::record( 0, 1.0 );
		CostTracker::record( 5, 0 );
		CostTracker::record( 5, -1 );

		$this->assertSame( array(), \get_option( CostTracker::SPEND_OPTION, array() ) );
	}

	public function test_default_budget_filter(): void {
		\add_filter( 'wp_mcp_ai_default_hourly_budget', static function () {
			return 2.5;
		} );

		$post_id = self::factory()->post->create();
		$this->assertEqualsWithDelta( 2.5, CostTracker::get_budget( $post_id ), 1e-9 );

		$check = CostTracker::check_budget( $post_id, 3.0 );
		$this->assertWPError( $check );
	}

	public function test_subscriber_on_before_throws_when_over_budget(): void {
		$post_id = self::factory()->post->create();
		\update_post_meta( $post_id, 'wp_mcp_ai_hourly_budget', 0.01 );

		try {
			CostTrackerSubscriber::on_before(
				'generate_veo_video',
				array(),
				array( 'assistant_id' => $post_id )
			);
			$this->fail( 'Expected CostBudgetExceeded to be thrown.' );
		} catch ( CostBudgetExceeded $e ) {
			$this->assertSame( $post_id, $e->get_assistant_id() );
			$this->assertSame( 429, $e->getCode() );

			$error = $e->to_wp_error();
			$this->assertSame( 'cost_budget_exceeded', $error->get_error_code() );
			$this->assertSame( 429, $error->get_error_data()['status'] );
			$this->assertSame( 3600, $error->get_error_data()['retry_after'] );
		}
	}

	public function test_subscriber_skips_without_assistant(): void {
		// No assistant context → no throw, no record.
		CostTrackerSubscriber::on_before( 'generate_veo_video', array(), array() );
		CostTrackerSubscriber::on_after( 'generate_veo_video', array(), array(), array( 'ok' ) );

		$this->assertSame( array(), \get_option( CostTracker::SPEND_OPTION, array() ) );
	}

	public function test_subscriber_on_after_records_success_only(): void {
		$post_id = self::factory()->post->create();

		CostTrackerSubscriber::on_after(
			'generate_music',
			array(),
			array( 'assistant_id' => $post_id ),
			new \WP_Error( 'boom' )
		);
		$this->assertSame( 0.0, CostTracker::get_hourly_spend( $post_id ) );

		CostTrackerSubscriber::on_after(
			'generate_music',
			array(),
			array( 'assistant_id' => $post_id ),
			array( 'ok' => true )
		);
		$this->assertEqualsWithDelta( 0.10, CostTracker::get_hourly_spend( $post_id ), 1e-9 );
	}
}
