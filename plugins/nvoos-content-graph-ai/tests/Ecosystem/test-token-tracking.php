<?php
/**
 * Token tracking database + enhanced tracking + optimizer port tests (Wave D3e).
 *
 * Characterization suite for `TokenTrackingDatabase`, `EnhancedTokenTracking`,
 * and `TokenDbOptimizer`. Assertions mirror the base plugin's token-tracking
 * tests: schema creation, insert validation, date/tool filters, cost
 * summaries (estimated vs actual), aggregations, retention cleanup,
 * enhanced recording from usage/tool data, backfill, misattribution
 * migration, and usermeta index lifecycle.
 *
 * The hourly-usage table is created with real DDL — the WP framework's
 * TEMPORARY-table rewrite is suspended in setUp and restored in
 * tearDown after the real table is dropped (same pattern as the thread
 * manager suite).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Analytics\EnhancedTokenTracking;
use NvoosContentGraphAi\Analytics\TokenDbOptimizer;
use NvoosContentGraphAi\Analytics\TokenTrackingDatabase;
use NvoosContentGraphAi\Analytics\UsageTracker;

/**
 * @group analytics
 */
class Test_Token_Tracking extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Allow real DDL on the custom table.
		\remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// In monolith runs the base plugin's EnhancedTokenTracking hooks the
		// same action and writes into the same table — detach it so this
		// suite's recordings stay deterministic.
		\remove_all_actions( 'wp_mcp_ai_after_usage_recorded' );

		\delete_option( TokenTrackingDatabase::DB_VERSION_OPTION );
		\delete_option( TokenDbOptimizer::SCHEMA_VERSION_OPTION );

		TokenTrackingDatabase::create_or_update_table();
	}

	public function tearDown(): void {
		// Drop the real table BEFORE re-adding the framework temp-table filter.
		TokenTrackingDatabase::drop_table();

		\add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		\remove_all_actions( 'wp_mcp_ai_token_usage_recorded' );
		\delete_option( TokenTrackingDatabase::DB_VERSION_OPTION );
		\delete_option( TokenDbOptimizer::SCHEMA_VERSION_OPTION );

		// Always clean up optimizer indexes if a test left them behind.
		TokenDbOptimizer::remove_optimizations();

		parent::tearDown();
	}

	// ─── Schema + inserts ──────────────────────────────────────────

	public function test_schema_constants_and_version_bookkeeping(): void {
		$this->assertSame( '1.0.0', TokenTrackingDatabase::DB_VERSION );
		$this->assertSame( 'wp_mcp_ai_token_tracking_db_version', TokenTrackingDatabase::DB_VERSION_OPTION );
		$this->assertSame( 'mcp_ai_hourly_token_usage', TokenTrackingDatabase::TABLE_NAME );

		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'mcp_ai_hourly_token_usage', TokenTrackingDatabase::get_table_name() );

		// Version bookkeeping: already created → no re-create.
		TokenTrackingDatabase::maybe_create_or_update_table();
		$this->assertSame( '1.0.0', \get_option( TokenTrackingDatabase::DB_VERSION_OPTION ) );
	}

	public function test_record_usage_insert_validation_and_action(): void {
		$user_id = self::factory()->user->create();

		// Validation failures.
		$this->assertFalse( TokenTrackingDatabase::record_usage( 0, 'tool', 'openai', 'gpt-4o', 10, 10 ) );
		$this->assertFalse( TokenTrackingDatabase::record_usage( $user_id, 'tool', '', 'gpt-4o', 10, 10 ) );
		$this->assertFalse( TokenTrackingDatabase::record_usage( $user_id, 'tool', 'openai', '', 10, 10 ) );
		$this->assertFalse( TokenTrackingDatabase::record_usage( $user_id, 'tool', 'openai', 'gpt-4o', 0, 0 ) );

		// Successful insert + action.
		$fired = null;
		\add_action(
			'wp_mcp_ai_token_usage_recorded',
			static function ( $insert_id, $user, $tool, $provider, $model, $input, $output, $cost, $estimated ) use ( &$fired ) {
				$fired = array( $insert_id, $tool, $provider, $model, $input, $output, $cost, $estimated );
			},
			10,
			9
		);

		$record_id = TokenTrackingDatabase::record_usage(
			$user_id,
			'my_tool',
			'openai',
			'gpt-4o-mini',
			100,
			50,
			0.5,
			false,
			'2026-01-15 10:00:00'
		);

		$this->assertIsInt( $record_id );
		$this->assertSame( array( $record_id, 'my_tool', 'openai', 'gpt-4o-mini', 100, 50, 0.5, false ), $fired );

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '' );
		$this->assertCount( 1, $rows );
		$this->assertEquals( 150, (int) $rows[0]['total_tokens'] );
		$this->assertSame( '2026-01-15 10:00:00', $rows[0]['timestamp'] );
		$this->assertEquals( 0, (int) $rows[0]['is_estimated'] );
	}

	public function test_get_user_usage_date_and_tool_filters(): void {
		$user_id = self::factory()->user->create();
		$other   = self::factory()->user->create();

		TokenTrackingDatabase::record_usage( $user_id, 'tool_a', 'openai', 'gpt-4o', 10, 10, 0.1, true, '2026-01-10 12:00:00' );
		TokenTrackingDatabase::record_usage( $user_id, 'tool_b', 'openai', 'gpt-4o', 10, 10, 0.1, true, '2026-01-20 12:00:00' );
		TokenTrackingDatabase::record_usage( $other, 'tool_a', 'openai', 'gpt-4o', 10, 10, 0.1, true, '2026-01-15 12:00:00' );

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '2026-01-01 00:00:00', '2026-01-15 00:00:00' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'tool_a', $rows[0]['tool'] );

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '', 'tool_b' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'tool_b', $rows[0]['tool'] );

		$this->assertSame( array(), TokenTrackingDatabase::get_user_usage( 0, '', '' ) );
	}

	public function test_cost_summary_splits_estimated_and_actual(): void {
		$user_id = self::factory()->user->create();

		TokenTrackingDatabase::record_usage( $user_id, 'tool_a', 'openai', 'gpt-4o', 10, 10, 1.5, true, '2026-01-10 12:00:00' );
		TokenTrackingDatabase::record_usage( $user_id, 'tool_b', 'openai', 'gpt-4o', 10, 10, 2.5, false, '2026-01-11 12:00:00' );

		$summary = TokenTrackingDatabase::get_user_cost_summary( $user_id, '2026-01-01 00:00:00', '2026-12-31 23:59:59' );

		$this->assertSame( 4.0, $summary['total_cost'] );
		$this->assertSame( 40, $summary['total_tokens'] );
		$this->assertSame( 1.5, $summary['estimated_cost'] );
		$this->assertSame( 2.5, $summary['actual_cost'] );

		$this->assertSame(
			array(
				'total_cost'     => 0.0,
				'total_tokens'   => 0,
				'estimated_cost' => 0.0,
				'actual_cost'    => 0.0,
			),
			TokenTrackingDatabase::get_user_cost_summary( 0, '', '' )
		);
	}

	public function test_aggregations(): void {
		$u1 = self::factory()->user->create();
		$u2 = self::factory()->user->create();

		TokenTrackingDatabase::record_usage( $u1, 'tool_a', 'openai', 'gpt-4o', 100, 50, 0.25, true, '2026-01-10 12:00:00' );
		TokenTrackingDatabase::record_usage( $u1, 'tool_b', 'gemini', 'gemini-2.5-flash', 20, 20, 0.05, true, '2026-01-11 12:00:00' );
		TokenTrackingDatabase::record_usage( $u2, 'tool_a', 'openai', 'gpt-4o-mini', 10, 10, 0.02, true, '2026-01-12 12:00:00' );

		$by_provider = TokenTrackingDatabase::get_aggregated_by_provider( '2026-01-01 00:00:00', '2026-12-31 23:59:59' );
		$this->assertCount( 2, $by_provider );

		$by_model = TokenTrackingDatabase::get_aggregated_by_model( '2026-01-01 00:00:00', '2026-12-31 23:59:59' );
		$this->assertCount( 3, $by_model );

		$by_tool = TokenTrackingDatabase::get_aggregated_by_tool( '2026-01-01 00:00:00', '2026-12-31 23:59:59' );
		$this->assertCount( 2, $by_tool );

		$by_date = TokenTrackingDatabase::get_aggregated_by_date( '2026-01-01 00:00:00', '2026-12-31 23:59:59' );
		$this->assertCount( 3, $by_date );

		$by_user = TokenTrackingDatabase::get_aggregated_by_user( '2026-01-01 00:00:00', '2026-12-31 23:59:59' );
		$this->assertCount( 2, $by_user );
	}

	public function test_cleanup_old_records(): void {
		$user_id = self::factory()->user->create();

		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-120 days' ) );

		TokenTrackingDatabase::record_usage( $user_id, 'tool_a', 'openai', 'gpt-4o', 10, 10, 0.1, true, $old_date );
		TokenTrackingDatabase::record_usage( $user_id, 'tool_b', 'openai', 'gpt-4o', 10, 10, 0.1, true );

		$deleted = TokenTrackingDatabase::cleanup_old_records( 90 );

		$this->assertSame( 1, $deleted );
		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'tool_b', $rows[0]['tool'] );
	}

	// ─── Enhanced tracking ─────────────────────────────────────────

	public function test_enhanced_usage_60_40_split_from_total(): void {
		$user_id = self::factory()->user->create();

		EnhancedTokenTracking::record_enhanced_usage(
			$user_id,
			0,
			'openai',
			'gpt-4o',
			array(),
			array( 'total_tokens' => 100 )
		);

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'chat', $rows[0]['tool'] );
		$this->assertEquals( 60, (int) $rows[0]['input_tokens'] );
		$this->assertEquals( 40, (int) $rows[0]['output_tokens'] );
		$this->assertEquals( 0, (int) $rows[0]['is_estimated'] );
	}

	public function test_enhanced_tool_usage_source_priority(): void {
		$user_id = self::factory()->user->create();

		// Priority 1: result carries usage/provider/model → not estimated.
		EnhancedTokenTracking::record_tool_usage(
			'gemini_tool',
			array(),
			array( 'user_id' => $user_id ),
			array(
				'provider' => 'gemini',
				'model'    => 'gemini-2.5-flash',
				'usage'    => array(
					'prompt_tokens'     => 10,
					'completion_tokens' => 20,
				),
			)
		);

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'gemini_tool', $rows[0]['tool'] );
		$this->assertSame( 'gemini', $rows[0]['provider'] );
		$this->assertEquals( 0, (int) $rows[0]['is_estimated'] );

		// Priority 2: context token_usage + provider/model.
		EnhancedTokenTracking::record_tool_usage(
			'ctx_tool',
			array(),
			array(
				'user_id'     => $user_id,
				'token_usage' => array( 'total_tokens' => 40 ),
				'provider'    => 'openai',
				'model'       => 'gpt-4o',
			),
			array()
		);

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '', 'ctx_tool' );
		$this->assertCount( 1, $rows );
		$this->assertEquals( 20, (int) $rows[0]['input_tokens'] );
		$this->assertEquals( 20, (int) $rows[0]['output_tokens'] );
		$this->assertEquals( 0, (int) $rows[0]['is_estimated'] );

		// Priority 4: settings fallback → estimated.
		EnhancedTokenTracking::record_tool_usage(
			'settings_tool',
			array(),
			array(
				'user_id'     => $user_id,
				'token_usage' => array( 'total_tokens' => 10 ),
			),
			array()
		);

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '', 'settings_tool' );
		$this->assertCount( 1, $rows );
		$this->assertEquals( 1, (int) $rows[0]['is_estimated'] );

		// No usage anywhere → no record.
		EnhancedTokenTracking::record_tool_usage( 'noop_tool', array(), array( 'user_id' => $user_id ), array() );
		$this->assertCount( 0, TokenTrackingDatabase::get_user_usage( $user_id, '', '', 'noop_tool' ) );
	}

	public function test_backfill_from_usage_meta(): void {
		$user_id = self::factory()->user->create();

		UsageTracker::record_chat_usage(
			$user_id,
			0,
			array(),
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o',
				'usage'    => array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 50,
				),
			)
		);

		$results = EnhancedTokenTracking::backfill_historical_data( $user_id );

		$this->assertSame( 1, $results['users_processed'] );
		$this->assertSame( 1, $results['records_created'] );
		$this->assertSame( 1, $results['records_estimated'] );
		$this->assertSame( 0, $results['errors'] );

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'historical', $rows[0]['tool'] );
		$this->assertEquals( 1, (int) $rows[0]['is_estimated'] );
	}

	public function test_migrate_provider_misattributions(): void {
		$user_id = self::factory()->user->create();

		TokenTrackingDatabase::record_usage( $user_id, 'generate_gemini_image', 'openai', 'gpt-4o', 100, 100, 0.5, true, '2026-01-10 12:00:00' );

		$dry = EnhancedTokenTracking::migrate_provider_misattributions( true, 100 );

		$this->assertSame( 1, $dry['total_gemini_records'] );
		$this->assertSame( 1, $dry['total_needing_migration'] );
		$this->assertSame( 0, $dry['correctly_attributed'] );
		$this->assertSame( 1, $dry['records_updated'] );
		$this->assertSame( 'gemini', $dry['updates'][0]['new_provider'] );
		$this->assertSame( 'gemini-3.1-flash-image', $dry['updates'][0]['new_model'] );

		// Apply for real.
		$applied = EnhancedTokenTracking::migrate_provider_misattributions( false, 100 );
		$this->assertSame( 1, $applied['records_updated'] );

		$rows = TokenTrackingDatabase::get_user_usage( $user_id, '', '' );
		$this->assertSame( 'gemini', $rows[0]['provider'] );
		$this->assertSame( 'gemini-3.1-flash-image', $rows[0]['model'] );
		$this->assertEquals( 0, (int) $rows[0]['is_estimated'] );
	}

	public function test_get_user_statistics_shape(): void {
		$user_id = self::factory()->user->create();

		TokenTrackingDatabase::record_usage( $user_id, 'tool_a', 'openai', 'gpt-4o', 10, 10, 0.5, true, '2026-01-10 12:00:00' );

		$stats = EnhancedTokenTracking::get_user_statistics( $user_id, '2026-01-01 00:00:00', '2026-12-31 23:59:59' );

		$this->assertSame( 1, $stats['total_records'] );
		$this->assertSame( 1, $stats['by_provider']['openai']['records'] );
		$this->assertSame( 1, $stats['by_tool']['tool_a']['records'] );
		$this->assertSame( 20, $stats['summary']['total_tokens'] );
	}

	// ─── Optimizer ─────────────────────────────────────────────────

	public function test_optimizer_index_lifecycle(): void {
		// Optimize: indexes created and version recorded.
		TokenDbOptimizer::optimize_database();

		$stats = TokenDbOptimizer::get_optimization_stats();
		$this->assertTrue( $stats['tier_index_exists'] );
		$this->assertTrue( $stats['usage_index_exists'] );
		$this->assertSame( 2, $stats['wp_mcp_ai_indexes'] );

		// Second run is a no-op (indexes already present).
		TokenDbOptimizer::optimize_database();

		// Analyze queries.
		$analysis = TokenDbOptimizer::analyze_query_performance();
		$this->assertArrayHasKey( 'tier_lookup', $analysis );
		$this->assertArrayHasKey( 'usage_lookup', $analysis );

		// Remove.
		$this->assertTrue( TokenDbOptimizer::remove_optimizations() );

		$stats = TokenDbOptimizer::get_optimization_stats();
		$this->assertFalse( $stats['tier_index_exists'] );
		$this->assertFalse( $stats['usage_index_exists'] );
		$this->assertFalse( $stats['optimizations_active'] );
	}

	public function test_optimizer_version_gate(): void {
		// Already at current version → optimize_database not invoked.
		\update_option( TokenDbOptimizer::SCHEMA_VERSION_OPTION, TokenDbOptimizer::CURRENT_SCHEMA_VERSION, false );

		TokenDbOptimizer::maybe_optimize_database();

		$stats = TokenDbOptimizer::get_optimization_stats();
		$this->assertFalse( $stats['tier_index_exists'] );
	}
}
