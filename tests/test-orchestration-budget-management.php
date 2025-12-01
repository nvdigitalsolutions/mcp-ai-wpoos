<?php
/**
 * Tests for Orchestration Layer Budget Management.
 *
 * Tests the predict, orchestrate, and adjust functionality of the orchestration layer.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class WP_MCP_AI_Orchestration_Budget_Test
 */
class WP_MCP_AI_Orchestration_Budget_Test extends WP_UnitTestCase {

	/**
	 * User ID for testing.
	 *
	 * @var int
	 */
	protected static $user_id;

	/**
	 * Set up before all tests.
	 *
	 * @param WP_UnitTest_Factory $factory Factory instance.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$user_id = $factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
	}

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset user tool usage before each test.
		if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( self::$user_id );
		}
	}

	/**
	 * Test that tool token limits class exists.
	 */
	public function test_tool_token_limits_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) );
	}

	/**
	 * Test that budget enforcement can be disabled via filter.
	 */
	public function test_budget_enforcement_can_be_disabled() {
		// Disable enforcement.
		add_filter( 'wp_mcp_ai_enforce_tool_token_limits', '__return_false' );

		// Set user usage to exceed limit.
		$this->set_user_usage_above_limit( 'test_tool' );

		// Check should not throw exception when enforcement disabled.
		$context = array( 'user_id' => self::$user_id );

		try {
			WP_MCP_AI_Tool_Token_Limits::check_tool_limit( 'test_tool', array(), $context );
			$exception_thrown = false;
		} catch ( Exception $e ) {
			$exception_thrown = true;
		}

		$this->assertFalse( $exception_thrown, 'Exception should not be thrown when enforcement is disabled' );

		// Clean up.
		remove_filter( 'wp_mcp_ai_enforce_tool_token_limits', '__return_false' );
	}

	/**
	 * Test that budget enforcement throws exception when limit exceeded.
	 */
	public function test_budget_enforcement_throws_exception_when_exceeded() {
		// Set user usage to exceed limit.
		$this->set_user_usage_above_limit( 'test_tool' );

		$context = array( 'user_id' => self::$user_id );

		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/Daily token limit exceeded/' );

		WP_MCP_AI_Tool_Token_Limits::check_tool_limit( 'test_tool', array(), $context );
	}

	/**
	 * Test that adjust_tool_result_for_budget returns small results unchanged.
	 */
	public function test_adjust_tool_result_returns_small_results_unchanged() {
		$small_result = 'This is a small result that fits within budget.';
		$adjusted     = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget(
			$small_result,
			'test_tool',
			array()
		);

		$this->assertEquals( $small_result, $adjusted );
	}

	/**
	 * Test that adjust_tool_result_for_budget truncates large string results.
	 */
	public function test_adjust_tool_result_truncates_large_strings() {
		// Create a large string that will exceed budget (simulate 10k tokens = 40k chars).
		$large_result = str_repeat( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 800 );

		$adjusted = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget(
			$large_result,
			'test_tool',
			array()
		);

		// Adjusted result should be shorter.
		$this->assertLessThan( strlen( $large_result ), strlen( $adjusted ) );

		// Should contain truncation notice.
		$this->assertStringContainsString( 'truncated by orchestration layer', $adjusted );
	}

	/**
	 * Test that adjust_tool_result_for_budget handles array results intelligently.
	 */
	public function test_adjust_tool_result_handles_arrays_intelligently() {
		// Create a result with markdown field (common pattern).
		$large_markdown = str_repeat( 'Lorem ipsum dolor sit amet. ', 2000 );
		$result         = array(
			'url'      => 'https://example.com',
			'title'    => 'Test Page',
			'markdown' => $large_markdown,
			'metadata' => array( 'author' => 'Test' ),
		);

		$adjusted = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget(
			$result,
			'test_tool',
			array()
		);

		// Should still be an array.
		$this->assertIsArray( $adjusted );

		// URL and title should be preserved.
		$this->assertEquals( 'https://example.com', $adjusted['url'] );
		$this->assertEquals( 'Test Page', $adjusted['title'] );

		// Markdown should be truncated.
		$this->assertLessThan( strlen( $large_markdown ), strlen( $adjusted['markdown'] ) );
	}

	/**
	 * Test that high-output tools get higher token limits.
	 */
	public function test_high_output_tools_get_higher_limits() {
		// Create a moderately sized result.
		$result = str_repeat( 'Test content. ', 400 ); // ~1600 chars = ~400 tokens.

		// Regular tool should be truncated on low tier.
		add_filter(
			'wp_mcp_ai_workload_tier',
			function () {
				return 'low';
			}
		);

		$adjusted_normal = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget(
			$result,
			'normal_tool',
			array()
		);

		// High-output tool should NOT be truncated.
		$adjusted_crawl = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget(
			$result,
			'run_crawl4ai_job',
			array()
		);

		// Clean up filter.
		remove_all_filters( 'wp_mcp_ai_workload_tier' );

		// High-output tool should allow more content.
		$this->assertGreaterThanOrEqual( strlen( $adjusted_normal ), strlen( $adjusted_crawl ) );
	}

	/**
	 * Test that workload tier affects budget limits.
	 */
	public function test_workload_tier_affects_budget_limits() {
		// Create a result that's too big for low tier but fine for high tier.
		$result = str_repeat( 'Test content. ', 200 ); // ~800 chars = ~200 tokens.

		// Low tier should truncate.
		add_filter(
			'wp_mcp_ai_workload_tier',
			function () {
				return 'low';
			}
		);

		$adjusted_low = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget(
			$result,
			'test_tool',
			array()
		);

		remove_all_filters( 'wp_mcp_ai_workload_tier' );

		// High tier should not truncate.
		add_filter(
			'wp_mcp_ai_workload_tier',
			function () {
				return 'high';
			}
		);

		$adjusted_high = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget(
			$result,
			'test_tool',
			array()
		);

		remove_all_filters( 'wp_mcp_ai_workload_tier' );

		// High tier should allow more content.
		$this->assertGreaterThanOrEqual( strlen( $adjusted_low ), strlen( $adjusted_high ) );
	}

	/**
	 * Test that max tokens filter is respected.
	 */
	public function test_max_tokens_filter_is_respected() {
		$result = str_repeat( 'Test. ', 1000 );

		// Set a very low limit via filter.
		add_filter(
			'wp_mcp_ai_tool_result_max_tokens',
			function () {
				return 10; // Very restrictive.
			}
		);

		$adjusted = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget(
			$result,
			'test_tool',
			array()
		);

		remove_all_filters( 'wp_mcp_ai_tool_result_max_tokens' );

		// Result should be very short due to low limit.
		$this->assertLessThan( 100, strlen( $adjusted ) );
	}

	/**
	 * Test tool usage recording.
	 */
	public function test_tool_usage_is_recorded() {
		$context = array( 'user_id' => self::$user_id );
		$result  = 'Test result with some content to count tokens.';

		// Simulate recording usage.
		WP_MCP_AI_Tool_Token_Limits::record_tool_usage(
			'test_tool',
			array(),
			$context,
			$result
		);

		// Check usage was recorded.
		$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_daily_usage( self::$user_id, 'test_tool' );

		$this->assertGreaterThan( 0, $usage );
	}

	/**
	 * Helper: Set user usage above limit for a tool.
	 *
	 * @param string $tool_slug Tool slug.
	 */
	protected function set_user_usage_above_limit( $tool_slug ) {
		$limit    = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( $tool_slug );
		$date_key = gmdate( 'Y-m-d', time() );

		$usage = array(
			$tool_slug => array(
				'total_tokens' => $limit + 1000,
				'requests'     => 10,
				'first_used'   => current_time( 'mysql', true ),
				'last_used'    => current_time( 'mysql', true ),
				'daily'        => array(
					$date_key => $limit + 1000,
				),
			),
		);

		update_user_meta( self::$user_id, '_wp_mcp_ai_tool_token_usage', $usage );
	}
}
