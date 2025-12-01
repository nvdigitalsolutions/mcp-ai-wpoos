<?php
/**
 * Test Enhanced Token Tracking Database functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Token_Tracking_Database
 */
class Test_Token_Tracking_Database extends WP_UnitTestCase {

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
	 * Test table creation.
	 */
	public function test_table_creation() {
		global $wpdb;

		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();

		// Check table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$this->assertEquals( $table_name, $table_exists, 'Token tracking table should be created' );
	}

	/**
	 * Test recording token usage.
	 */
	public function test_record_usage() {
		$user_id       = $this->factory->user->create();
		$tool          = 'test_tool';
		$provider      = 'openai';
		$model         = 'gpt-4o-mini';
		$input_tokens  = 1000;
		$output_tokens = 500;
		$cost_usd      = 0.15;

		$insert_id = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			$tool,
			$provider,
			$model,
			$input_tokens,
			$output_tokens,
			$cost_usd,
			false
		);

		$this->assertNotFalse( $insert_id, 'Record should be inserted successfully' );
		$this->assertGreaterThan( 0, $insert_id, 'Insert ID should be positive' );
	}

	/**
	 * Test recording usage with automatic cost calculation.
	 */
	public function test_record_usage_auto_cost() {
		$user_id       = $this->factory->user->create();
		$tool          = 'chat';
		$provider      = 'openai';
		$model         = 'gpt-4o-mini';
		$input_tokens  = 1000;
		$output_tokens = 500;

		// Don't provide cost - should be calculated automatically.
		$insert_id = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			$tool,
			$provider,
			$model,
			$input_tokens,
			$output_tokens
		);

		$this->assertNotFalse( $insert_id, 'Record should be inserted with auto-calculated cost' );

		// Verify cost was calculated.
		global $wpdb;
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.
		$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $insert_id ), ARRAY_A );

		$this->assertNotNull( $record, 'Record should exist' );
		$this->assertGreaterThan( 0, floatval( $record['cost_usd'] ), 'Cost should be calculated and > 0' );
	}

	/**
	 * Test getting user usage.
	 */
	public function test_get_user_usage() {
		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		// Record some usage.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool1',
			'openai',
			'gpt-4o-mini',
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

		$usage = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertIsArray( $usage, 'Usage should be an array' );
		$this->assertCount( 2, $usage, 'Should have 2 usage records' );
	}

	/**
	 * Test getting user usage with tool filter.
	 */
	public function test_get_user_usage_with_tool_filter() {
		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		// Record usage for multiple tools.
		WP_MCP_AI_Token_Tracking_Database::record_usage( $user_id, 'tool1', 'openai', 'gpt-4o', 1000, 500 );
		WP_MCP_AI_Token_Tracking_Database::record_usage( $user_id, 'tool2', 'openai', 'gpt-4o', 1000, 500 );
		WP_MCP_AI_Token_Tracking_Database::record_usage( $user_id, 'tool1', 'openai', 'gpt-4o', 1000, 500 );

		$usage = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date, 'tool1' );

		$this->assertIsArray( $usage, 'Usage should be an array' );
		$this->assertCount( 2, $usage, 'Should have 2 records for tool1' );
	}

	/**
	 * Test getting cost summary.
	 */
	public function test_get_user_cost_summary() {
		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		// Record some actual costs.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool1',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			false // actual cost.
		);

		// Record some estimated costs.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool2',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			true // estimated cost.
		);

		$summary = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary( $user_id, $start_date, $end_date );

		$this->assertIsArray( $summary, 'Summary should be an array' );
		$this->assertArrayHasKey( 'total_cost', $summary );
		$this->assertArrayHasKey( 'total_tokens', $summary );
		$this->assertArrayHasKey( 'estimated_cost', $summary );
		$this->assertArrayHasKey( 'actual_cost', $summary );

		$this->assertEquals( 0.30, $summary['total_cost'], 'Total cost should be 0.30' );
		$this->assertEquals( 0.15, $summary['actual_cost'], 'Actual cost should be 0.15' );
		$this->assertEquals( 0.15, $summary['estimated_cost'], 'Estimated cost should be 0.15' );
		$this->assertEquals( 3000, $summary['total_tokens'], 'Total tokens should be 3000' );
	}

	/**
	 * Test cleanup of old records.
	 */
	public function test_cleanup_old_records() {
		$user_id = $this->factory->user->create();

		// Record usage 100 days ago (should be cleaned up with 90-day retention).
		$old_timestamp = gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) );
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool1',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			false,
			$old_timestamp
		);

		// Record recent usage (should NOT be cleaned up).
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool2',
			'openai',
			'gpt-4o-mini',
			1000,
			500,
			0.15,
			false
		);

		// Run cleanup with 90-day retention.
		$deleted = WP_MCP_AI_Token_Tracking_Database::cleanup_old_records( 90 );

		$this->assertEquals( 1, $deleted, 'Should delete 1 old record' );

		// Verify recent record still exists.
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );
		$usage      = WP_MCP_AI_Token_Tracking_Database::get_user_usage( $user_id, $start_date, $end_date );

		$this->assertCount( 1, $usage, 'Should have 1 recent record remaining' );
	}

	/**
	 * Test validation of required fields.
	 */
	public function test_record_usage_validation() {
		// Try to record without user ID.
		$result = WP_MCP_AI_Token_Tracking_Database::record_usage(
			0, // invalid user ID.
			'tool',
			'openai',
			'gpt-4o',
			1000,
			500
		);

		$this->assertFalse( $result, 'Should fail without valid user ID' );

		// Try to record without provider.
		$user_id = $this->factory->user->create();
		$result  = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool',
			'', // invalid provider.
			'gpt-4o',
			1000,
			500
		);

		$this->assertFalse( $result, 'Should fail without valid provider' );

		// Try to record without model.
		$result = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool',
			'openai',
			'', // invalid model.
			1000,
			500
		);

		$this->assertFalse( $result, 'Should fail without valid model' );

		// Try to record with zero tokens.
		$result = WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool',
			'openai',
			'gpt-4o',
			0, // zero tokens.
			0
		);

		$this->assertFalse( $result, 'Should fail with zero tokens' );
	}

	/**
	 * Test empty result for non-existent user.
	 */
	public function test_get_usage_for_nonexistent_user() {
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		$usage = WP_MCP_AI_Token_Tracking_Database::get_user_usage( 99999, $start_date, $end_date );

		$this->assertIsArray( $usage, 'Usage should be an array' );
		$this->assertEmpty( $usage, 'Usage should be empty for non-existent user' );
	}

	/**
	 * Test cost summary for user with no usage.
	 */
	public function test_cost_summary_no_usage() {
		$user_id    = $this->factory->user->create();
		$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$end_date   = gmdate( 'Y-m-d H:i:s' );

		$summary = WP_MCP_AI_Token_Tracking_Database::get_user_cost_summary( $user_id, $start_date, $end_date );

		$this->assertIsArray( $summary, 'Summary should be an array' );
		$this->assertEquals( 0.0, $summary['total_cost'], 'Total cost should be 0' );
		$this->assertEquals( 0, $summary['total_tokens'], 'Total tokens should be 0' );
	}

	/**
	 * Test action hook is fired after recording.
	 */
	public function test_action_hook_fired() {
		$user_id = $this->factory->user->create();
		$fired   = false;

		$callback = function () use ( &$fired ) {
			$fired = true;
		};

		add_action( 'wp_mcp_ai_token_usage_recorded', $callback );

		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$user_id,
			'tool',
			'openai',
			'gpt-4o',
			1000,
			500
		);

		$this->assertTrue( $fired, 'Action hook should be fired after recording usage' );

		remove_action( 'wp_mcp_ai_token_usage_recorded', $callback );
	}
}
