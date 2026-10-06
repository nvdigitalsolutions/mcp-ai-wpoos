<?php
/**
 * Tests for the Pro Jev cascade bridge (Proposal 056, P1 Pro wiring).
 *
 * @package NV_oOS_Pro
 * @since   1.1.97
 */

/**
 * Pro cascade bridge test suite.
 */
class Test_Pro_Cascade_Bridge extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Jev_Tier_Routing' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-pro-jev-tier-routing.php';
		}

		if ( class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) && method_exists( 'WP_MCP_AI_Admin_Settings_Base', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		}
		delete_option( 'wp_mcp_ai_settings' );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_cascade_classifier' );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) && method_exists( 'WP_MCP_AI_Admin_Settings_Base', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		}
		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * The register() method wires the cascade classifier filter.
	 */
	public function test_register_wires_cascade_classifier_filter() {
		WP_MCP_AI_Pro_Jev_Tier_Routing::register();

		$this->assertSame( 20, has_filter( 'wp_mcp_ai_cascade_classifier', array( 'WP_MCP_AI_Pro_Jev_Tier_Routing', 'cascade_classifier' ) ) );
	}

	/**
	 * A frontier-read signal maps to complex (skip the cheap tier).
	 */
	public function test_map_signal_frontier_complex() {
		$this->assertSame(
			'complex',
			WP_MCP_AI_Pro_Jev_Tier_Routing::map_signal_to_tier(
				array(
					'needs_frontier' => 0.9,
					'confidence'     => 0.8,
				)
			)
		);
	}

	/**
	 * A confident cheap-tier read maps to simple.
	 */
	public function test_map_signal_confident_simple() {
		$this->assertSame(
			'simple',
			WP_MCP_AI_Pro_Jev_Tier_Routing::map_signal_to_tier(
				array(
					'needs_frontier' => 0.2,
					'confidence'     => 0.8,
				)
			)
		);
	}

	/**
	 * A weak confidence read fails closed to complex.
	 */
	public function test_map_signal_weak_confidence_fails_closed() {
		$this->assertSame(
			'complex',
			WP_MCP_AI_Pro_Jev_Tier_Routing::map_signal_to_tier(
				array(
					'needs_frontier' => 0.2,
					'confidence'     => 0.3,
				)
			)
		);
	}

	/**
	 * With tier routing disabled the classifier returns null (passthrough).
	 */
	public function test_cascade_classifier_null_when_disabled() {
		$verdict = WP_MCP_AI_Pro_Jev_Tier_Routing::cascade_classifier(
			null,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertNull( $verdict );
	}

	/**
	 * An existing (higher-priority) verdict is preserved untouched.
	 */
	public function test_cascade_classifier_preserves_existing_verdict() {
		$existing = array(
			'tier'       => 'simple',
			'confidence' => 0.9,
			'reason'     => 'earlier classifier',
		);

		$verdict = WP_MCP_AI_Pro_Jev_Tier_Routing::cascade_classifier(
			$existing,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertSame( $existing, $verdict );
	}

	/**
	 * With tier routing enabled but Jev unavailable, the classifier fails
	 * closed to null — no network call is made.
	 */
	public function test_cascade_classifier_null_when_jev_unavailable() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_jev_tier_routing' => true ) );
		if ( class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) && method_exists( 'WP_MCP_AI_Admin_Settings_Base', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		}

		$verdict = WP_MCP_AI_Pro_Jev_Tier_Routing::cascade_classifier(
			null,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello.',
				),
			),
			array()
		);

		$this->assertNull( $verdict );
	}
}
