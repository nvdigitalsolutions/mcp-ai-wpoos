<?php
/**
 * Tests for CSV Export functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test CSV export functionality.
 */
class Test_CSV_Export extends WP_UnitTestCase {

	/**
	 * Test export_usage_report returns CSV format.
	 */
	public function test_export_returns_csv_format() {
		// Create test user with admin capability.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create a test user with usage data.
		$user_id = $this->factory->user->create(
			array(
				'user_login' => 'testuser',
				'user_email' => 'test@example.com',
				'role'       => 'subscriber',
			)
		);

		// Set tier for test user.
		WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user_id, 'pro' );

		// Add some usage data.
		$usage = array(
			'general_tools' => array(
				'total_tokens' => 5000,
				'requests'     => 10,
				'last_used'    => gmdate( 'Y-m-d H:i:s' ),
			),
		);
		update_user_meta( $user_id, '_wp_mcp_ai_tool_token_usage', $usage );

		// Export CSV.
		$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report();

		// Assert CSV is not empty.
		$this->assertNotEmpty( $csv );

		// Assert CSV contains header row.
		$this->assertStringContainsString( 'User ID', $csv );
		$this->assertStringContainsString( 'Username', $csv );
		$this->assertStringContainsString( 'Email', $csv );
		$this->assertStringContainsString( 'Tier', $csv );

		// Assert CSV contains user data.
		$this->assertStringContainsString( 'testuser', $csv );
		$this->assertStringContainsString( 'test@example.com', $csv );
		$this->assertStringContainsString( 'pro', $csv );
	}

	/**
	 * Test export_usage_report requires admin capability.
	 */
	public function test_export_requires_admin_capability() {
		// Create non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Try to export - should return empty string.
		$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report();

		$this->assertEmpty( $csv );
	}

	/**
	 * Test export with tier filter.
	 */
	public function test_export_with_tier_filter() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create pro tier user.
		$pro_user_id = $this->factory->user->create(
			array(
				'user_login' => 'prouser',
				'role'       => 'editor',
			)
		);
		WP_MCP_AI_Tool_Token_Limits::set_user_tier( $pro_user_id, 'pro' );
		update_user_meta(
			$pro_user_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'general_tools' => array(
					'total_tokens' => 1000,
					'requests'     => 5,
				),
			)
		);

		// Create free tier user.
		$free_user_id = $this->factory->user->create(
			array(
				'user_login' => 'freeuser',
				'role'       => 'subscriber',
			)
		);
		update_user_meta(
			$free_user_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'general_tools' => array(
					'total_tokens' => 500,
					'requests'     => 2,
				),
			)
		);

		// Export with pro tier filter.
		$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report( array( 'tier' => 'pro' ) );

		// Assert pro user is included.
		$this->assertStringContainsString( 'prouser', $csv );

		// Assert free user is NOT included.
		$this->assertStringNotContainsString( 'freeuser', $csv );
	}

	/**
	 * Test export with tool filter.
	 */
	public function test_export_with_tool_filter() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create user with crawl4ai usage.
		$crawl_user_id = $this->factory->user->create(
			array(
				'user_login' => 'crawluser',
			)
		);
		update_user_meta(
			$crawl_user_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'run_crawl4ai_job' => array(
					'total_tokens' => 10000,
					'requests'     => 5,
				),
			)
		);

		// Create user with general tools usage.
		$general_user_id = $this->factory->user->create(
			array(
				'user_login' => 'generaluser',
			)
		);
		update_user_meta(
			$general_user_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'general_tools' => array(
					'total_tokens' => 1000,
					'requests'     => 2,
				),
			)
		);

		// Export with crawl4ai filter.
		$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report( array( 'tool' => 'run_crawl4ai_job' ) );

		// Assert crawl user is included.
		$this->assertStringContainsString( 'crawluser', $csv );

		// Assert general user is NOT included.
		$this->assertStringNotContainsString( 'generaluser', $csv );
	}

	/**
	 * Test CSV format is valid.
	 */
	public function test_csv_format_is_valid() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create test user.
		$user_id = $this->factory->user->create(
			array(
				'user_login' => 'csvtest',
				'user_email' => 'csv@test.com',
			)
		);
		update_user_meta(
			$user_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'general_tools' => array(
					'total_tokens' => 5000,
					'requests'     => 10,
					'last_used'    => gmdate( 'Y-m-d H:i:s' ),
				),
			)
		);

		// Export CSV.
		$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report();

		// Parse CSV.
		$rows = str_getcsv( $csv, "\n" );

		// Assert we have at least header + 1 data row.
		$this->assertGreaterThanOrEqual( 2, count( $rows ) );

		// Parse header row.
		$header = str_getcsv( $rows[0] );

		// Assert header has expected columns.
		$this->assertContains( 'User ID', $header );
		$this->assertContains( 'Username', $header );
		$this->assertContains( 'Email', $header );
		$this->assertContains( 'Tier', $header );
		$this->assertContains( 'Total Tokens', $header );
		$this->assertContains( 'Total Requests', $header );
		$this->assertContains( 'Last Used', $header );
		$this->assertContains( 'Limit', $header );
		$this->assertContains( 'Usage %', $header );
	}

	/**
	 * Test export with multiple users.
	 */
	public function test_export_with_multiple_users() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create multiple users with usage.
		$user_count = 5;
		for ( $i = 0; $i < $user_count; $i++ ) {
			$user_id = $this->factory->user->create(
				array(
					'user_login' => "testuser{$i}",
				)
			);
			update_user_meta(
				$user_id,
				'_wp_mcp_ai_tool_token_usage',
				array(
					'general_tools' => array(
						'total_tokens' => 1000 * ( $i + 1 ),
						'requests'     => $i + 1,
					),
				)
			);
		}

		// Export CSV.
		$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report();

		// Parse CSV.
		$rows = str_getcsv( $csv, "\n" );

		// Assert we have header + user count rows.
		$this->assertGreaterThanOrEqual( $user_count + 1, count( $rows ) );

		// Assert all users are in CSV.
		for ( $i = 0; $i < $user_count; $i++ ) {
			$this->assertStringContainsString( "testuser{$i}", $csv );
		}
	}

	/**
	 * Test usage percentage calculation.
	 */
	public function test_usage_percentage_calculation() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create user with 50% usage.
		$user_id = $this->factory->user->create(
			array(
				'user_login' => 'percenttest',
			)
		);
		WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user_id, 'free' );

		// Free tier limit is 50000, so 25000 tokens = 50%.
		update_user_meta(
			$user_id,
			'_wp_mcp_ai_tool_token_usage',
			array(
				'general_tools' => array(
					'total_tokens' => 25000,
					'requests'     => 10,
				),
			)
		);

		// Export CSV.
		$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report();

		// Assert percentage is calculated and displayed.
		$this->assertStringContainsString( '%', $csv );
	}
}
