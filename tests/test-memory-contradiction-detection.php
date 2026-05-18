<?php
/**
 * Tests for the Memory Layer 2026 Phase 5 contradiction detector.
 *
 * Covers:
 *  1. Detector returns empty when no candidate exceeds the similarity threshold.
 *  2. Key/value conflict — same metadata.key, different metadata.value.
 *  3. Title-match + content-diverges (Jaccard heuristic).
 *  4. Auto-supersession OFF by default — event fires, no `wp_mcp_ai_memory_contradiction_resolved`.
 *  5. Auto-supersession ON — `resolved` event fires.
 *  6. Master kill-switch — disabled detector early-returns with no events.
 *  7. `store_agent_context` integration — write near an existing similar memory
 *     triggers the event AND swallows a throwing detector instead of blocking the
 *     store.
 *  8. Performance — detector caps strictly at top-K candidates regardless of pool
 *     size; uncapped scanning would emit more events than top-K allows.
 *
 * @package WP_MCP_AI
 * @since 1.1.20
 */

if ( ! class_exists( 'WP_MCP_AI_Memory_Tier_Manager' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-tier-manager.php';
}
if ( ! class_exists( 'WP_MCP_AI_Memory_Contradiction_Detector' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-contradiction-detector.php';
}

/**
 * Test case for the Phase 5 contradiction detector.
 *
 * @since 1.1.20
 */
class Test_Memory_Contradiction_Detection extends WP_UnitTestCase {

	/**
	 * Captured `wp_mcp_ai_memory_contradiction_detected` payloads.
	 *
	 * @var array<int,array<string,string>>
	 */
	private $detected_events = array();

	/**
	 * Captured `wp_mcp_ai_memory_contradiction_resolved` payloads.
	 *
	 * @var array<int,array<string,string>>
	 */
	private $resolved_events = array();

	/**
	 * Install action subscribers.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->detected_events = array();
		$this->resolved_events = array();

		add_action(
			'wp_mcp_ai_memory_contradiction_detected',
			function ( $existing_id, $new_id, $reason ) {
				$this->detected_events[] = array(
					'existing_id' => (string) $existing_id,
					'new_id'      => (string) $new_id,
					'reason'      => (string) $reason,
				);
			},
			10,
			3
		);

		add_action(
			'wp_mcp_ai_memory_contradiction_resolved',
			function ( $existing_id, $new_id ) {
				$this->resolved_events[] = array(
					'existing_id' => (string) $existing_id,
					'new_id'      => (string) $new_id,
				);
			},
			10,
			2
		);
	}

	/**
	 * Tear down — clear every filter the suite installs.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_memory_contradiction_detection_enabled' );
		remove_all_filters( 'wp_mcp_ai_memory_contradiction_detection_on_store' );
		remove_all_filters( 'wp_mcp_ai_memory_contradiction_top_k' );
		remove_all_filters( 'wp_mcp_ai_memory_contradiction_similarity_threshold' );
		remove_all_filters( 'wp_mcp_ai_memory_contradiction_jaccard_threshold' );
		remove_all_filters( 'wp_mcp_ai_memory_contradiction_auto_supersede' );
		remove_all_filters( 'wp_mcp_ai_memory_contradiction_candidates' );
		remove_all_filters( 'wp_mcp_ai_recall_memory_candidates' );
		remove_all_actions( 'wp_mcp_ai_memory_contradiction_detected' );
		remove_all_actions( 'wp_mcp_ai_memory_contradiction_resolved' );
		parent::tearDown();
	}

	/* ------------------------------------------------------------------
	 * 1. Empty when no candidate exceeds the similarity threshold
	 * ------------------------------------------------------------------ */

	/**
	 * When the only candidate scores below the similarity threshold, the
	 * detector must return an empty array and emit no events.
	 */
	public function test_returns_empty_when_below_similarity_threshold() {
		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				return array(
					array(
						'context_id' => 'ctx_existing_low',
						'title'      => 'Random unrelated entry',
						'content'    => 'Apples and oranges and bananas.',
						'similarity' => 0.10, // Below default 0.85 threshold.
						'metadata'   => array( 'key' => 'k', 'value' => 'old' ),
					),
				);
			}
		);

		$result = WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
			array(
				'context_id' => 'ctx_new_1',
				'agent_id'   => 'agent_1',
				'title'      => 'Random unrelated entry',
				'content'    => 'Pears and grapes and melons.',
				'metadata'   => array( 'key' => 'k', 'value' => 'new' ),
			)
		);

		$this->assertSame( array(), $result );
		$this->assertCount( 0, $this->detected_events );
	}

	/* ------------------------------------------------------------------
	 * 2. Key/value conflict
	 * ------------------------------------------------------------------ */

	/**
	 * Same metadata.key with a different metadata.value flags the older row.
	 */
	public function test_key_value_conflict_flags_existing_row() {
		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				return array(
					array(
						'context_id' => 'ctx_kv_old',
						'title'      => 'Preferred greeting',
						'content'    => 'The agent should greet customers warmly and ask for their order.',
						'similarity' => 0.95,
						'metadata'   => array(
							'key'   => 'preferred_greeting',
							'value' => 'Howdy partner',
						),
					),
				);
			}
		);

		$result = WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
			array(
				'context_id' => 'ctx_kv_new',
				'agent_id'   => 'agent_1',
				'title'      => 'Preferred greeting',
				'content'    => 'The agent should greet customers warmly and ask for their order.',
				'metadata'   => array(
					'key'   => 'preferred_greeting',
					'value' => 'Hello friend',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'key_value_conflict', $result[0]['reason'] );
		$this->assertSame( 'ctx_kv_old', $result[0]['existing_context_id'] );
		$this->assertSame( 'ctx_kv_new', $result[0]['new_context_id'] );

		$this->assertCount( 1, $this->detected_events );
		$this->assertSame( 'key_value_conflict', $this->detected_events[0]['reason'] );
	}

	/* ------------------------------------------------------------------
	 * 3. Title-match, content diverges
	 * ------------------------------------------------------------------ */

	/**
	 * Same title with low Jaccard similarity on content flags the older row.
	 */
	public function test_same_title_different_content_flags_existing_row() {
		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				return array(
					array(
						'context_id' => 'ctx_title_old',
						'title'      => 'Project mission statement',
						'content'    => 'Build the best calendar widget for small teams.',
						'similarity' => 0.95,
					),
				);
			}
		);

		$result = WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
			array(
				'context_id' => 'ctx_title_new',
				'agent_id'   => 'agent_1',
				// Same title (case-insensitive).
				'title'      => 'PROJECT mission statement',
				// Wildly different tokens → Jaccard far below default 0.4.
				'content'    => 'Operate spacecraft logistics for orbital rescues.',
			)
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'title_match_content_diverges', $result[0]['reason'] );
		$this->assertSame( 'ctx_title_old', $result[0]['existing_context_id'] );

		$this->assertCount( 1, $this->detected_events );
	}

	/* ------------------------------------------------------------------
	 * 4. Auto-supersession OFF by default
	 * ------------------------------------------------------------------ */

	/**
	 * Detection MUST fire `detected` but NOT `resolved` when
	 * auto-supersession is off (the default).
	 */
	public function test_auto_supersede_off_by_default_does_not_resolve() {
		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				return array(
					array(
						'context_id' => 'ctx_auto_old',
						'title'      => 'Capital',
						'content'    => 'Paris is the capital.',
						'similarity' => 0.95,
						'metadata'   => array( 'key' => 'capital_of_france', 'value' => 'Paris' ),
					),
				);
			}
		);

		WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
			array(
				'context_id' => 'ctx_auto_new',
				'agent_id'   => 'agent_1',
				'title'      => 'Capital',
				'content'    => 'Paris is the capital.',
				'metadata'   => array( 'key' => 'capital_of_france', 'value' => 'Lyon' ),
			)
		);

		$this->assertCount( 1, $this->detected_events );
		$this->assertCount( 0, $this->resolved_events, 'auto-supersession is off by default.' );
	}

	/* ------------------------------------------------------------------
	 * 5. Auto-supersession ON
	 * ------------------------------------------------------------------ */

	/**
	 * When the auto-supersede filter is enabled, the `resolved` event MUST fire
	 * for each detected contradiction (mutation is best-effort and gated on
	 * JetEngine — the event always fires).
	 */
	public function test_auto_supersede_on_emits_resolved_event() {
		add_filter( 'wp_mcp_ai_memory_contradiction_auto_supersede', '__return_true' );

		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				return array(
					array(
						'context_id' => 'ctx_super_old',
						'title'      => 'Capital',
						'content'    => 'Lyon is the capital.',
						'similarity' => 0.95,
						'metadata'   => array( 'key' => 'capital_of_france', 'value' => 'Lyon' ),
					),
				);
			}
		);

		WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
			array(
				'context_id' => 'ctx_super_new',
				'agent_id'   => 'agent_1',
				'title'      => 'Capital',
				'content'    => 'Paris is the capital.',
				'metadata'   => array( 'key' => 'capital_of_france', 'value' => 'Paris' ),
			)
		);

		$this->assertCount( 1, $this->detected_events );
		$this->assertCount( 1, $this->resolved_events );
		$this->assertSame( 'ctx_super_old', $this->resolved_events[0]['existing_id'] );
		$this->assertSame( 'ctx_super_new', $this->resolved_events[0]['new_id'] );
	}

	/* ------------------------------------------------------------------
	 * 6. Master kill-switch
	 * ------------------------------------------------------------------ */

	/**
	 * When the detector is globally disabled, no events are emitted and an
	 * empty array is returned even when candidates obviously conflict.
	 */
	public function test_master_kill_switch_disables_detection() {
		add_filter( 'wp_mcp_ai_memory_contradiction_detection_enabled', '__return_false' );

		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				return array(
					array(
						'context_id' => 'ctx_killed_old',
						'title'      => 'X',
						'content'    => 'A',
						'similarity' => 0.99,
						'metadata'   => array( 'key' => 'k', 'value' => 'old' ),
					),
				);
			}
		);

		$result = WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
			array(
				'context_id' => 'ctx_killed_new',
				'agent_id'   => 'agent_1',
				'title'      => 'X',
				'content'    => 'A',
				'metadata'   => array( 'key' => 'k', 'value' => 'new' ),
			)
		);

		$this->assertSame( array(), $result );
		$this->assertCount( 0, $this->detected_events );
	}

	/* ------------------------------------------------------------------
	 * 7. store_agent_context integration — fires event and survives throws
	 * ------------------------------------------------------------------ */

	/**
	 * A write through `store_agent_context` that has a similar existing memory
	 * must fire the contradiction event AND succeed even when the detector
	 * itself throws.
	 *
	 * We exercise both halves in one test because they share the same
	 * fixture scaffolding.
	 */
	public function test_store_agent_context_integration_fires_and_survives_throws() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Store_Agent_Context' ) ) {
			$candidates = glob( dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-store-agent-context.php' );
			if ( ! empty( $candidates ) ) {
				require_once $candidates[0];
			}
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Store_Agent_Context' ) ) {
			$this->markTestSkipped( 'store_agent_context tool not loadable in this harness.' );
		}

		// Seed a similar existing memory the detector will see during the store.
		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				return array(
					array(
						'context_id' => 'ctx_seed_old',
						'title'      => 'Customer SLA',
						'content'    => 'Response time within 24 hours on business days.',
						'similarity' => 0.95,
						'metadata'   => array(
							'key'   => 'response_sla',
							'value' => '24h',
						),
					),
				);
			}
		);

		$tool = new WP_MCP_AI_Tool_Store_Agent_Context();

		// --- Half A: event fires for a colliding write.
		$result = $tool->execute(
			array(
				'agent_id'     => 'agent_test_seed',
				'context_type' => 'fact',
				'context_data' => array(
					'title'    => 'Customer SLA',
					'content'  => 'Response time within 24 hours on business days.',
					'metadata' => array(
						'key'   => 'response_sla',
						'value' => '4h',
					),
				),
			),
			array()
		);

		$this->assertNotEmpty( $result );
		if ( is_array( $result ) ) {
			$this->assertSame( true, $result['success'] );
		}
		$this->assertCount( 1, $this->detected_events, 'A colliding write must trigger one detection event.' );
		$this->assertSame( 'key_value_conflict', $this->detected_events[0]['reason'] );

		// --- Half B: a throwing detector must NOT block the store.
		// Reset captured events so we count the next half cleanly.
		$this->detected_events = array();

		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				throw new RuntimeException( 'simulated detector failure' );
			}
		);

		$result_b = $tool->execute(
			array(
				'agent_id'     => 'agent_test_seed',
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Independent fact',
					'content' => 'Some other unrelated memory.',
				),
			),
			array()
		);

		$this->assertNotEmpty( $result_b );
		if ( is_array( $result_b ) ) {
			$this->assertSame( true, $result_b['success'], 'A throwing detector must NOT block the store.' );
		}
		$this->assertCount( 0, $this->detected_events, 'No event when the candidate provider threw.' );
	}

	/* ------------------------------------------------------------------
	 * 8. Performance — top-K cap is strictly enforced
	 * ------------------------------------------------------------------ */

	/**
	 * Even when the candidate pool contains many conflicts, the detector must
	 * consider no more than the configured top-K. With `top_k = 2` and a pool
	 * of 10 conflicting records, no more than 2 events should fire.
	 */
	public function test_top_k_cap_is_strictly_enforced() {
		add_filter( 'wp_mcp_ai_memory_contradiction_top_k', static function () { return 2; } );

		add_filter(
			'wp_mcp_ai_memory_contradiction_candidates',
			static function () {
				$rows = array();
				for ( $i = 0; $i < 10; $i++ ) {
					$rows[] = array(
						'context_id' => 'ctx_perf_' . $i,
						'title'      => 'Shared title',
						'content'    => 'Token A token B token C nothing else.',
						'similarity' => 0.95,
						'metadata'   => array( 'key' => 'k', 'value' => 'v_' . $i ),
					);
				}
				return $rows;
			}
		);

		$result = WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
			array(
				'context_id' => 'ctx_perf_new',
				'agent_id'   => 'agent_1',
				'title'      => 'Shared title',
				'content'    => 'Completely different vocabulary entirely.',
				'metadata'   => array( 'key' => 'k', 'value' => 'v_new' ),
			)
		);

		$this->assertCount( 2, $result, 'Detector must cap results at top-K.' );
		$this->assertLessThanOrEqual( 2, count( $this->detected_events ) );
	}
}
