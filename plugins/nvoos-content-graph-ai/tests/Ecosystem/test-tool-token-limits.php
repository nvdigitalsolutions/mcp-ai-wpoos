<?php
/**
 * Tool token limits port tests (Wave D3f).
 *
 * Characterization suite for `ToolTokenLimits`. Assertions mirror the
 * base plugin's tool-token-limits tests: constants, tier resolution
 * (guest / role / custom / expired), tier info, per-tool limits with
 * multipliers, multiplier validation, model preferences, usage recording
 * and accumulation, daily/hourly reads, resets, statistics, expired-data
 * cleanup, peak-hour detection, forecasting, alert throttling, anomaly
 * detection, per-call and per-session accounting, bulk tier assignment,
 * tiered-limits migration, CSV export, cache preload/invalidation, and
 * budget truncation (via a subclass pinning the workload-tier seam).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Analytics\ToolTokenLimits;

/**
 * Testable subclass exposing protected members and pinning the
 * workload-tier / hours-until-reset seams.
 */
class Testable_Tool_Token_Limits extends ToolTokenLimits {

	/**
	 * Pin the workload tier for deterministic budget tests.
	 *
	 * @return string
	 */
	protected static function get_workload_tier() {
		return 'low';
	}

	/**
	 * Pin hours-until-reset for deterministic forecast tests.
	 *
	 * @return float
	 */
	protected static function get_hours_until_daily_reset() {
		return 10.0;
	}

	/**
	 * Expose estimate_tokens().
	 *
	 * @param mixed $result Tool result.
	 * @return int
	 */
	public static function expose_estimate_tokens( $result ) {
		return self::estimate_tokens( $result );
	}

	/**
	 * Expose truncate_result().
	 *
	 * @param mixed $result     Tool result.
	 * @param int   $max_tokens Maximum tokens allowed.
	 * @return mixed
	 */
	public static function expose_truncate_result( $result, $max_tokens ) {
		return self::truncate_result( $result, $max_tokens );
	}

	/**
	 * Expose get_max_tool_result_tokens().
	 *
	 * @param string $tier      Workload tier.
	 * @param string $tool_slug Tool identifier.
	 * @return int
	 */
	public static function expose_max_tool_result_tokens( $tier, $tool_slug ) {
		return self::get_max_tool_result_tokens( $tier, $tool_slug );
	}

	/**
	 * Expose get_tool_multiplier().
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return float
	 */
	public static function expose_multiplier( $tool_slug ) {
		return self::get_tool_multiplier( $tool_slug );
	}
}

/**
 * @group analytics
 */
class Test_Tool_Token_Limits extends \WP_UnitTestCase {

	/**
	 * Test user ID for testing.
	 *
	 * @var int
	 */
	protected $test_user_id;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// In monolith runs the base plugin's ToolTokenLimits hooks the
		// same actions and writes to the same meta key — detach it so this
		// suite's recordings stay deterministic.
		\remove_all_actions( 'wp_mcp_ai_after_tool_execution' );
		\remove_all_actions( 'wp_mcp_ai_before_tool_execution' );
		\remove_all_actions( 'wp_mcp_ai_user_tier_changed' );

