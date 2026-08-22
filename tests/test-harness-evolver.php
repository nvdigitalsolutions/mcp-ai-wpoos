<?php
/**
 * Tests for the Continual Harness Evolver (failure signatures, gates, budget).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test harness evolver functionality.
 */
class Test_Harness_Evolver extends WP_UnitTestCase {

	/**
	 * Clean up filter and transient state between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_harness_evolution_enabled' );
		remove_all_filters( 'wp_mcp_ai_harness_evolution_frequency' );
		remove_all_filters( 'wp_mcp_ai_harness_evolution_warmup' );
		remove_all_filters( 'wp_mcp_ai_harness_evolution_budget_usd' );
		remove_all_filters( 'wp_mcp_ai_harness_verification_on_no_cases' );
		remove_all_filters( 'wp_mcp_ai_harness_verification_enabled' );
		delete_transient( 'wp_mcp_ai_evolution_budget_0' );
		delete_transient( 'wp_mcp_ai_evolution_budget_999999' );

		parent::tearDown();
	}

	/**
	 * Build a synthetic tool-call trajectory event.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param bool   $is_error  Whether the call errored.
	 * @return array Event array.
	 */
	private function make_tool_call_event( $tool_slug, $arguments, $is_error = false ) {
		$result = array( 'is_error' => $is_error );
		if ( $is_error ) {
			$result['error_message'] = 'Synthetic failure';
		}

		return array(
			'step_type' => 'tool_call',
			'tool_slug' => $tool_slug,
			'data'      => wp_json_encode(
				array(
					'arguments' => $arguments,
					'result'    => $result,
				)
			),
		);
	}

