<?php
/**
 * Tests for the Memory Layer 2026 Phase 5 decay sweep + strengthen helper.
 *
 * Covers:
 *  1. Decay math — a record stored 30 days ago decays to ~50% of base.
 *  2. Decay floor — a 1000-day-old record bottoms out at 0.1, not lower.
 *  3. Legacy rows (no confidence_score / no last_accessed_at) use Phase 2
 *     fallbacks and decay correctly.
 *  4. Strengthen on access bumps a 0.5 confidence to 0.55 (capped at 1.0).
 *  5. Strengthen emits the strengthen event with the current MySQL timestamp.
 *  6. The `wp_mcp_ai_memory_decay_max_per_sweep` filter limits how many rows
 *     are processed in a single sweep.
 *  7. Disabling decay via the kill-switch turns `decay_sweep()` into a no-op.
 *  8. The `wp_mcp_ai_memory_decayed` action fires once per changed row.
 *
 * @package WP_MCP_AI
 * @since 1.1.20
 */

if ( ! class_exists( 'WP_MCP_AI_Memory_Capture_Service' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-capture-service.php';
}
if ( ! class_exists( 'WP_MCP_AI_Memory_Tier_Manager' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-tier-manager.php';
}

/**
 * Test case for the Phase 5 decay extension.
 *
 * @since 1.1.20
 */
class Test_Memory_Decay extends WP_UnitTestCase {

	/**
	 * Captured `wp_mcp_ai_memory_decayed` action payloads — populated by the
	 * subscriber installed in {@see setUp()} so individual tests can count
	 * and inspect emissions without re-installing a listener each time.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $decay_events = array();

	/**
	 * Captured `wp_mcp_ai_memory_strengthened` action payloads.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $strengthen_events = array();

	/**
	 * Install action listeners shared across cases.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->decay_events      = array();
		$this->strengthen_events = array();

		add_action(
			'wp_mcp_ai_memory_decayed',
			function ( $context_id, $old, $new ) {
				$this->decay_events[] = array(
					'context_id' => $context_id,
					'old'        => (float) $old,
					'new'        => (float) $new,
				);
			},
			10,
			3
		);

		add_action(
			'wp_mcp_ai_memory_strengthened',
			function ( $context_id, $old, $new, $ts ) {
				$this->strengthen_events[] = array(
					'context_id' => $context_id,
					'old'        => (float) $old,
					'new'        => (float) $new,
					'ts'         => (string) $ts,
				);
			},
			10,
			4
		);
	}

	/**
	 * Tear down — clear every filter / action the suite installs.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_memory_decay_enabled' );
		remove_all_filters( 'wp_mcp_ai_memory_decay_half_life_days' );
		remove_all_filters( 'wp_mcp_ai_memory_decay_floor' );
		remove_all_filters( 'wp_mcp_ai_memory_decay_batch_size' );
		remove_all_filters( 'wp_mcp_ai_memory_decay_max_per_sweep' );
		remove_all_filters( 'wp_mcp_ai_memory_decay_candidates' );
		remove_all_filters( 'wp_mcp_ai_memory_tier_manager_candidates' );
		remove_all_filters( 'wp_mcp_ai_memory_access_strengthen' );
		remove_all_actions( 'wp_mcp_ai_memory_decayed' );
		remove_all_actions( 'wp_mcp_ai_memory_strengthened' );
		parent::tearDown();
	}

	/**
	 * Build a candidate record helper used by most cases.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private function make_candidate( array $overrides = array() ) {
		$defaults = array(
			'context_id'       => 'ctx_' . wp_generate_password( 8, false ),
			'agent_id'         => 'agent_1',
			'confidence_score' => '1.0',
			'last_accessed_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
		);
		return array_merge( $defaults, $overrides );
	}

	/* ------------------------------------------------------------------
	 * 1. Decay math
	 * ------------------------------------------------------------------ */

	/**
	 * A record last accessed exactly one half-life ago should decay to half
	 * of its base confidence (within a small epsilon).
	 */
	public function test_decay_math_at_half_life_halves_confidence() {
		$result = WP_MCP_AI_Memory_Tier_Manager::compute_decayed_confidence( 1.0, 30, 30, 0.1 );
		$this->assertEqualsWithDelta( 0.5, $result, 0.01 );

		// And after two half-lives the value should be a quarter, still above floor.
		$result_two_hl = WP_MCP_AI_Memory_Tier_Manager::compute_decayed_confidence( 1.0, 60, 30, 0.1 );
		$this->assertEqualsWithDelta( 0.25, $result_two_hl, 0.01 );
	}

	/* ------------------------------------------------------------------
	 * 2. Decay floor
	 * ------------------------------------------------------------------ */

	/**
	 * A 1000-day-old record must bottom out at the floor, not below.
	 */
	public function test_decay_floor_caps_lower_bound() {
		$floor  = 0.1;
		$result = WP_MCP_AI_Memory_Tier_Manager::compute_decayed_confidence( 1.0, 1000, 30, $floor );
		$this->assertEqualsWithDelta( $floor, $result, 0.0001 );
		$this->assertGreaterThanOrEqual( $floor, $result );
	}

	/* ------------------------------------------------------------------
	 * 3. Legacy rows
	 * ------------------------------------------------------------------ */

	/**
	 * Legacy CCT rows without `confidence_score` or `last_accessed_at` must
	 * still decay correctly. Bridge defaults: confidence_score = 1.0,
	 * last_accessed_at = stored_at.
	 */
	public function test_legacy_rows_decay_with_phase2_fallbacks() {
		add_filter(
			'wp_mcp_ai_memory_decay_candidates',
			function () {
				// No confidence_score AND no last_accessed_at — only stored_at.
				return array(
					array(
						'context_id' => 'ctx_legacy_decay',
						'stored_at'  => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
					),
				);
			}
		);

		$manager = WP_MCP_AI_Memory_Tier_Manager::get_instance();
		$changed = $manager->decay_sweep();

		$this->assertSame( 1, $changed );
		$this->assertCount( 1, $this->decay_events );
		$this->assertSame( 'ctx_legacy_decay', $this->decay_events[0]['context_id'] );
		// Base confidence falls back to 1.0; half-life 30d → ~0.5.
		$this->assertEqualsWithDelta( 1.0, $this->decay_events[0]['old'], 0.0001 );
		$this->assertEqualsWithDelta( 0.5, $this->decay_events[0]['new'], 0.01 );
	}

	/* ------------------------------------------------------------------
	 * 4. Strengthen on access
	 * ------------------------------------------------------------------ */

	/**
	 * Strengthening 0.5 with the default 0.05 bump yields 0.55, and 0.99
	 * caps at exactly 1.0.
	 */
	public function test_strengthen_on_access_bumps_and_caps() {
		// JetEngine is not available in the headless harness, so the helper
		// returns `false` after the strengthen event has fired. We assert the
		// event payload (which carries the new value) instead of the return.
		WP_MCP_AI_Memory_Tier_Manager::strengthen_on_access( 'ctx_strengthen_1', 0.5 );

		$this->assertCount( 1, $this->strengthen_events );
		$this->assertSame( 'ctx_strengthen_1', $this->strengthen_events[0]['context_id'] );
		$this->assertEqualsWithDelta( 0.5, $this->strengthen_events[0]['old'], 0.0001 );
		$this->assertEqualsWithDelta( 0.55, $this->strengthen_events[0]['new'], 0.0001 );

		// Capping behaviour — 0.99 + 0.05 should clamp to 1.0.
		WP_MCP_AI_Memory_Tier_Manager::strengthen_on_access( 'ctx_strengthen_cap', 0.99 );

		$this->assertCount( 2, $this->strengthen_events );
		$this->assertEqualsWithDelta( 1.0, $this->strengthen_events[1]['new'], 0.0001 );
	}

	/* ------------------------------------------------------------------
	 * 5. Strengthen records a current timestamp
	 * ------------------------------------------------------------------ */

	/**
	 * The strengthen event must carry a MySQL-shaped timestamp matching the
	 * current request time.
	 */
	public function test_strengthen_emits_current_timestamp() {
		WP_MCP_AI_Memory_Tier_Manager::strengthen_on_access( 'ctx_strengthen_ts', 0.3 );

		$this->assertCount( 1, $this->strengthen_events );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$this->strengthen_events[0]['ts']
		);

		// The timestamp should be within a small window of "now" (allow up to
		// 5 seconds slack so a slow test runner doesn't flake).
		$delta = abs( time() - strtotime( $this->strengthen_events[0]['ts'] ) );
		$this->assertLessThanOrEqual( 5, $delta );
	}

	/* ------------------------------------------------------------------
	 * 6. Decay batch limit
	 * ------------------------------------------------------------------ */

	/**
	 * Setting `wp_mcp_ai_memory_decay_max_per_sweep` to 5 must process
	 * exactly 5 rows even when the candidate pool contains many more.
	 */
	public function test_decay_max_per_sweep_caps_processing() {
		add_filter( 'wp_mcp_ai_memory_decay_max_per_sweep', static function () { return 5; } );

		add_filter(
			'wp_mcp_ai_memory_decay_candidates',
			function () {
				$rows = array();
				for ( $i = 0; $i < 20; $i++ ) {
					$rows[] = $this->make_candidate( array( 'context_id' => 'ctx_cap_' . $i ) );
				}
				return $rows;
			}
		);

		$changed = WP_MCP_AI_Memory_Tier_Manager::get_instance()->decay_sweep();

		$this->assertSame( 5, $changed );
		$this->assertCount( 5, $this->decay_events );
	}

	/* ------------------------------------------------------------------
	 * 7. Decay disable
	 * ------------------------------------------------------------------ */

	/**
	 * When the master kill-switch is engaged, the existing `sweep()` must
	 * skip the decay pass entirely — even if candidates exist.
	 */
	public function test_decay_disable_makes_sweep_skip_decay() {
		add_filter( 'wp_mcp_ai_memory_decay_enabled', '__return_false' );

		add_filter(
			'wp_mcp_ai_memory_decay_candidates',
			function () {
				return array( $this->make_candidate( array( 'context_id' => 'ctx_disabled' ) ) );
			}
		);

		$summary = WP_MCP_AI_Memory_Tier_Manager::get_instance()->sweep();

		$this->assertArrayNotHasKey( 'decayed', $summary, 'decay_sweep() must NOT run when the master kill-switch is engaged.' );
		$this->assertCount( 0, $this->decay_events );
	}

	/* ------------------------------------------------------------------
	 * 8. Decayed action fires once per changed row
	 * ------------------------------------------------------------------ */

	/**
	 * The `wp_mcp_ai_memory_decayed` action must fire EXACTLY ONCE per row
	 * whose confidence delta exceeds the write epsilon, and MUST NOT fire
	 * for rows that are already at the floor (delta ≈ 0).
	 */
	public function test_decayed_action_fires_once_per_changed_row() {
		add_filter(
			'wp_mcp_ai_memory_decay_candidates',
			function () {
				return array(
					// Should decay — base 1.0, 30 days, half-life 30 → 0.5 (delta = 0.5).
					$this->make_candidate( array( 'context_id' => 'ctx_changes' ) ),
					// Already at floor with zero days since access — should NOT decay.
					array(
						'context_id'       => 'ctx_at_floor',
						'confidence_score' => '0.1',
						'last_accessed_at' => current_time( 'mysql' ),
					),
					// Decays from 0.5 to ~0.25 (delta well above epsilon).
					array(
						'context_id'       => 'ctx_partial',
						'confidence_score' => '0.5',
						'last_accessed_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
					),
				);
			}
		);

		$changed = WP_MCP_AI_Memory_Tier_Manager::get_instance()->decay_sweep();

		$this->assertSame( 2, $changed );
		$this->assertCount( 2, $this->decay_events );

		$ids = array_column( $this->decay_events, 'context_id' );
		$this->assertContains( 'ctx_changes', $ids );
		$this->assertContains( 'ctx_partial', $ids );
		$this->assertNotContains( 'ctx_at_floor', $ids );
	}

	/* ------------------------------------------------------------------
	 * BONUS: integration with sweep() — decayed key landed in summary
	 * ------------------------------------------------------------------ */

	/**
	 * When the master sweep runs and decay candidates exist, the returned
	 * summary must include a `decayed` key reflecting the changed-row count.
	 */
	public function test_sweep_summary_includes_decayed_key() {
		add_filter(
			'wp_mcp_ai_memory_decay_candidates',
			function () {
				return array(
					$this->make_candidate( array( 'context_id' => 'ctx_summary_1' ) ),
					$this->make_candidate( array( 'context_id' => 'ctx_summary_2' ) ),
				);
			}
		);

		$summary = WP_MCP_AI_Memory_Tier_Manager::get_instance()->sweep();

		$this->assertArrayHasKey( 'decayed', $summary );
		$this->assertSame( 2, $summary['decayed'] );
	}
}
