<?php
/**
 * Usage tracker port tests (Wave D3d).
 *
 * Characterization suite for `UsageTracker`. Assertions mirror the base
 * plugin's usage tracker tests: meta key, provider/model resolution,
 * usage normalization, totals incrementing (model + assistant level),
 * snapshot filter, recorded action, cost calculation via the fallback
 * pricing map (exact + prefix matching), and user-deletion cleanup.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Analytics\UsageTracker;

/**
 * @group analytics
 */
class Test_Usage_Tracker extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_settings' );
	}

	public function tearDown(): void {
		\remove_all_filters( 'wp_mcp_ai_usage_snapshot' );
		\remove_all_actions( 'wp_mcp_ai_after_usage_recorded' );

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	public function test_user_meta_key_constant(): void {
		$this->assertSame( '_wp_mcp_ai_usage_totals', UsageTracker::USER_META_KEY );
	}

	public function test_record_chat_usage_persists_and_increments(): void {
		$user_id = self::factory()->user->create();

		UsageTracker::record_chat_usage(
			$user_id,
			7,
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o',
			),
			array(
				'model' => 'gpt-4o',
				'usage' => array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 50,
				),
			)
		);

		$usage = UsageTracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'openai', $usage );
		$this->assertArrayHasKey( 'gpt-4o', $usage['openai'] );

		$totals = $usage['openai']['gpt-4o'];
		$this->assertSame( 1, $totals['requests'] );
		$this->assertSame( 100, $totals['prompt_tokens'] );
		$this->assertSame( 50, $totals['completion_tokens'] );
		$this->assertSame( 150, $totals['total_tokens'] );
		$this->assertNotEmpty( $totals['last_used_gmt'] );
		$this->assertSame( 1, $totals['assistants'][7]['requests'] );
		$this->assertSame( 150, $totals['assistants'][7]['total_tokens'] );

		// Second record increments.
		UsageTracker::record_chat_usage(
			$user_id,
			7,
			array(),
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o',
				'usage'    => array(
					'prompt_tokens'     => 10,
					'completion_tokens' => 20,
				),
			)
		);

		$usage  = UsageTracker::get_usage_for_user( $user_id );
		$totals = $usage['openai']['gpt-4o'];
		$this->assertSame( 2, $totals['requests'] );
		$this->assertSame( 110, $totals['prompt_tokens'] );
		$this->assertSame( 180, $totals['total_tokens'] );
	}

	public function test_record_chat_usage_ignores_empty_usage_and_no_user(): void {
		$user_id = self::factory()->user->create();

		// No usage in response → nothing stored.
		UsageTracker::record_chat_usage( $user_id, 0, array(), array( 'model' => 'gpt-4o' ) );
		$this->assertSame( array(), UsageTracker::get_usage_for_user( $user_id ) );

		// User 0 → nothing stored.
		UsageTracker::record_chat_usage(
			0,
			0,
			array(),
			array(
				'usage' => array( 'total_tokens' => 10 ),
			)
		);
	}

	public function test_record_chat_usage_resolves_provider_and_model_defaults(): void {
		$user_id = self::factory()->user->create();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			\update_option(
				'wp_mcp_ai_settings',
				array(
					'default_provider'      => 'gemini',
					'default_model'         => 'gemini-2.5-pro',
					'default_gemini_model'  => 'gemini-2.5-pro',
				)
			);
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		} else {
			\NvoosContentGraphAi\CoreBridge::instance()->settings->set( 'ai_default_provider', 'gemini' );
			\NvoosContentGraphAi\CoreBridge::instance()->settings->set( 'ai_default_model', 'gemini-2.5-pro' );
		}

		UsageTracker::record_chat_usage(
			$user_id,
			0,
			array(),
			array(
				'usage' => array( 'total_tokens' => 25 ),
			)
		);

		$usage = UsageTracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'gemini', $usage );
		$this->assertArrayHasKey( 'gemini-2.5-pro', $usage['gemini'] );
		$this->assertSame( 25, $usage['gemini']['gemini-2.5-pro']['total_tokens'] );
	}

	public function test_snapshot_filter_and_recorded_action(): void {
		$user_id = self::factory()->user->create();

		\add_filter(
			'wp_mcp_ai_usage_snapshot',
			static function ( $usage ) {
				$usage['cached_tokens'] = 42;
				return $usage;
			}
		);

		$fired = null;
		\add_action(
			'wp_mcp_ai_after_usage_recorded',
			static function ( $user_id, $assistant_id, $provider, $model, $totals, $usage ) use ( &$fired ) {
				$fired = array( $provider, $model, $totals['cached_tokens'], $usage['cached_tokens'] );
			},
			10,
			6
		);

		UsageTracker::record_chat_usage(
			$user_id,
			0,
			array(),
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o',
				'usage'    => array( 'total_tokens' => 5 ),
			)
		);

		$this->assertSame( array( 'openai', 'gpt-4o', 42, 42 ), $fired );
	}

	public function test_cost_calculation_exact_and_prefix_pricing(): void {
		// Exact fallback pricing: gpt-4o = $0.0025 in / $0.01 out per 1K.
		$cost = UsageTracker::calculate_cost( 'openai', 'gpt-4o', 1000, 500 );
		$this->assertEquals( 0.0025 + 0.005, $cost );

		// Prefix match: gpt-4o-2024-11-20 matches gpt-4o pricing.
		$cost = UsageTracker::calculate_cost( 'openai', 'gpt-4o-2024-11-20', 1000, 0 );
		$this->assertEquals( 0.0025, $cost );

		// Unknown model → 0.
		$this->assertSame( 0.0, UsageTracker::calculate_cost( 'openai', 'completely-unknown-model', 1000, 1000 ) );
	}

	public function test_calculate_user_total_cost(): void {
		$user_id = self::factory()->user->create();

		UsageTracker::record_chat_usage(
			$user_id,
			0,
			array(),
			array(
				'provider' => 'openai',
				'model'    => 'gpt-4o-mini',
				'usage'    => array(
					'prompt_tokens'     => 1000,
					'completion_tokens' => 1000,
				),
			)
		);

		$expected = ( 1000 / 1000 ) * 0.00015 + ( 1000 / 1000 ) * 0.0006;
		$this->assertEquals( $expected, UsageTracker::calculate_user_total_cost( $user_id ) );
		$this->assertSame( 0.0, UsageTracker::calculate_user_total_cost( 0 ) );
	}

	public function test_delete_usage_for_user(): void {
		$user_id = self::factory()->user->create();

		UsageTracker::record_chat_usage(
			$user_id,
			0,
			array(),
			array(
				'provider' => 'openai',
				'usage'    => array( 'total_tokens' => 10 ),
			)
		);
		$this->assertNotEmpty( UsageTracker::get_usage_for_user( $user_id ) );

		UsageTracker::delete_usage_for_user( $user_id );
		$this->assertSame( array(), UsageTracker::get_usage_for_user( $user_id ) );

		// Zero user is a no-op.
		UsageTracker::delete_usage_for_user( 0 );
	}
}
