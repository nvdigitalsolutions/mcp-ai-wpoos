<?php
/**
 * Test chart "Today" option and new distribution charts for Token Manager
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for "Today" option and provider/model distribution charts
 */
class Test_Chart_Today_Option extends WP_UnitTestCase {

	/**
	 * Test user IDs.
	 *
	 * @var array
	 */
	protected $test_user_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test users.
		$this->test_user_ids = array(
			'user1' => $this->factory->user->create( array( 'role' => 'subscriber' ) ),
			'user2' => $this->factory->user->create( array( 'role' => 'editor' ) ),
		);

		// Add some usage data for testing.
		$this->add_test_usage_data();
	}

	/**
	 * Clean up after test.
	 */
	public function tearDown(): void {
		foreach ( $this->test_user_ids as $user_id ) {
			delete_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
			delete_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );
		}

		parent::tearDown();
	}

	/**
	 * Add test usage data.
	 */
	private function add_test_usage_data() {
		$today = gmdate( 'Y-m-d' );

		// Add usage for user1 - OpenAI GPT-4.
		$usage_data = array(
			'openai' => array(
				'gpt-4' => array(
					'total_tokens'      => 5000,
					'prompt_tokens'     => 3000,
					'completion_tokens' => 2000,
					'requests'          => 10,
				),
			),
		);
		update_user_meta( $this->test_user_ids['user1'], WP_MCP_AI_Usage_Tracker::USER_META_KEY, $usage_data );

		// Add tool-specific usage for user1.
		$tool_usage = array(
			'general_tools' => array(
				'total_tokens' => 5000,
				'requests'     => 10,
				'daily'        => array(
					$today => 5000,
				),
			),
		);
		update_user_meta( $this->test_user_ids['user1'], WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $tool_usage );

		// Add usage for user2 - Google Gemini and Anthropic Claude.
		$usage_data = array(
			'google'    => array(
				'gemini-pro' => array(
					'total_tokens'      => 8000,
					'prompt_tokens'     => 5000,
					'completion_tokens' => 3000,
					'requests'          => 15,
				),
			),
			'anthropic' => array(
				'claude-3-opus' => array(
					'total_tokens'      => 3000,
					'prompt_tokens'     => 2000,
					'completion_tokens' => 1000,
					'requests'          => 5,
				),
			),
		);
		update_user_meta( $this->test_user_ids['user2'], WP_MCP_AI_Usage_Tracker::USER_META_KEY, $usage_data );

		// Add tool-specific usage for user2.
		$tool_usage = array(
			'general_tools' => array(
				'total_tokens' => 11000,
				'requests'     => 20,
				'daily'        => array(
					$today => 11000,
				),
			),
		);
		update_user_meta( $this->test_user_ids['user2'], WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $tool_usage );
	}

	/**
	 * Test get_usage_trend_data with 1 day (Today).
	 */
	public function test_get_usage_trend_data_today() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => 1 ) );

		// Verify data structure.
		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'labels', $data, 'Should have labels' );
		$this->assertArrayHasKey( 'datasets', $data, 'Should have datasets' );

		// Verify we have exactly 1 label for today.
		$this->assertEquals( 1, count( $data['labels'] ), 'Should have 1 label for today' );

		// Verify the label is today's date.
		$today = gmdate( 'Y-m-d' );
		$this->assertEquals( $today, $data['labels'][0], 'Label should be today\'s date' );

		// Verify datasets structure.
		$this->assertIsArray( $data['datasets'], 'Datasets should be an array' );
		if ( ! empty( $data['datasets'] ) ) {
			$this->assertArrayHasKey( 'label', $data['datasets'][0], 'Dataset should have label' );
			$this->assertArrayHasKey( 'data', $data['datasets'][0], 'Dataset should have data' );
			$this->assertEquals( 1, count( $data['datasets'][0]['data'] ), 'Should have 1 data point for today' );
		}
	}

	/**
	 * Test get_provider_distribution_data.
	 */
	public function test_get_provider_distribution_data() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_provider_distribution_data();

		// Verify data structure.
		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'labels', $data, 'Should have labels' );
		$this->assertArrayHasKey( 'values', $data, 'Should have values' );
		$this->assertArrayHasKey( 'colors', $data, 'Should have colors' );
		$this->assertArrayHasKey( 'datasets', $data, 'Should have datasets' );

		// Verify labels and values have same count.
		$this->assertEquals(
			count( $data['labels'] ),
			count( $data['values'] ),
			'Labels and values should have same count'
		);

		// Verify colors match count.
		$this->assertEquals(
			count( $data['labels'] ),
			count( $data['colors'] ),
			'Colors should match label count'
		);

		// Verify we have the expected providers from test data.
		$provider_labels = array_map( 'strtolower', $data['labels'] );
		$this->assertContains( 'openai', $provider_labels, 'Should have OpenAI provider' );
		$this->assertContains( 'google', $provider_labels, 'Should have Google provider' );
		$this->assertContains( 'anthropic', $provider_labels, 'Should have Anthropic provider' );

		// Verify values are positive.
		foreach ( $data['values'] as $value ) {
			$this->assertGreaterThan( 0, $value, 'All provider values should be positive' );
		}

		// Verify total tokens.
		$total_tokens = array_sum( $data['values'] );
		$this->assertEquals( 16000, $total_tokens, 'Total tokens should match test data (5000 + 8000 + 3000)' );
	}

	/**
	 * Test get_provider_distribution_data for specific user.
	 */
	public function test_get_provider_distribution_data_specific_user() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_provider_distribution_data(
			array(
				'user_id' => $this->test_user_ids['user1'],
			)
		);

		// Verify data structure.
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'labels', $data );
		$this->assertArrayHasKey( 'values', $data );

		// User1 only has OpenAI usage.
		$this->assertEquals( 1, count( $data['labels'] ), 'User1 should have 1 provider' );
		$this->assertEquals( 'Openai', $data['labels'][0], 'User1 should use OpenAI' );
		$this->assertEquals( 5000, $data['values'][0], 'User1 should have 5000 tokens' );
	}

	/**
	 * Test get_model_distribution_data.
	 */
	public function test_get_model_distribution_data() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_model_distribution_data();

		// Verify data structure.
		$this->assertIsArray( $data, 'Should return an array' );
		$this->assertArrayHasKey( 'labels', $data, 'Should have labels' );
		$this->assertArrayHasKey( 'values', $data, 'Should have values' );
		$this->assertArrayHasKey( 'colors', $data, 'Should have colors' );
		$this->assertArrayHasKey( 'datasets', $data, 'Should have datasets' );

		// Verify labels and values have same count.
		$this->assertEquals(
			count( $data['labels'] ),
			count( $data['values'] ),
			'Labels and values should have same count'
		);

		// Verify we have the expected models from test data.
		$this->assertContains( 'google/gemini-pro', $data['labels'], 'Should have Google Gemini Pro' );
		$this->assertContains( 'openai/gpt-4', $data['labels'], 'Should have OpenAI GPT-4' );
		$this->assertContains( 'anthropic/claude-3-opus', $data['labels'], 'Should have Anthropic Claude 3 Opus' );

		// Verify models are sorted by usage (descending).
		$first_value = $data['values'][0];
		foreach ( $data['values'] as $value ) {
			$this->assertLessThanOrEqual( $first_value, $value, 'Values should be sorted descending' );
			$first_value = $value;
		}

		// Verify total tokens.
		$total_tokens = array_sum( $data['values'] );
		$this->assertEquals( 16000, $total_tokens, 'Total tokens should match test data' );
	}

	/**
	 * Test get_model_distribution_data with limit.
	 */
	public function test_get_model_distribution_data_with_limit() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_model_distribution_data(
			array(
				'limit' => 2,
			)
		);

		// Should return only top 2 models.
		$this->assertLessThanOrEqual( 2, count( $data['labels'] ), 'Should limit to 2 models' );
		$this->assertEquals( count( $data['labels'] ), count( $data['values'] ), 'Labels and values should match' );
	}

	/**
	 * Test get_model_distribution_data for specific user.
	 */
	public function test_get_model_distribution_data_specific_user() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_model_distribution_data(
			array(
				'user_id' => $this->test_user_ids['user2'],
			)
		);

		// User2 has 2 models.
		$this->assertEquals( 2, count( $data['labels'] ), 'User2 should have 2 models' );
		$this->assertContains( 'google/gemini-pro', $data['labels'], 'Should have Gemini Pro' );
		$this->assertContains( 'anthropic/claude-3-opus', $data['labels'], 'Should have Claude 3 Opus' );

		// Total should be 11000 (8000 + 3000).
		$total_tokens = array_sum( $data['values'] );
		$this->assertEquals( 11000, $total_tokens, 'User2 should have 11000 total tokens' );
	}

	/**
	 * Test that provider distribution returns empty arrays when no usage data.
	 */
	public function test_get_provider_distribution_data_no_usage() {
		// Create a new user with no usage data.
		$user_id = $this->factory->user->create();

		$data = WP_MCP_AI_Chart_JS_Helper::get_provider_distribution_data(
			array(
				'user_id' => $user_id,
			)
		);

		// Should return empty arrays.
		$this->assertIsArray( $data );
		$this->assertEmpty( $data['labels'], 'Should have empty labels for no usage' );
		$this->assertEmpty( $data['values'], 'Should have empty values for no usage' );

		// Clean up.
		wp_delete_user( $user_id );
	}

	/**
	 * Test that model distribution returns empty arrays when no usage data.
	 */
	public function test_get_model_distribution_data_no_usage() {
		// Create a new user with no usage data.
		$user_id = $this->factory->user->create();

		$data = WP_MCP_AI_Chart_JS_Helper::get_model_distribution_data(
			array(
				'user_id' => $user_id,
			)
		);

		// Should return empty arrays.
		$this->assertIsArray( $data );
		$this->assertEmpty( $data['labels'], 'Should have empty labels for no usage' );
		$this->assertEmpty( $data['values'], 'Should have empty values for no usage' );

		// Clean up.
		wp_delete_user( $user_id );
	}

	/**
	 * Test that usage trend data handles edge cases for "today".
	 */
	public function test_get_usage_trend_data_today_edge_cases() {
		// Test with invalid days parameter (should default).
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => 0 ) );
		$this->assertGreaterThan( 0, count( $data['labels'] ), 'Should handle invalid days parameter' );

		// Test with negative days parameter (should default).
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => -5 ) );
		$this->assertGreaterThan( 0, count( $data['labels'] ), 'Should handle negative days parameter' );

		// Test with days = 1 (today).
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => 1 ) );
		$this->assertEquals( 1, count( $data['labels'] ), 'Should return exactly 1 day for today' );
	}
}