	/**
	 * Empty trajectories produce empty signatures.
	 */
	public function test_detect_failure_signatures_empty() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'sig-empty-session', 0 );

		$this->assertSame( array(), $evolver->detect_failure_signatures( array() ) );
	}

	/**
	 * Tool failures are detected.
	 */
	public function test_detect_failure_signatures_tool_failures() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'sig-fail-session', 0 );

		$trajectory = array(
			$this->make_tool_call_event( 'web_search', array( 'q' => 'x' ), true ),
		);

		$signatures = $evolver->detect_failure_signatures( $trajectory );

		$this->assertArrayHasKey( 'tool_failures', $signatures );
		$this->assertCount( 1, $signatures['tool_failures'] );
		$this->assertSame( 'web_search', $signatures['tool_failures'][0]['tool'] );
	}

	/**
	 * Stuck loops (3+ identical consecutive calls) are detected.
	 */
	public function test_detect_failure_signatures_stuck_loops() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'sig-loop-session', 0 );

		$trajectory = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$trajectory[] = $this->make_tool_call_event( 'loop_tool', array( 'same' => 'args' ) );
		}

		$signatures = $evolver->detect_failure_signatures( $trajectory );

		$this->assertArrayHasKey( 'stuck_loops', $signatures );
		$this->assertCount( 1, $signatures['stuck_loops'] );
		$this->assertSame( 4, $signatures['stuck_loops'][0]['repetitions'] );
	}

	/**
	 * Budget exhaustion and low success rate are detected.
	 */
	public function test_detect_failure_signatures_budget_and_success_rate() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'sig-budget-session', 0 );

		$trajectory = array(
			$this->make_tool_call_event( 'tool_a', array(), true ),
			$this->make_tool_call_event( 'tool_b', array(), true ),
			array(
				'step_type' => 'session_end',
				'status'    => 'timeout',
			),
		);

		$signatures = $evolver->detect_failure_signatures( $trajectory );

		$this->assertArrayHasKey( 'budget_exhausted', $signatures );
		$this->assertTrue( $signatures['budget_exhausted'] );
		$this->assertArrayHasKey( 'low_success_rate', $signatures );
	}

	/**
	 * Analyze without audit trail data is graceful, not fatal.
	 */
	public function test_analyze_failures_no_trail_graceful() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'analyze-no-trail-session', 0 );

		$analysis = $evolver->analyze_failures( 'all', 50 );

		$this->assertIsArray( $analysis );
		$this->assertSame( 0, $analysis['failures_detected'] );
		$this->assertFalse( $analysis['trail_available'] );
		$this->assertArrayHasKey( 'note', $analysis );
	}

	/**
	 * Invalid components return WP_Error from analyze.
	 */
	public function test_analyze_failures_invalid_component() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'analyze-bad-session', 0 );

		$result = $evolver->analyze_failures( 'bogus' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_evolution_invalid_component', $result->get_error_code() );
	}

	/**
	 * Evolution is disabled by default.
	 */
	public function test_evolve_disabled_by_default() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'evolve-disabled-session', 999999 );

		$result = $evolver->evolve();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['evolved'] );
	}

	/**
	 * Invalid components return WP_Error from evolve.
	 */
	public function test_evolve_invalid_component_returns_wp_error() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'evolve-bad-session', 999999 );

		$result = $evolver->evolve( 'bogus' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_evolution_invalid_component', $result->get_error_code() );
	}

	/**
	 * An exhausted budget blocks evolution before any trajectory read.
	 */
	public function test_evolve_budget_gate() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		add_filter( 'wp_mcp_ai_harness_evolution_enabled', '__return_true' );
		set_transient( 'wp_mcp_ai_evolution_budget_999999', 99.0, HOUR_IN_SECONDS );

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'evolve-budget-session', 999999 );

		$result = $evolver->evolve();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_evolution_budget_exceeded', $result->get_error_code() );
	}

	/**
	 * Warmup and frequency gates behave as documented.
	 */
	public function test_should_evolve_warmup_and_frequency() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		add_filter( 'wp_mcp_ai_harness_evolution_enabled', '__return_true' );

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'freq-session', 1 );

		$this->assertFalse( $evolver->should_evolve( 1 ) ); // Below warmup (5).
		$this->assertTrue( $evolver->should_evolve( 5 ) ); // Early phase, every 5.
		$this->assertFalse( $evolver->should_evolve( 6 ) ); // Off-beat.
		$this->assertTrue( $evolver->should_evolve( 100 ) ); // Stable phase, every 20.
		$this->assertFalse( $evolver->should_evolve( 101 ) ); // Off-beat.
	}

	/**
	 * Evolved skills normalize to the Skill Registry shape.
	 */
	public function test_get_evolved_skills_normalizes_registry_shape() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		update_option(
			'wp_mcp_ai_evolved_skills',
			array(
				'alpha'  => array(
					'name'        => 'alpha-skill',
					'description' => 'A skill <script>alert(1)</script>',
					'code'        => 'Contact bob@example.com for help.',
					'evolved'     => true,
				),
				'broken' => array( 'description' => 'No name key' ),
			),
			false
		);

		$skills = WP_MCP_AI_Agent_Harness_Evolver::get_evolved_skills();

		$this->assertArrayHasKey( 'alpha-skill', $skills );
		$this->assertSame( 'alpha-skill', $skills['alpha-skill']['name'] );
		$this->assertArrayHasKey( 'description', $skills['alpha-skill'] );
		$this->assertArrayHasKey( 'instructions', $skills['alpha-skill'] );
		$this->assertTrue( $skills['alpha-skill']['evolved'] );
		$this->assertArrayNotHasKey( 'broken', $skills );

		if ( class_exists( 'WP_MCP_AI_Pii_Filter' ) ) {
			$this->assertStringNotContainsString( 'bob@example.com', $skills['alpha-skill']['instructions'] );
		}
	}

	/**
	 * The constructor survives swapped argument order.
	 */
	public function test_constructor_survives_swapped_arguments() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		// Historical (buggy) order: assistant first, session second.
		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 42, 'swapped-session' );

		$reflect        = new ReflectionClass( $evolver );
		$assistant_prop = $reflect->getProperty( 'assistant_id' );
		$assistant_prop->setAccessible( true );
		$session_prop = $reflect->getProperty( 'session_id' );
		$session_prop->setAccessible( true );

		$this->assertSame( 42, $assistant_prop->getValue( $evolver ) );
		$this->assertSame( 'swapped-session', $session_prop->getValue( $evolver ) );

		// Documented order: session first, assistant second.
		$evolver_ok = new WP_MCP_AI_Agent_Harness_Evolver( 'normal-session', 7 );

		$this->assertSame( 7, $assistant_prop->getValue( $evolver_ok ) );
		$this->assertSame( 'normal-session', $session_prop->getValue( $evolver_ok ) );
	}

	/**
	 * Verification without replay data skips (allows) by default.
	 */
	public function test_verify_prompt_candidate_skips_without_replay_data() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Failure_Replay class not available.' );
		}

		// Clean any leftover trace data for this assistant.
		if ( class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( 999999 );
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'verify-skip-session', 999999 );
		$result  = $evolver->verify_prompt_candidate( 'prompt a', 'prompt b' );

		$this->assertIsArray( $result );
		$this->assertSame( 'skip', $result['decision'] );
	}

	/**
	 * Fail-closed behavior on missing replay data is configurable.
	 */
	public function test_verify_prompt_candidate_can_fail_closed_without_replay_data() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Failure_Replay' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Failure_Replay class not available.' );
		}

		if ( class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( 999999 );
		}
		add_filter(
			'wp_mcp_ai_harness_verification_on_no_cases',
			static function () {
				return 'reject';
			}
		);

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'verify-reject-session', 999999 );
		$result  = $evolver->verify_prompt_candidate( 'prompt a', 'prompt b' );

		$this->assertIsArray( $result );
		$this->assertSame( 'reject', $result['decision'] );
	}

	/**
	 * Skipped verification writes nothing to the population or learning log.
	 */
	public function test_verify_skip_writes_nothing() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Harness_Evolver class not available.' );
		}

		if ( class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( 999999 );
		}
		if ( class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) ) {
			WP_MCP_AI_Artifact_Learning_Log::clear();
		}
		if ( class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			WP_MCP_AI_Artifact_Population::clear();
		}

		$evolver = new WP_MCP_AI_Agent_Harness_Evolver( 'verify-skip-log-session', 999999 );
		$result  = $evolver->verify_prompt_candidate( 'prompt a', 'prompt b' );

		$this->assertIsArray( $result );
		$this->assertSame( 'skip', $result['decision'] );

		if ( class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) ) {
			$this->assertSame( array(), WP_MCP_AI_Artifact_Learning_Log::get_entries() );
		}
		if ( class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->assertSame( array(), WP_MCP_AI_Artifact_Population::get_population() );
		}
	}
}
