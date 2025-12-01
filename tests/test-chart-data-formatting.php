<?php
/**
 * Test Chart.js data formatting and helper methods
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Chart.js data formatting
 */
class Test_Chart_Data_Formatting extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected $test_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a test user.
		$this->test_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
	}

	/**
	 * Test usage trend data format.
	 */
	public function test_usage_trend_data_format() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data(
			array(
				'days' => 7,
			)
		);

		// Verify structure.
		$this->assertIsArray( $data, 'Usage trend data should be an array' );
		$this->assertArrayHasKey( 'labels', $data, 'Should have labels key' );
		$this->assertArrayHasKey( 'datasets', $data, 'Should have datasets key' );

		// Verify labels.
		$this->assertIsArray( $data['labels'], 'Labels should be an array' );
		$this->assertCount( 7, $data['labels'], 'Should have 7 labels for 7 days' );

		// Verify each label is a valid date.
		foreach ( $data['labels'] as $label ) {
			$this->assertMatchesRegularExpression(
				'/^\d{4}-\d{2}-\d{2}$/',
				$label,
				'Each label should be a date in Y-m-d format'
			);
		}

		// Verify datasets.
		$this->assertIsArray( $data['datasets'], 'Datasets should be an array' );
		$this->assertGreaterThan( 0, count( $data['datasets'] ), 'Should have at least one dataset' );

		// Verify first dataset structure.
		$first_dataset = $data['datasets'][0];
		$this->assertArrayHasKey( 'label', $first_dataset );
		$this->assertArrayHasKey( 'data', $first_dataset );
		$this->assertArrayHasKey( 'borderColor', $first_dataset );
		$this->assertArrayHasKey( 'backgroundColor', $first_dataset );

		// Verify data array.
		$this->assertIsArray( $first_dataset['data'], 'Dataset data should be an array' );
		$this->assertCount( 7, $first_dataset['data'], 'Dataset should have 7 data points' );

		// Verify all data points are numeric.
		foreach ( $first_dataset['data'] as $value ) {
			$this->assertIsNumeric( $value, 'Data points should be numeric' );
			$this->assertGreaterThanOrEqual( 0, $value, 'Data points should be non-negative' );
		}
	}

	/**
	 * Test tier distribution data format.
	 */
	public function test_tier_distribution_data_format() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_data();

		// Verify structure.
		$this->assertIsArray( $data, 'Tier distribution data should be an array' );
		$this->assertArrayHasKey( 'labels', $data, 'Should have labels key' );
		$this->assertArrayHasKey( 'values', $data, 'Should have values key' );

		// Verify labels and values are arrays.
		$this->assertIsArray( $data['labels'], 'Labels should be an array' );
		$this->assertIsArray( $data['values'], 'Values should be an array' );

		// Verify same length.
		$this->assertCount(
			count( $data['labels'] ),
			$data['values'],
			'Labels and values should have the same length'
		);

		// Verify expected tiers.
		$this->assertContains( 'Free', $data['labels'], 'Should include Free tier' );
		$this->assertContains( 'Pro', $data['labels'], 'Should include Pro tier' );
		$this->assertContains( 'Enterprise', $data['labels'], 'Should include Enterprise tier' );

		// Verify all values are non-negative integers.
		foreach ( $data['values'] as $value ) {
			$this->assertIsInt( $value, 'Values should be integers' );
			$this->assertGreaterThanOrEqual( 0, $value, 'Values should be non-negative' );
		}

		// Verify sum of values matches total users.
		$total_users = count( get_users() );
		$this->assertEquals(
			$total_users,
			array_sum( $data['values'] ),
			'Sum of tier values should equal total users'
		);
	}

	/**
	 * Test usage trend config format.
	 */
	public function test_usage_trend_config_format() {
		$config = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_config();

		// Verify structure.
		$this->assertIsArray( $config, 'Config should be an array' );
		$this->assertArrayHasKey( 'type', $config, 'Should have type key' );
		$this->assertArrayHasKey( 'options', $config, 'Should have options key' );

		// Verify type.
		$this->assertEquals( 'line', $config['type'], 'Type should be line' );

		// Verify options.
		$this->assertIsArray( $config['options'], 'Options should be an array' );
		$this->assertArrayHasKey( 'responsive', $config['options'] );
		$this->assertArrayHasKey( 'maintainAspectRatio', $config['options'] );
		$this->assertArrayHasKey( 'plugins', $config['options'] );
		$this->assertArrayHasKey( 'scales', $config['options'] );

		// Verify responsive settings.
		$this->assertTrue( $config['options']['responsive'], 'Should be responsive' );
		$this->assertFalse( $config['options']['maintainAspectRatio'], 'Should not maintain aspect ratio' );
	}

	/**
	 * Test tier distribution config format.
	 */
	public function test_tier_distribution_config_format() {
		$config = WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_config();

		// Verify structure.
		$this->assertIsArray( $config, 'Config should be an array' );
		$this->assertArrayHasKey( 'type', $config, 'Should have type key' );
		$this->assertArrayHasKey( 'options', $config, 'Should have options key' );

		// Verify type.
		$this->assertEquals( 'pie', $config['type'], 'Type should be pie' );

		// Verify options.
		$this->assertIsArray( $config['options'], 'Options should be an array' );
		$this->assertArrayHasKey( 'responsive', $config['options'] );
		$this->assertArrayHasKey( 'plugins', $config['options'] );

		// Verify legend position.
		$this->assertArrayHasKey( 'legend', $config['options']['plugins'] );
		$this->assertEquals(
			'right',
			$config['options']['plugins']['legend']['position'],
			'Legend should be on the right'
		);
	}

	/**
	 * Test usage trend data with different day ranges.
	 */
	public function test_usage_trend_data_different_ranges() {
		$ranges = array( 7, 14, 30, 90 );

		foreach ( $ranges as $days ) {
			$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data(
				array(
					'days' => $days,
				)
			);

			$this->assertCount(
				$days,
				$data['labels'],
				"Should have {$days} labels for {$days} days"
			);

			$this->assertCount(
				$days,
				$data['datasets'][0]['data'],
				"Should have {$days} data points for {$days} days"
			);
		}
	}

	/**
	 * Test usage trend data date ordering.
	 */
	public function test_usage_trend_data_date_ordering() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data(
			array(
				'days' => 7,
			)
		);

		$labels = $data['labels'];

		// Verify dates are in chronological order.
		for ( $i = 0; $i < count( $labels ) - 1; $i++ ) {
			$current = strtotime( $labels[ $i ] );
			$next    = strtotime( $labels[ $i + 1 ] );

			$this->assertLessThan(
				$next,
				$current,
				'Dates should be in chronological order (oldest to newest)'
			);
		}

		// Verify last date is today or recent.
		$last_date = end( $labels );
		$today     = gmdate( 'Y-m-d' );
		$diff      = strtotime( $today ) - strtotime( $last_date );

		$this->assertLessThanOrEqual(
			86400, // 1 day in seconds
			$diff,
			'Last date should be today or yesterday'
		);
	}

	/**
	 * Test tier distribution data with custom tier assignments.
	 */
	public function test_tier_distribution_with_custom_tiers() {
		// Create users with different tiers.
		$free_user = $this->factory->user->create();
		update_user_meta( $free_user, 'wp_mcp_ai_token_tier', 'free' );

		$pro_user = $this->factory->user->create();
		update_user_meta( $pro_user, 'wp_mcp_ai_token_tier', 'pro' );

		$enterprise_user = $this->factory->user->create();
		update_user_meta( $enterprise_user, 'wp_mcp_ai_token_tier', 'enterprise' );

		// Get distribution.
		$data = WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_data();

		// Find indexes.
		$free_index       = array_search( 'Free', $data['labels'], true );
		$pro_index        = array_search( 'Pro', $data['labels'], true );
		$enterprise_index = array_search( 'Enterprise', $data['labels'], true );

		// Verify counts include our test users.
		$this->assertGreaterThanOrEqual( 1, $data['values'][ $free_index ], 'Should have at least 1 free user' );
		$this->assertGreaterThanOrEqual( 1, $data['values'][ $pro_index ], 'Should have at least 1 pro user' );
		$this->assertGreaterThanOrEqual( 1, $data['values'][ $enterprise_index ], 'Should have at least 1 enterprise user' );

		// Clean up.
		wp_delete_user( $free_user );
		wp_delete_user( $pro_user );
		wp_delete_user( $enterprise_user );
	}

	/**
	 * Test usage trend data handles no usage gracefully.
	 */
	public function test_usage_trend_data_handles_no_usage() {
		$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data(
			array(
				'days' => 7,
			)
		);

		// Should still return valid structure even with no usage.
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'labels', $data );
		$this->assertArrayHasKey( 'datasets', $data );

		// All data points should be 0 or valid numbers.
		foreach ( $data['datasets'][0]['data'] as $value ) {
			$this->assertIsNumeric( $value );
			$this->assertGreaterThanOrEqual( 0, $value );
		}
	}
}
