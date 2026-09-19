<?php
/**
 * Tests for WP_MCP_AI_Tool_Payload_Advisor.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for WP_MCP_AI_Tool_Payload_Advisor.
 */
class Test_Tool_Payload_Advisor extends WP_UnitTestCase {

	/**
	 * Tear down: restore the adaptive-cap option to its default state.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_adaptive_tool_cap' );
		remove_all_filters( 'wp_mcp_ai_adaptive_tool_cap' );
		parent::tearDown();
	}

	/**
	 * The adaptive cap is disabled by default (zero behavior change).
	 */
	public function test_adaptive_cap_disabled_by_default() {
		$this->assertFalse( WP_MCP_AI_Tool_Payload_Advisor::is_adaptive_cap_enabled() );
	}

	/**
	 * A per-assistant "on" override wins over a disabled site default.
	 */
	public function test_assistant_override_on_beats_global_off() {
		delete_option( 'wp_mcp_ai_adaptive_tool_cap' );

		$this->assertTrue(
			WP_MCP_AI_Tool_Payload_Advisor::is_adaptive_cap_enabled(
				array( 'adaptive_tool_cap' => 'on' )
			)
		);
	}

	/**
	 * A per-assistant "off" override wins over an enabled site default.
	 */
	public function test_assistant_override_off_beats_global_on() {
		update_option( 'wp_mcp_ai_adaptive_tool_cap', true );

		$this->assertFalse(
			WP_MCP_AI_Tool_Payload_Advisor::is_adaptive_cap_enabled(
				array( 'adaptive_tool_cap' => 'off' )
			)
		);
	}

	/**
	 * An empty assistant value inherits the enabled site default.
	 */
	public function test_assistant_default_inherits_global_on() {
		update_option( 'wp_mcp_ai_adaptive_tool_cap', true );

		$this->assertTrue(
			WP_MCP_AI_Tool_Payload_Advisor::is_adaptive_cap_enabled(
				array( 'adaptive_tool_cap' => '' )
			)
		);
	}

	/**
	 * The adaptive cap can be enabled via the stored option.
	 */
	public function test_adaptive_cap_enabled_via_option() {
		update_option( 'wp_mcp_ai_adaptive_tool_cap', true );
		$this->assertTrue( WP_MCP_AI_Tool_Payload_Advisor::is_adaptive_cap_enabled() );
	}

	/**
	 * The enable check honors the filter for code-level control.
	 */
	public function test_adaptive_cap_enabled_via_filter() {
		add_filter( 'wp_mcp_ai_adaptive_tool_cap', '__return_true' );
		$this->assertTrue( WP_MCP_AI_Tool_Payload_Advisor::is_adaptive_cap_enabled() );
	}

	/**
	 * Unknown models return 0 (keep the configured cap).
	 */
	public function test_recommended_cap_unknown_model_returns_zero() {
		$this->assertSame( 0, WP_MCP_AI_Tool_Payload_Advisor::recommended_cap_for_model( '' ) );
	}

	/**
	 * Non-empty but unresolvable models either return 0 (no catalog entry)
	 * or one of the cap constants when the rate-limit CCT supplies a default
	 * context window — both are valid advisor behavior.
	 */
	public function test_recommended_cap_unresolvable_model_is_sane() {
		$cap = WP_MCP_AI_Tool_Payload_Advisor::recommended_cap_for_model( 'totally-unknown-model-xyz' );

		$this->assertContains(
			$cap,
			array(
				0,
				WP_MCP_AI_Tool_Payload_Advisor::SMALL_CONTEXT_CAP,
				WP_MCP_AI_Tool_Payload_Advisor::MEDIUM_CONTEXT_CAP,
				WP_MCP_AI_Tool_Payload_Advisor::LARGE_CONTEXT_CAP,
			)
		);
	}

	/**
	 * Small-context models (<=128K) recommend the small cap.
	 */
	public function test_recommended_cap_small_context() {
		$this->assertSame(
			WP_MCP_AI_Tool_Payload_Advisor::SMALL_CONTEXT_CAP,
			WP_MCP_AI_Tool_Payload_Advisor::recommended_cap_for_model( 'gpt-4o' )
		);
	}

	/**
	 * Medium-context models (128K–256K) recommend the medium cap.
	 */
	public function test_recommended_cap_medium_context() {
		$cap = WP_MCP_AI_Tool_Payload_Advisor::recommended_cap_for_model( 'claude-sonnet-4-5' );
		$this->assertGreaterThan( WP_MCP_AI_Tool_Payload_Advisor::SMALL_CONTEXT_CAP, $cap );
		$this->assertLessThanOrEqual( WP_MCP_AI_Tool_Payload_Advisor::MEDIUM_CONTEXT_CAP, $cap );
	}

	/**
	 * Large-context models (>256K) recommend the large cap.
	 */
	public function test_recommended_cap_large_context() {
		$this->assertSame(
			WP_MCP_AI_Tool_Payload_Advisor::LARGE_CONTEXT_CAP,
			WP_MCP_AI_Tool_Payload_Advisor::recommended_cap_for_model( 'gpt-5.6' )
		);
	}

	/**
	 * The summary reflects the configured cap when the adaptive cap is off.
	 */
	public function test_recommendation_summary_reflects_configured_cap() {
		$summary = WP_MCP_AI_Tool_Payload_Advisor::get_recommendation_summary( 'gpt-4o' );

		$this->assertFalse( $summary['enabled'] );
		$this->assertSame( WP_MCP_AI_Tool_Payload_Advisor::SMALL_CONTEXT_CAP, $summary['recommended_cap'] );
		$this->assertSame( 100, $summary['effective_cap'] );
	}
}
