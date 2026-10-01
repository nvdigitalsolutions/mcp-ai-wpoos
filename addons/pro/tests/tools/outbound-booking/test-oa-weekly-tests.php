<?php
/**
 * Test Outbound Booking weekly A/B test promotion.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Weekly test promotion tests.
 */
class Test_OA_Weekly_Tests extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$base = WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/';
		require_once $base . 'class-wp-mcp-ai-oa-settings.php';
		require_once $base . 'class-wp-mcp-ai-oa-templates.php';
		require_once $base . 'class-wp-mcp-ai-oa-angle-cpt.php';
		require_once $base . 'class-wp-mcp-ai-oa-booking-link-cpt.php';
		require_once $base . 'class-wp-mcp-ai-oa-outbox.php';
		require_once $base . 'class-wp-mcp-ai-oa-channels.php';
		require_once $base . 'class-wp-mcp-ai-oa-notifications.php';
		require_once $base . 'class-wp-mcp-ai-oa-engine.php';

		update_option( 'wp_mcp_ai_settings', array( 'enable_outbound_booking_toolkit' => 1 ) );
		delete_option( WP_MCP_AI_OA_Engine::HISTORY_OPTION );
		if ( ! post_type_exists( 'mcp_ai_oa_angle' ) ) {
			register_post_type( 'mcp_ai_oa_angle', array( 'public' => false, 'label' => 'Angles' ) );
		}
	}

	/**
	 * The better-performing variant is promoted to champion.
	 */
	public function test_promotes_winning_variant() {
		$root = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_angle',
				'post_title'  => 'Root Angle',
				'post_status' => 'publish',
			)
		);
		$challenger = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_angle',
				'post_title'  => 'Challenger B',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $root, '_oa_angle_test_status', 'champion' );
		update_post_meta( $root, '_oa_angle_sends', 20 );
		update_post_meta( $root, '_oa_angle_replies', 2 );
		update_post_meta( $challenger, '_oa_angle_variant_of', $root );
		update_post_meta( $challenger, '_oa_angle_test_status', 'challenger' );
		update_post_meta( $challenger, '_oa_angle_sends', 10 );
		update_post_meta( $challenger, '_oa_angle_replies', 5 );

		WP_MCP_AI_OA_Settings::update( array( 'min_test_sends' => 10 ) );

		$summary = WP_MCP_AI_OA_Engine::run_weekly_tests();
		$this->assertCount( 1, $summary['promotions'] );

		$this->assertSame( 'champion', get_post_meta( $challenger, '_oa_angle_test_status', true ) );
		$this->assertSame( 'paused', get_post_meta( $root, '_oa_angle_test_status', true ) );

		$history = get_option( WP_MCP_AI_OA_Engine::HISTORY_OPTION, array() );
		$this->assertCount( 1, $history );
		$this->assertSame( 'Challenger B', $history[0]['promotions'][0]['winner'] );
	}

	/**
	 * Groups below the minimum-send threshold are left untouched.
	 */
	public function test_skips_groups_below_threshold() {
		$root = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_angle',
				'post_title'  => 'Quiet Angle',
				'post_status' => 'publish',
			)
		);
		$challenger = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_angle',
				'post_title'  => 'Quiet Challenger',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $root, '_oa_angle_test_status', 'champion' );
		update_post_meta( $root, '_oa_angle_sends', 2 );
		update_post_meta( $challenger, '_oa_angle_variant_of', $root );
		update_post_meta( $challenger, '_oa_angle_test_status', 'challenger' );
		update_post_meta( $challenger, '_oa_angle_sends', 1 );

		WP_MCP_AI_OA_Settings::update( array( 'min_test_sends' => 10 ) );

		$summary = WP_MCP_AI_OA_Engine::run_weekly_tests();
		$this->assertEmpty( $summary['promotions'] );
		$this->assertSame( 'champion', get_post_meta( $root, '_oa_angle_test_status', true ) );
		$this->assertSame( 'challenger', get_post_meta( $challenger, '_oa_angle_test_status', true ) );
		$this->assertEmpty( get_option( WP_MCP_AI_OA_Engine::HISTORY_OPTION, array() ) );
	}

	/**
	 * Single-member groups have nothing to test against.
	 */
	public function test_skips_single_member_groups() {
		$loner = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_oa_angle',
				'post_title'  => 'Loner Angle',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $loner, '_oa_angle_test_status', 'champion' );
		update_post_meta( $loner, '_oa_angle_sends', 50 );
		update_post_meta( $loner, '_oa_angle_replies', 25 );

		WP_MCP_AI_OA_Settings::update( array( 'min_test_sends' => 10 ) );

		$summary = WP_MCP_AI_OA_Engine::run_weekly_tests();
		$this->assertEmpty( $summary['promotions'] );
		$this->assertSame( 'champion', get_post_meta( $loner, '_oa_angle_test_status', true ) );
	}
}
