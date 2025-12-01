<?php
/**
 * Test Enhanced Token Tracking Integration functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Enhanced_Token_Tracking
 */
class Test_Enhanced_Token_Tracking extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize the database table.
		WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test data.
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );

		parent::tearDown();
	}

	/**
	 * Test enhanced usage recording via action hook.
	 */
	public function test_record_enhanced_usage_via_hook() {
		$user_id      = $this->factory->user->create();
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );
		$provider     = 'openai';
		$model        = 'gpt-4o-mini';

		$totals = array(
			'requests'          => 1,
			'prompt_tokens'     => 1000,
			'completion_tokens' => 500,
			'total_tokens'      => 1500,
		);

		$usage = array(
			'prompt_tokens'     => 1000,
			'completion_tokens' => 500,
			'total_tokens'      => 1500,
		);

		// Trigger the hook that enhanced tracking listens to.
		do_action( 'wp_mcp_ai_after_usage_recorded', $user_id, $assistant_id, $provider, $model, $totals, $usage );

		// Verify record was created.
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $records, 'Should have 1 usage record' );
		$this->assertEquals( $provider, $records[0]['provider'], 'Provider should match' );
		$this->assertEquals( $model, $records[0]['model'], 'Model should match' );
		$this->assertEquals( 1000, $records[0]['input_tokens'], 'Input tokens should match' );
		$this->assertEquals( 500, $records[0]['output_tokens'], 'Output tokens should match' );
	}

	/**
	 * Test token split estimation when separate counts not available.
	 */
	public function test_token_split_estimation() {
		$user_id      = $this->factory->user->create();
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		$usage = array(
			'total_tokens' => 1000, // No separate input/output.
		);

		do_action( 'wp_mcp_ai_after_usage_recorded', $user_id, $assistant_id, 'openai', 'gpt-4o', array(), $usage );

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $records, 'Should have 1 record' );
		// Should estimate 60/40 split.
		$this->assertEquals( 600, $records[0]['input_tokens'], 'Should estimate 60% input' );
		$this->assertEquals( 400, $records[0]['output_tokens'], 'Should estimate 40% output' );
	}

	/**
	 * Test tool usage recording.
	 */
	public function test_record_tool_usage() {
		$user_id = $this->factory->user->create();

		$tool_name = 'test_tool';
		$arguments = array();
		$context   = array(
			'user_id'     => $user_id,
			'provider'    => 'openai',
			'model'       => 'gpt-4o-mini',
			'token_usage' => array(
				'prompt_tokens'     => 500,
				'completion_tokens' => 250,
			),
		);
		$result    = array( 'success' => true );

		do_action( 'wp_mcp_ai_after_tool_execution', $tool_name, $arguments, $context, $result );

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $records, 'Should have 1 tool usage record' );
		$this->assertEquals( $tool_name, $records[0]['tool'], 'Tool name should match' );
		$this->assertEquals( 'openai', $records[0]['provider'], 'Provider should match' );
		$this->assertEquals( 500, $records[0]['input_tokens'], 'Input tokens should match' );
		$this->assertEquals( 250, $records[0]['output_tokens'], 'Output tokens should match' );
		$this->assertEquals( 0, $records[0]['is_estimated'], 'Should be actual cost (provider/model known)' );
	}

	/**
	 * Test tool usage with provider/model/usage from result (Priority 1).
	 *
	 * This tests the new functionality where tools return provider/model/usage
	 * in their results, which should take priority over context or settings.
	 */
	public function test_record_tool_usage_from_result() {
		$user_id = $this->factory->user->create();

		$tool_name = 'gemini_tool';
		$arguments = array();
		$context   = array(
			'user_id' => $user_id,
			// No token_usage in context - should come from result.
		);
		$result = array(
			'success'  => true,
			'provider' => 'gemini', // Tool reports it used Gemini.
			'model'    => 'gemini-1.5-flash',
			'usage'    => array(
				'prompt_tokens'     => 800,
				'completion_tokens' => 400,
				'total_tokens'      => 1200,
			),
		);

		do_action( 'wp_mcp_ai_after_tool_execution', $tool_name, $arguments, $context, $result );

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $records, 'Should have 1 tool usage record' );
		$this->assertEquals( $tool_name, $records[0]['tool'], 'Tool name should match' );
		$this->assertEquals( 'gemini', $records[0]['provider'], 'Provider should be from result' );
		$this->assertEquals( 'gemini-1.5-flash', $records[0]['model'], 'Model should be from result' );
		$this->assertEquals( 800, $records[0]['input_tokens'], 'Input tokens should match' );
		$this->assertEquals( 400, $records[0]['output_tokens'], 'Output tokens should match' );
		$this->assertEquals( 0, $records[0]['is_estimated'], 'Should NOT be estimated (provider/model from result)' );
	}

	/**
	 * Test tool usage with inferred provider/model.
	 */
	public function test_tool_usage_with_inferred_provider() {
		$user_id = $this->factory->user->create();

		$tool_name = 'inferred_tool';
		$arguments = array();
		$context   = array(
			'user_id'     => $user_id,
			// No provider/model - should be inferred from settings.
			'token_usage' => array(
				'total_tokens' => 1000,
			),
		);
		$result    = array();

		do_action( 'wp_mcp_ai_after_tool_execution', $tool_name, $arguments, $context, $result );

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $records, 'Should have 1 record' );
		$this->assertNotEmpty( $records[0]['provider'], 'Provider should be inferred' );
		$this->assertNotEmpty( $records[0]['model'], 'Model should be inferred' );
		$this->assertEquals( 1, $records[0]['is_estimated'], 'Should be marked as estimated' );
	}

	/**
	 * Test getting user statistics.
	 */
	public function test_get_user_statistics() {
		$user_id = $this->factory->user->create();

		// Record usage from different providers.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool1',
			'openai',
			'gpt-4o',
			1000,
			500,
			0.15,
			false
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool2',
			'gemini',
			'gemini-1.5-flash',
			2000,
			1000,
			0.25,
			false
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool1',
			'openai',
			'gpt-4o-mini',
			500,
			250,
			0.05,
			false
		);

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$stats      = WP_MCP_AI_Enhanced_Token_Tracking::get_user_statistics( $user_id, $start_date, $end_date );

		$this->assertIsArray( $stats, 'Statistics should be an array' );
		$this->assertArrayHasKey( 'summary', $stats );
		$this->assertArrayHasKey( 'by_provider', $stats );
		$this->assertArrayHasKey( 'by_tool', $stats );

		// Check summary.
		$this->assertEquals( 0.45, $stats['summary']['total_cost'], 'Total cost should be 0.45' );
		$this->assertEquals( 5250, $stats['summary']['total_tokens'], 'Total tokens should be 5250' );

		// Check by provider.
		$this->assertArrayHasKey( 'openai', $stats['by_provider'] );
		$this->assertArrayHasKey( 'gemini', $stats['by_provider'] );
		$this->assertEquals( 2, $stats['by_provider']['openai']['records'], 'Should have 2 OpenAI records' );
		$this->assertEquals( 1, $stats['by_provider']['gemini']['records'], 'Should have 1 Gemini record' );

		// Check by tool.
		$this->assertArrayHasKey( 'tool1', $stats['by_tool'] );
		$this->assertArrayHasKey( 'tool2', $stats['by_tool'] );
		$this->assertEquals( 2, $stats['by_tool']['tool1']['records'], 'Should have 2 tool1 records' );
		$this->assertEquals( 1, $stats['by_tool']['tool2']['records'], 'Should have 1 tool2 record' );
	}

	/**
	 * Test historical data backfill.
	 */
	public function test_backfill_historical_data() {
		$user_id = $this->factory->user->create();

		// Simulate existing usage data in user meta (from WP_MCP_AI_Usage_Tracker).
		$historical_usage = array(
			'openai' => array(
				'gpt-4o-mini' => array(
					'requests'          => 5,
					'prompt_tokens'     => 5000,
					'completion_tokens' => 2500,
					'total_tokens'      => 7500,
				),
			),
			'gemini' => array(
				'gemini-1.5-flash' => array(
					'requests'     => 3,
					'total_tokens' => 6000,
				),
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY, $historical_usage );

		// Run backfill.
		$results = WP_MCP_AI_Enhanced_Token_Tracking::backfill_historical_data( $user_id );

		$this->assertIsArray( $results, 'Results should be an array' );
		$this->assertEquals( 1, $results['users_processed'], 'Should process 1 user' );
		$this->assertEquals( 2, $results['records_created'], 'Should create 2 historical records' );
		$this->assertEquals( 2, $results['records_estimated'], 'All historical records should be estimated' );

		// Verify records were created.
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 2, $records, 'Should have 2 backfilled records' );

		foreach ( $records as $record ) {
			$this->assertEquals( 'historical', $record['tool'], 'Tool should be marked as historical' );
			$this->assertEquals( 1, $record['is_estimated'], 'Should be marked as estimated' );
		}
	}

	/**
	 * Test that tracking doesn't record when no token usage in context.
	 */
	public function test_no_recording_without_token_usage() {
		$user_id = $this->factory->user->create();

		$context = array(
			'user_id' => $user_id,
			// No token_usage in context.
		);

		do_action( 'wp_mcp_ai_after_tool_execution', 'no_tokens_tool', array(), array(), $context );

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 minute' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$records    = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertEmpty( $records, 'Should not record without token usage data' );
	}

	/**
	 * Test cleanup scheduling.
	 */
	public function test_cleanup_scheduled() {
		// Initialize should schedule cleanup.
		WP_MCP_AI_Enhanced_Token_Tracking::init();

		$scheduled = wp_next_scheduled( 'wp_mcp_ai_cleanup_token_tracking' );

		$this->assertNotFalse( $scheduled, 'Cleanup task should be scheduled' );
	}

	/**
	 * Test statistics with no data.
	 */
	public function test_statistics_empty() {
		$user_id = $this->factory->user->create();

		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$stats      = WP_MCP_AI_Enhanced_Token_Tracking::get_user_statistics( $user_id, $start_date, $end_date );

		$this->assertIsArray( $stats, 'Statistics should be an array' );
		$this->assertEquals( 0.0, $stats['summary']['total_cost'], 'Total cost should be 0' );
		$this->assertEquals( 0, $stats['summary']['total_tokens'], 'Total tokens should be 0' );
		$this->assertEmpty( $stats['by_provider'], 'By provider should be empty' );
		$this->assertEmpty( $stats['by_tool'], 'By tool should be empty' );
	}

	/**
	 * Test provider migration for historical misattributions.
	 */
	public function test_migrate_provider_misattributions() {
		$user_id = $this->factory->user->create();

		// Create records with incorrect provider attribution.
		// Gemini tools that were incorrectly tracked as OpenAI.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'generate_gemini_image',
			'openai', // WRONG - should be gemini.
			'gpt-4o-mini',
			1000,
			500,
			null, // Let it calculate with wrong provider.
			true
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'edit_gemini_image',
			'openai', // WRONG - should be gemini.
			'gpt-4o',
			2000,
			1000,
			null,
			true
		);

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'analyze_comment_content',
			'openai', // WRONG - could be gemini.
			'gpt-4o-mini',
			500,
			250,
			null,
			true
		);

		// Also create a correctly attributed record.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'some_other_tool',
			'openai',
			'gpt-4o-mini',
			100,
			50,
			null,
			false
		);

		// Run dry run migration.
		$dry_results = WP_MCP_AI_Enhanced_Token_Tracking::migrate_provider_misattributions( true, 100 );

		$this->assertTrue( $dry_results['dry_run'], 'Should be a dry run' );
		$this->assertEquals( 3, $dry_results['total_checked'], 'Should check 3 Gemini tool records' );
		$this->assertEquals( 3, $dry_results['records_updated'], 'Should plan to update 3 records' );
		$this->assertCount( 3, $dry_results['updates'], 'Should have 3 update details' );

		// Verify updates are planned correctly.
		foreach ( $dry_results['updates'] as $update ) {
			$this->assertEquals( 'openai', $update['old_provider'], 'Old provider should be OpenAI' );
			$this->assertEquals( 'gemini', $update['new_provider'], 'New provider should be Gemini' );
			$this->assertNotEquals( $update['old_cost'], $update['new_cost'], 'Cost should be recalculated' );
		}

		// Run actual migration.
		$results = WP_MCP_AI_Enhanced_Token_Tracking::migrate_provider_misattributions( false, 100 );

		$this->assertFalse( $results['dry_run'], 'Should not be a dry run' );
		$this->assertEquals( 3, $results['records_updated'], 'Should have updated 3 records' );

		// Verify database was updated.
		global $wpdb;
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching.
		$gemini_records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tool, provider, model, is_estimated FROM {$table_name} WHERE provider = %s ORDER BY tool",
				'gemini'
			),
			ARRAY_A
		);

		$this->assertCount( 3, $gemini_records, 'Should have 3 Gemini records after migration' );

		// Verify specific tools were updated.
		$tools_updated = array_column( $gemini_records, 'tool' );
		$this->assertContains( 'generate_gemini_image', $tools_updated );
		$this->assertContains( 'edit_gemini_image', $tools_updated );
		$this->assertContains( 'analyze_comment_content', $tools_updated );

		// Verify is_estimated was updated to 0 (actual).
		foreach ( $gemini_records as $record ) {
			$this->assertEquals( 0, $record['is_estimated'], 'Migrated records should not be marked as estimated' );
		}

		// Verify OpenAI record was not touched.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching.
		$openai_records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tool FROM {$table_name} WHERE provider = %s",
				'openai'
			),
			ARRAY_A
		);

		$this->assertCount( 1, $openai_records, 'Should still have 1 OpenAI record' );
		$this->assertEquals( 'some_other_tool', $openai_records[0]['tool'], 'Correct tool should remain as OpenAI' );
	}
}