		$this->test_user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);

		\wp_cache_flush();

		\delete_user_meta( $this->test_user_id, ToolTokenLimits::USAGE_META_KEY );
		\delete_user_meta( $this->test_user_id, ToolTokenLimits::TIER_META_KEY );
		\delete_user_meta( $this->test_user_id, '_wp_mcp_ai_token_tier_expires' );

		\delete_option( ToolTokenLimits::LIMITS_OPTION );
		\delete_option( ToolTokenLimits::MODEL_PREFERENCES_OPTION );
		\delete_option( 'wp_mcp_ai_tool_multipliers' );
		\delete_option( 'wp_mcp_ai_tiered_limits_migrated' );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );
	}

	/**
	 * Clean up after test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_user_tool_limit' );
		\remove_all_filters( 'wp_mcp_ai_enforce_tool_token_limits' );
		\remove_all_filters( 'wp_mcp_ai_enforce_per_session_limits' );
		\remove_all_filters( 'wp_mcp_ai_session_limit_safety_buffer' );
		\remove_all_filters( 'wp_mcp_ai_tool_limit_multiplier' );
		\remove_all_filters( 'wp_mcp_ai_all_tool_multipliers' );
		\remove_all_filters( 'wp_mcp_ai_all_tool_model_preferences' );
		\remove_all_filters( 'wp_mcp_ai_available_tool_models' );
		\remove_all_actions( 'wp_mcp_ai_tool_token_limit_exceeded' );
		\remove_all_actions( 'wp_mcp_ai_per_call_limit_exceeded' );
		\remove_all_actions( 'wp_mcp_ai_per_session_limit_exceeded' );
		\remove_all_actions( 'wp_mcp_ai_session_limit_approaching' );
		\remove_all_actions( 'wp_mcp_ai_usage_anomaly_detected' );
		\remove_all_actions( 'wp_mcp_ai_tool_token_usage_recorded' );
		\remove_all_actions( 'wp_mcp_ai_limit_alert_sent' );
		\remove_all_actions( 'wp_mcp_ai_user_tier_changed' );

		if ( $this->test_user_id ) {
			\delete_user_meta( $this->test_user_id, ToolTokenLimits::USAGE_META_KEY );
			\delete_user_meta( $this->test_user_id, ToolTokenLimits::TIER_META_KEY );
			\delete_user_meta( $this->test_user_id, '_wp_mcp_ai_token_tier_expires' );
		}

		\delete_option( ToolTokenLimits::LIMITS_OPTION );
		\delete_option( ToolTokenLimits::MODEL_PREFERENCES_OPTION );
		\delete_option( 'wp_mcp_ai_tool_multipliers' );
		\delete_option( 'wp_mcp_ai_tiered_limits_migrated' );
		\delete_option( 'wp_mcp_ai_settings' );
		\delete_option( 'nvoos_content_graph_settings' );

		\wp_set_current_user( 0 );
		\wp_cache_flush();

		parent::tearDown();
	}

	/**
	 * Test the constants keep their base values.
	 */
	public function test_constants_match_base(): void {
		$this->assertSame( 'wp_mcp_ai_tool_token_limits', ToolTokenLimits::LIMITS_OPTION );
		$this->assertSame( 'wp_mcp_ai_tool_model_preferences', ToolTokenLimits::MODEL_PREFERENCES_OPTION );
		$this->assertSame( '_wp_mcp_ai_tool_token_usage', ToolTokenLimits::USAGE_META_KEY );
		$this->assertSame( '_wp_mcp_ai_token_tier', ToolTokenLimits::TIER_META_KEY );
		$this->assertSame( 100000, ToolTokenLimits::DEFAULT_GENERAL_LIMIT );
		$this->assertSame( 200000, ToolTokenLimits::DEFAULT_CRAWL4AI_LIMIT );
		$this->assertSame( 'free', ToolTokenLimits::TIER_FREE );
		$this->assertSame( 'pro', ToolTokenLimits::TIER_PRO );
		$this->assertSame( 'enterprise', ToolTokenLimits::TIER_ENTERPRISE );
	}

	/**
	 * Test guest tier resolution.
	 */
	public function test_get_user_tier_guest_is_free(): void {
		$this->assertSame( 'free', ToolTokenLimits::get_user_tier( 0 ) );
	}

	/**
	 * Test role-based tier resolution with caching.
	 */
	public function test_get_user_tier_by_role(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertSame( 'enterprise', ToolTokenLimits::get_user_tier( $this->test_user_id ) );
		$this->assertSame( 'pro', ToolTokenLimits::get_user_tier( $editor_id ) );

		// Second call should hit the object cache and return the same tier.
		$this->assertSame( 'enterprise', ToolTokenLimits::get_user_tier( $this->test_user_id ) );
	}

	/**
	 * Test custom tier with and without expiry.
	 */
	public function test_get_user_tier_custom_and_expiry(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Permanent custom tier.
		$this->assertTrue( ToolTokenLimits::set_user_tier( $subscriber_id, 'pro', 0 ) );
		$this->assertSame( 'pro', ToolTokenLimits::get_user_tier( $subscriber_id ) );

		// Future expiry keeps the custom tier.
		$this->assertTrue( ToolTokenLimits::set_user_tier( $subscriber_id, 'enterprise', time() + HOUR_IN_SECONDS ) );
		$this->assertSame( 'enterprise', ToolTokenLimits::get_user_tier( $subscriber_id ) );

		// Expired tier falls back to role-based detection.
		$this->assertTrue( ToolTokenLimits::set_user_tier( $subscriber_id, 'pro', time() - 100 ) );
		$this->assertSame( 'free', ToolTokenLimits::get_user_tier( $subscriber_id ) );
		$this->assertEmpty( \get_user_meta( $subscriber_id, ToolTokenLimits::TIER_META_KEY, true ) );
	}

	/**
	 * Test tier info for known and unknown tiers.
	 */
	public function test_get_tier_info(): void {
		$pro = ToolTokenLimits::get_tier_info( 'pro' );
		$this->assertSame( 'pro', $pro['tier'] );
		$this->assertSame( 200000, $pro['daily_limit'] );

		$unknown = ToolTokenLimits::get_tier_info( 'bogus-tier' );
		$this->assertSame( ToolTokenLimits::DEFAULT_GENERAL_LIMIT, $unknown['daily_limit'] );
	}

	/**
	 * Test per-user/per-tool limits apply multipliers.
	 */
	public function test_get_user_tool_limit_applies_multiplier(): void {
		// Administrator → enterprise (1M) × 2.0 for web_search.
		$this->assertSame( 2000000, ToolTokenLimits::get_user_tool_limit( $this->test_user_id, 'web_search' ) );

		// Unknown tools use the default 1.0 multiplier.
		$this->assertSame( 1000000, ToolTokenLimits::get_user_tool_limit( $this->test_user_id, 'unknown_tool_x' ) );
	}

	/**
	 * Test multiplier validation and custom multipliers.
	 */
	public function test_tool_multiplier_validation(): void {
		$this->assertFalse( ToolTokenLimits::set_tool_multiplier( 'my_tool', 0.05 ) );
		$this->assertFalse( ToolTokenLimits::set_tool_multiplier( 'my_tool', 11 ) );
		$this->assertFalse( ToolTokenLimits::set_tool_multiplier( '', 2 ) );

		$this->assertTrue( ToolTokenLimits::set_tool_multiplier( 'my_tool', 2.5 ) );
		$this->assertSame( 2.5, Testable_Tool_Token_Limits::expose_multiplier( 'my_tool' ) );
		$this->assertSame( 2500000, ToolTokenLimits::get_user_tool_limit( $this->test_user_id, 'my_tool' ) );

		$multipliers = ToolTokenLimits::get_tool_multipliers();
		$this->assertSame( 2.5, $multipliers['my_tool'] );
		$this->assertSame( 2.0, $multipliers['web_search'] );
	}

	/**
	 * Test model preference round-trip.
	 */
	public function test_model_preferences_roundtrip(): void {
		$this->assertSame( 'default', ToolTokenLimits::get_tool_model_preference( 'some_tool' ) );

		$this->assertTrue( ToolTokenLimits::set_tool_model_preference( 'some_tool', 'gpt-4o' ) );
		$this->assertSame( 'gpt-4o', ToolTokenLimits::get_tool_model_preference( 'some_tool' ) );

		$this->assertFalse( ToolTokenLimits::set_tool_model_preference( '', 'gpt-4o' ) );

		$preferences = ToolTokenLimits::get_tool_model_preferences();
		$this->assertSame( 'gpt-4o', $preferences['some_tool'] );
	}

	/**
	 * Test set_user_tier validation and the tier-changed hook.
	 */
	public function test_set_user_tier_validation_and_hook(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( ToolTokenLimits::set_user_tier( $subscriber_id, 'platinum' ) );
		$this->assertFalse( ToolTokenLimits::set_user_tier( 0, 'pro' ) );

		$fired = array();
		\add_action(
			'wp_mcp_ai_user_tier_changed',
			static function ( $user_id, $old_tier, $new_tier, $expires ) use ( &$fired ): void {
				$fired = array(
					'user_id'  => $user_id,
					'old_tier' => $old_tier,
					'new_tier' => $new_tier,
					'expires'  => $expires,
				);
			},
			10,
			4
		);

		$this->assertTrue( ToolTokenLimits::set_user_tier( $subscriber_id, 'pro', 12345 ) );

		$this->assertSame( $subscriber_id, $fired['user_id'] );
		$this->assertSame( 'free', $fired['old_tier'] );
		$this->assertSame( 'pro', $fired['new_tier'] );
		$this->assertSame( 12345, $fired['expires'] );
	}

	/**
	 * Test default and custom tool limits.
	 */
	public function test_get_and_set_tool_limit(): void {
		$this->assertSame( ToolTokenLimits::DEFAULT_CRAWL4AI_LIMIT, ToolTokenLimits::get_tool_limit( 'run_crawl4ai_job' ) );
		$this->assertSame( ToolTokenLimits::DEFAULT_GENERAL_LIMIT, ToolTokenLimits::get_tool_limit( 'some_other_tool' ) );

		$this->assertTrue( ToolTokenLimits::set_tool_limit( 'test_tool', 50000 ) );
		$this->assertSame( 50000, ToolTokenLimits::get_tool_limit( 'test_tool' ) );
		$this->assertFalse( ToolTokenLimits::set_tool_limit( '', 100 ) );
	}

	/**
	 * Test recording tool usage writes the full usage shape.
	 */
	public function test_record_tool_usage_shape(): void {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );
		$result    = str_repeat( 'a', 40 ); // ~10 tokens.

		ToolTokenLimits::record_tool_usage( $tool_slug, array( 'test' => 'data' ), $context, $result );

		$usage = ToolTokenLimits::get_user_tool_usage( $this->test_user_id );

		$this->assertArrayHasKey( $tool_slug, $usage );
		$this->assertSame( 10, $usage[ $tool_slug ]['total_tokens'] );
		$this->assertSame( 1, $usage[ $tool_slug ]['requests'] );
		$this->assertNotEmpty( $usage[ $tool_slug ]['first_used'] );
		$this->assertNotEmpty( $usage[ $tool_slug ]['last_used'] );

		$today = gmdate( 'Y-m-d', time() );
		$hour  = gmdate( 'Y-m-d-H', time() );
		$this->assertSame( 10, $usage[ $tool_slug ]['daily'][ $today ] );
		$this->assertSame( 10, $usage[ $tool_slug ]['hourly'][ $hour ] );

		// The recorded action fires with the right payload.
		$recorded = array();
		\add_action(
			'wp_mcp_ai_tool_token_usage_recorded',
			static function ( $user_id, $slug, $tokens, $ctx ) use ( &$recorded ): void {
				$recorded = array( $user_id, $slug, $tokens, $ctx );
			},
			10,
			4
		);

		ToolTokenLimits::record_tool_usage( $tool_slug, array(), $context, $result );
		$this->assertSame( $this->test_user_id, $recorded[0] );
		$this->assertSame( $tool_slug, $recorded[1] );
		$this->assertSame( 10, $recorded[2] );
	}

	/**
	 * Test usage accumulation across calls.
	 */
	public function test_record_tool_usage_accumulates(): void {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );
		$result    = 'Test result'; // 11 chars → 2 tokens.

		ToolTokenLimits::record_tool_usage( $tool_slug, array(), $context, $result );
		ToolTokenLimits::record_tool_usage( $tool_slug, array(), $context, $result );
		ToolTokenLimits::record_tool_usage( $tool_slug, array(), $context, $result );

		$usage = ToolTokenLimits::get_user_tool_usage( $this->test_user_id );
		$this->assertSame( 3, $usage[ $tool_slug ]['requests'] );
		$this->assertSame( 6, $usage[ $tool_slug ]['total_tokens'] );
		$this->assertSame( 6, ToolTokenLimits::get_user_tool_daily_usage( $this->test_user_id, $tool_slug ) );
		$this->assertSame( 6, ToolTokenLimits::get_user_tool_hourly_usage( $this->test_user_id, $tool_slug ) );
	}

	/**
	 * Test empty usage reads for guests and unknown tools.
	 */
	public function test_empty_usage_reads(): void {
		$this->assertSame( array(), ToolTokenLimits::get_user_tool_usage( 0 ) );
		$this->assertSame( 0, ToolTokenLimits::get_user_tool_daily_usage( $this->test_user_id, 'never_used' ) );
		$this->assertSame( 0, ToolTokenLimits::get_user_tool_hourly_usage( $this->test_user_id, 'never_used' ) );
	}

	/**
	 * Test resetting usage for a single tool and for all tools.
	 */
	public function test_reset_user_tool_usage(): void {
		$context = array( 'user_id' => $this->test_user_id );

		ToolTokenLimits::record_tool_usage( 'tool_one', array(), $context, 'Test result' );
		ToolTokenLimits::record_tool_usage( 'tool_two', array(), $context, 'Test result' );

		$this->assertTrue( ToolTokenLimits::reset_user_tool_usage( $this->test_user_id, 'tool_one' ) );

		$usage = ToolTokenLimits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayNotHasKey( 'tool_one', $usage );
		$this->assertArrayHasKey( 'tool_two', $usage );

		$this->assertTrue( ToolTokenLimits::reset_user_tool_usage( $this->test_user_id ) );
		$this->assertSame( array(), ToolTokenLimits::get_user_tool_usage( $this->test_user_id ) );

		$this->assertFalse( ToolTokenLimits::reset_user_tool_usage( 0 ) );
	}

	/**
	 * Test peak usage hour detection.
	 */
	public function test_get_peak_usage_hour(): void {
		$tool_slug = 'test_tool';
		$older     = gmdate( 'Y-m-d-H', strtotime( '-2 days', time() ) );
		$recent    = gmdate( 'Y-m-d-H', strtotime( '-1 day', time() ) );

		\update_user_meta(
			$this->test_user_id,
			ToolTokenLimits::USAGE_META_KEY,
			array(
				$tool_slug => array(
					'hourly' => array(
						$older  => 100,
						$recent => 400,
					),
				),
			)
		);

		$peak = ToolTokenLimits::get_peak_usage_hour( $this->test_user_id, $tool_slug );
		$this->assertSame( $recent, $peak['hour'] );
		$this->assertSame( 400, $peak['tokens'] );

		$this->assertNull( ToolTokenLimits::get_peak_usage_hour( $this->test_user_id, 'never_used' ) );
	}

	/**
	 * Test forecast returns null with insufficient data.
	 */
	public function test_forecast_insufficient_data(): void {
		$context = array( 'user_id' => $this->test_user_id );
		ToolTokenLimits::record_tool_usage( 'test_tool', array(), $context, 'Test result' );

		$this->assertNull( ToolTokenLimits::forecast_limit_exhaustion( $this->test_user_id, 'test_tool' ) );
	}

	/**
	 * Test forecast shape with 24 hourly points.
	 */
	public function test_forecast_with_data(): void {
		$tool_slug = 'test_tool';
		$hourly    = array();
		for ( $i = 23; $i >= 0; $i-- ) {
			$key          = gmdate( 'Y-m-d-H', strtotime( "-{$i} hours", time() ) );
			$hourly[ $key ] = 100;
		}

		\update_user_meta(
			$this->test_user_id,
			ToolTokenLimits::USAGE_META_KEY,
			array(
				$tool_slug => array( 'hourly' => $hourly ),
			)
		);

		$forecast = ToolTokenLimits::forecast_limit_exhaustion( $this->test_user_id, $tool_slug );

		$this->assertIsArray( $forecast );
		$this->assertFalse( $forecast['will_exceed'] );
		$this->assertSame( 100, $forecast['avg_hourly_usage'] );
		$this->assertSame( 1000000, $forecast['limit'] );
		$this->assertSame( 30, $forecast['confidence'] );
		$this->assertArrayHasKey( 'remaining_tokens', $forecast );
		$this->assertArrayHasKey( 'hours_until_reset', $forecast );

		// Low confidence → no alert.
		$this->assertFalse( ToolTokenLimits::should_send_limit_alert( $this->test_user_id, $tool_slug ) );
	}

	/**
	 * Test limit alert throttling with a confident over-budget forecast.
	 */
	public function test_should_send_limit_alert_throttles(): void {
		$tool_slug = 'bulk_tool';
		$hourly    = array();
		for ( $i = 119; $i >= 0; $i-- ) {
			$key          = gmdate( 'Y-m-d-H', strtotime( "-{$i} hours", time() ) );
			$hourly[ $key ] = 1000000;
		}

		\update_user_meta(
			$this->test_user_id,
			ToolTokenLimits::USAGE_META_KEY,
			array(
				$tool_slug => array( 'hourly' => $hourly ),
			)
		);

		\add_filter( 'wp_mcp_ai_user_tool_limit', static function () {
			return 100000;
		} );

		// Testable subclass pins hours-until-reset to 10h → projected
		// usage far exceeds the filtered limit; 120 points → 70% confidence.
		$this->assertTrue( Testable_Tool_Token_Limits::should_send_limit_alert( $this->test_user_id, $tool_slug ) );
		$this->assertFalse( Testable_Tool_Token_Limits::should_send_limit_alert( $this->test_user_id, $tool_slug ) );

		\delete_transient( "wp_mcp_ai_limit_alert_{$this->test_user_id}_{$tool_slug}" );
	}

	/**
	 * Test anomaly detection thresholds.
	 */
	public function test_detect_usage_anomaly(): void {
		$tool_slug = 'test_tool';

		\update_user_meta(
			$this->test_user_id,
			ToolTokenLimits::USAGE_META_KEY,
			array(
				$tool_slug => array(
					'hourly' => array(
						gmdate( 'Y-m-d-H', strtotime( '-2 hours', time() ) ) => 100,
						gmdate( 'Y-m-d-H', strtotime( '-1 hour', time() ) )  => 100,
					),
				),
			)
		);

		// Average is 100 → threshold 500.
		$this->assertTrue( ToolTokenLimits::detect_usage_anomaly( $this->test_user_id, $tool_slug, 501 ) );
		$this->assertFalse( ToolTokenLimits::detect_usage_anomaly( $this->test_user_id, $tool_slug, 400 ) );
		$this->assertFalse( ToolTokenLimits::detect_usage_anomaly( $this->test_user_id, 'never_used', 1000 ) );
		$this->assertFalse( ToolTokenLimits::detect_usage_anomaly( 0, $tool_slug, 100 ) );
	}

	/**
	 * Test check_tool_limit fires the exceeded event without enforcement.
	 */
	public function test_check_tool_limit_fires_event(): void {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );

		\add_filter( 'wp_mcp_ai_user_tool_limit', static function () {
			return 1000;
		} );
		\add_filter( 'wp_mcp_ai_enforce_tool_token_limits', '__return_false' );

		ToolTokenLimits::record_tool_usage( $tool_slug, array(), $context, str_repeat( 'a', 10000 ) ); // ~2,500 tokens.

		$this->assertGreaterThan( 1000, ToolTokenLimits::get_user_tool_daily_usage( $this->test_user_id, $tool_slug ) );

		$event_fired = false;
		\add_action(
			'wp_mcp_ai_tool_token_limit_exceeded',
			static function () use ( &$event_fired ): void {
				$event_fired = true;
			}
		);

		ToolTokenLimits::check_tool_limit( $tool_slug, array(), $context );

		$this->assertTrue( $event_fired, 'Tool token limit exceeded event should fire' );
	}

	/**
	 * Test check_tool_limit throws when enforcement is on.
	 */
	public function test_check_tool_limit_enforces(): void {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );

		\add_filter( 'wp_mcp_ai_user_tool_limit', static function () {
			return 1000;
		} );

		ToolTokenLimits::record_tool_usage( $tool_slug, array(), $context, str_repeat( 'a', 10000 ) );

		$this->expectException( \Exception::class );
		ToolTokenLimits::check_tool_limit( $tool_slug, array(), $context );
	}

	/**
	 * Test per-call limit accounting fires the exceeded event.
	 */
	public function test_per_call_limit_after_fires_event(): void {
		$settings_option = defined( 'WP_MCP_AI_PATH' ) ? 'wp_mcp_ai_settings' : 'nvoos_content_graph_settings';
		$current         = \get_option( $settings_option, array() );
		\update_option(
			$settings_option,
			array_merge(
				is_array( $current ) ? $current : array(),
				array(
					'enable_per_call_limits' => true,
					'per_call_token_limit'   => 100,
				)
			)
		);

		$event_fired = false;
		\add_action(
			'wp_mcp_ai_per_call_limit_exceeded',
			static function () use ( &$event_fired ): void {
				$event_fired = true;
			}
		);

		ToolTokenLimits::record_tool_usage(
			'test_tool',
			array(),
			array( 'user_id' => $this->test_user_id ),
			str_repeat( 'a', 1000 ) // 250 tokens > 100 limit.
		);

		$this->assertTrue( $event_fired );
	}

	/**
	 * Test per-session usage accounting and over-budget marking.
	 */
	public function test_session_usage_accounting(): void {
		$settings_option = defined( 'WP_MCP_AI_PATH' ) ? 'wp_mcp_ai_settings' : 'nvoos_content_graph_settings';
		$current         = \get_option( $settings_option, array() );
		\update_option(
			$settings_option,
			array_merge(
				is_array( $current ) ? $current : array(),
				array(
					'enable_per_session_limits' => true,
					'per_session_token_limit'   => 1000,
				)
			)
		);

		$context = array(
			'user_id'    => $this->test_user_id,
			'session_id' => 'sessA',
		);

		// 4,000 chars → 1,000 tokens (at the limit, not over).
		ToolTokenLimits::record_tool_usage( 'test_tool', array(), $context, str_repeat( 'a', 4000 ) );

		$this->assertSame( 1000, ToolTokenLimits::get_session_usage( $this->test_user_id, 'sessA' ) );

		$session = ToolTokenLimits::get_session_data( $this->test_user_id, 'sessA' );
		$this->assertFalse( $session['over_budget'] );
		$this->assertSame( 1, $session['tool_calls']['test_tool']['count'] );

		// Another 100 tokens → 1,100 > 1,000 → marked over budget.
		ToolTokenLimits::record_tool_usage( 'test_tool', array(), $context, str_repeat( 'a', 400 ) );
		$session = ToolTokenLimits::get_session_data( $this->test_user_id, 'sessA' );
		$this->assertTrue( $session['over_budget'] );
		$this->assertSame( 1100, $session['total_tokens'] );

		// Reset clears the session.
		$this->assertTrue( ToolTokenLimits::reset_session_usage( $this->test_user_id, 'sessA' ) );
		$this->assertSame( 0, ToolTokenLimits::get_session_usage( $this->test_user_id, 'sessA' ) );
		$this->assertNull( ToolTokenLimits::get_session_data( $this->test_user_id, 'sessA' ) );
	}

	/**
	 * Test tool statistics across users.
	 */
	public function test_get_tool_statistics(): void {
		$tool_slug = 'test_tool';
		$result    = str_repeat( 'a', 400 ); // ~100 tokens.

		$second_user = self::factory()->user->create( array( 'role' => 'editor' ) );

		ToolTokenLimits::record_tool_usage( $tool_slug, array(), array( 'user_id' => $this->test_user_id ), $result );
		ToolTokenLimits::record_tool_usage( $tool_slug, array(), array( 'user_id' => $second_user ), $result );

		$stats = ToolTokenLimits::get_tool_statistics( $tool_slug );

		$this->assertSame( $tool_slug, $stats['tool_slug'] );
		$this->assertSame( 2, $stats['total_users'] );
		$this->assertSame( 2, $stats['total_requests'] );
		$this->assertSame( 200, $stats['total_tokens'] );
		$this->assertGreaterThan( 0, $stats['limit'] );

		\delete_user_meta( $second_user, ToolTokenLimits::USAGE_META_KEY );
	}

	/**
	 * Test expired daily entries are cleaned up.
	 */
	public function test_cleanup_expired_usage(): void {
		$tool_slug = 'test_tool';
		$context   = array( 'user_id' => $this->test_user_id );

		ToolTokenLimits::record_tool_usage( $tool_slug, array(), $context, 'Test result' );

		$usage                                     = ToolTokenLimits::get_user_tool_usage( $this->test_user_id );
		$old_date                                  = gmdate( 'Y-m-d', strtotime( '-35 days', time() ) );
		$today                                     = gmdate( 'Y-m-d', time() );
		$usage[ $tool_slug ]['daily'][ $old_date ] = 100;
		\update_user_meta( $this->test_user_id, ToolTokenLimits::USAGE_META_KEY, $usage );

		ToolTokenLimits::cleanup_expired_usage();

		$usage_after = ToolTokenLimits::get_user_tool_usage( $this->test_user_id );
		$this->assertArrayNotHasKey( $old_date, $usage_after[ $tool_slug ]['daily'] );
		$this->assertArrayHasKey( $today, $usage_after[ $tool_slug ]['daily'] );
	}

	/**
	 * Test bulk tier assignment permission gate and success path.
	 */
	public function test_bulk_set_user_tiers(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// No capability → permission error.
		$denied = ToolTokenLimits::bulk_set_user_tiers( array( $subscriber_id ), 'pro' );
		$this->assertNotEmpty( $denied['errors'] );
		$this->assertSame( 0, $denied['success'] );

		// Admin can bulk-assign.
		\wp_set_current_user( $this->test_user_id );

		$results = ToolTokenLimits::bulk_set_user_tiers( array( $subscriber_id, 999999 ), 'pro' );
		$this->assertSame( 1, $results['success'] );
		$this->assertSame( 1, $results['failed'] );
		$this->assertSame( 'pro', ToolTokenLimits::get_user_tier( $subscriber_id ) );

		// Invalid tier is rejected.
		$invalid = ToolTokenLimits::bulk_set_user_tiers( array( $subscriber_id ), 'bogus' );
		$this->assertNotEmpty( $invalid['errors'] );
	}

	/**
	 * Test tiered-limits migration (one-shot, role-based).
	 */
	public function test_migrate_to_tiered_limits(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Give both users usage data.
		\update_user_meta( $subscriber_id, ToolTokenLimits::USAGE_META_KEY, array( 't' => array() ) );
		\update_user_meta( $editor_id, ToolTokenLimits::USAGE_META_KEY, array( 't' => array() ) );

		\wp_set_current_user( $this->test_user_id );

		$result = ToolTokenLimits::migrate_to_tiered_limits();
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 'free', \get_user_meta( $subscriber_id, ToolTokenLimits::TIER_META_KEY, true ) );
		$this->assertSame( 'pro', \get_user_meta( $editor_id, ToolTokenLimits::TIER_META_KEY, true ) );

		// One-shot: second run errors.
		$again = ToolTokenLimits::migrate_to_tiered_limits();
		$this->assertWPError( $again );
	}

	/**
	 * Test usage report CSV export.
	 */
	public function test_export_usage_report(): void {
		// No capability → empty string.
		$this->assertSame( '', ToolTokenLimits::export_usage_report() );

		ToolTokenLimits::record_tool_usage(
			'test_tool',
			array(),
			array( 'user_id' => $this->test_user_id ),
			str_repeat( 'a', 400 )
		);

		\wp_set_current_user( $this->test_user_id );

		$csv = ToolTokenLimits::export_usage_report();

		$this->assertStringContainsString( 'User ID', $csv );
		$this->assertStringContainsString( (string) $this->test_user_id, $csv );
		$this->assertStringContainsString( 'enterprise', $csv );
	}

	/**
	 * Test tier cache preload and invalidation.
	 */
	public function test_preload_and_cached_tier(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertSame( 2, ToolTokenLimits::preload_user_tiers( array( $this->test_user_id, $editor_id ) ) );
		$this->assertSame( 'enterprise', ToolTokenLimits::get_user_tier_cached( $this->test_user_id ) );
		$this->assertSame( 'pro', ToolTokenLimits::get_user_tier_cached( $editor_id ) );

		ToolTokenLimits::set_user_tier( $editor_id, 'enterprise' );
		ToolTokenLimits::invalidate_tier_cache( $editor_id );
		$this->assertSame( 'enterprise', ToolTokenLimits::get_user_tier_cached( $editor_id ) );

		$this->assertSame( 0, ToolTokenLimits::preload_user_tiers( array() ) );
	}

	/**
	 * Test token estimation for different data types.
	 */
	public function test_estimate_tokens(): void {
		$this->assertSame( 10, Testable_Tool_Token_Limits::expose_estimate_tokens( str_repeat( 'a', 40 ) ) );
		$this->assertGreaterThan( 0, Testable_Tool_Token_Limits::expose_estimate_tokens( array( 'k' => 'v' ) ) );
		$this->assertSame( 1, Testable_Tool_Token_Limits::expose_estimate_tokens( 42 ) );
	}

	/**
	 * Test workload-tier result-token budgets.
	 */
	public function test_max_tool_result_tokens(): void {
		$this->assertSame( 500, Testable_Tool_Token_Limits::expose_max_tool_result_tokens( 'low', 'some_tool' ) );
		$this->assertSame( 2000, Testable_Tool_Token_Limits::expose_max_tool_result_tokens( 'medium', 'some_tool' ) );
		$this->assertSame( 8000, Testable_Tool_Token_Limits::expose_max_tool_result_tokens( 'high', 'some_tool' ) );
		$this->assertSame( 2000, Testable_Tool_Token_Limits::expose_max_tool_result_tokens( 'bogus', 'some_tool' ) );
		$this->assertSame( 1000, Testable_Tool_Token_Limits::expose_max_tool_result_tokens( 'low', 'run_crawl4ai_job' ) );
	}

	/**
	 * Test budget adjustment leaves within-budget results untouched.
	 */
	public function test_adjust_tool_result_within_budget(): void {
		$result = str_repeat( 'a', 100 ); // 25 tokens.

		$this->assertSame(
			$result,
			Testable_Tool_Token_Limits::adjust_tool_result_for_budget( $result, 'some_tool' )
		);
	}

	/**
	 * Test budget adjustment truncates oversized string results.
	 */
	public function test_adjust_tool_result_truncates_string(): void {
		$result = str_repeat( 'a', 3000 ); // 750 tokens > 500.

		$adjusted = Testable_Tool_Token_Limits::adjust_tool_result_for_budget( $result, 'some_tool' );
		$marker   = "\n\n[... Result truncated by orchestration layer to fit within budget constraints ...]";

		$this->assertIsString( $adjusted );
		$this->assertSame( 2000 + strlen( $marker ), strlen( $adjusted ) );
		$this->assertStringEndsWith( $marker, $adjusted );

		// High-output tools get 2x budget → fits without truncation.
		$this->assertSame(
			$result,
			Testable_Tool_Token_Limits::adjust_tool_result_for_budget( $result, 'web_search' )
		);
	}

	/**
	 * Test truncation preserves array structure (markdown field).
	 */
	public function test_truncate_result_markdown_field(): void {
		$marker = "\n\n[... Result truncated by orchestration layer to fit within budget constraints ...]";

		$result = Testable_Tool_Token_Limits::expose_truncate_result(
			array(
				'markdown' => str_repeat( 'm', 3000 ),
				'keep'     => 'value',
			),
			500
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'value', $result['keep'] );
		$this->assertSame( 1400 + strlen( $marker ), strlen( $result['markdown'] ) );
		$this->assertStringEndsWith( $marker, $result['markdown'] );
	}

	/**
	 * Test get_available_models in both install modes.
	 */
	public function test_get_available_models(): void {
		$models = ToolTokenLimits::get_available_models();

		$this->assertArrayHasKey( 'default', $models );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: provider groups sourced from the base model catalog.
			$this->assertArrayHasKey( 'openai_group', $models );
			$this->assertArrayHasKey( 'label', $models['openai_group'] );
			$this->assertNotEmpty( $models['openai_group']['options'] );
		} else {
			// Standalone: default-only option (no base model config).
			$this->assertSame( array( 'default' ), array_keys( $models ) );
		}
	}
}
