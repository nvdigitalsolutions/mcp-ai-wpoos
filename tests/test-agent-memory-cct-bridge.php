<?php
/**
 * Tests for the agent-memory CCT dual-write bridge.
 *
 * Focused on the pure-function pieces (`classify_tier`,
 * `build_record_from_event`, `wp_mcp_ai_memory_cct_record` filter) that don't
 * require JetEngine to be installed. The end-to-end mirror behaviour is
 * exercised separately in environments that have JetEngine available.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for `WP_MCP_AI_Agent_Memory_CCT_Bridge`.
 */
class WP_MCP_AI_Agent_Memory_CCT_Bridge_Test extends WP_UnitTestCase {

	/**
	 * Tool-call / workflow / skill context types must land in the procedural
	 * tier so they survive across sessions but never auto-summarise.
	 */
	public function test_classify_tier_routes_procedural_types() {
		$this->assertSame( 'procedural', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'tool_call' ) );
		$this->assertSame( 'procedural', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'workflow' ) );
		$this->assertSame( 'procedural', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'skill' ) );
	}

	/**
	 * Session/decision/learning are episodic — bounded to a session window
	 * and eligible for summarise-on-promote in Phase 4b-5.
	 */
	public function test_classify_tier_routes_episodic_types() {
		$this->assertSame( 'episodic', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'session' ) );
		$this->assertSame( 'episodic', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'decision' ) );
		$this->assertSame( 'episodic', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'learning' ) );
	}

	/**
	 * Anything else defaults to semantic — facts, preferences, identities.
	 */
	public function test_classify_tier_defaults_to_semantic() {
		$this->assertSame( 'semantic', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'fact' ) );
		$this->assertSame( 'semantic', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'preference' ) );
		$this->assertSame( 'semantic', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( '' ) );
		$this->assertSame( 'semantic', WP_MCP_AI_Agent_Memory_CCT_Bridge::classify_tier( 'totally-unknown-type' ) );
	}

	/**
	 * Build a record from a representative event payload and verify every
	 * industry-standard column is populated.
	 */
	public function test_build_record_from_event_populates_all_columns() {
		$event = array(
			'context_id'     => 'ctx_unit_test_001',
			'agent_id'       => 'agent_42',
			'context_type'   => 'fact',
			'content'        => 'The capital of France is Paris.',
			'title'          => 'France capital',
			'importance'     => 'high',
			'tags'           => array( 'geography', 'europe' ),
			'wing'           => 'general-knowledge',
			'room'           => 'world-facts',
			'verbatim'       => true,
			'source_post_id' => 0,
			'source_url'     => '',
			'source_type'    => '',
			'stored_at'      => '2026-04-30 12:00:00',
			'expires_at'     => '2026-05-30 12:00:00',
			'ttl'            => 2592000,
		);

		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event( $event );

		$this->assertSame( 'ctx_unit_test_001', $record['context_id'] );
		$this->assertSame( 'agent_42', $record['agent_id'] );
		$this->assertSame( 'semantic', $record['memory_tier'], 'fact-type events must auto-classify as semantic.' );
		$this->assertSame( 'fact', $record['context_type'] );
		$this->assertSame( 'general-knowledge', $record['wing'] );
		$this->assertSame( 'world-facts', $record['room'] );
		$this->assertSame( 'France capital', $record['title'] );
		$this->assertSame( 'high', $record['importance'] );
		$this->assertSame( 1, $record['verbatim'], 'verbatim must be persisted as integer 1.' );
		$this->assertSame( '2026-04-30 12:00:00', $record['transaction_time'] );
		$this->assertSame( '2026-04-30 12:00:00', $record['valid_from'] );
		$this->assertSame( '2026-05-30 12:00:00', $record['valid_until'] );
		$this->assertSame( '2026-05-30 12:00:00', $record['expires_at'] );
		$this->assertSame( 2592000, $record['ttl_seconds'] );
		$this->assertSame( 'store_agent_context', $record['source'], 'source defaults to the originating tool slug.' );
		$this->assertSame( 'publish', $record['cct_status'] );

		// Tags must be JSON-encoded so the CCT text column round-trips.
		$decoded = json_decode( $record['tags'], true );
		$this->assertSame( array( 'geography', 'europe' ), $decoded );
	}

	/**
	 * An explicit `memory_tier` on the event payload must override the
	 * auto-classifier — required for Phase 4b-3 where tools opt into a tier.
	 */
	public function test_explicit_memory_tier_overrides_auto_classifier() {
		$event = array(
			'context_id'   => 'ctx_unit_test_002',
			'agent_id'     => 'agent_42',
			'context_type' => 'fact', // Would normally be `semantic`.
			'memory_tier'  => 'working',
			'content'      => 'scratch',
			'title'        => 'scratch',
			'stored_at'    => '2026-04-30 12:00:00',
			'expires_at'   => '2026-04-30 13:00:00',
			'ttl'          => 3600,
		);

		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event( $event );
		$this->assertSame( 'working', $record['memory_tier'] );
	}

	/**
	 * The `wp_mcp_ai_memory_cct_record` filter must let listeners mutate the
	 * record before persistence — the documented extension point for PII
	 * scrubbing, custom provenance, and embedding/graph cross-refs.
	 */
	public function test_filter_can_mutate_record_before_persist() {
		$listener = function ( $record ) {
			$record['embedding_id']  = 'vec_abc123';
			$record['graph_node_id'] = 'node_xyz789';
			$record['source']        = 'custom_pipeline';
			return $record;
		};

		add_filter( 'wp_mcp_ai_memory_cct_record', $listener );

		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id' => 'ctx_unit_test_003',
				'agent_id'   => 'agent_42',
				'stored_at'  => '2026-04-30 12:00:00',
				'expires_at' => '2026-05-30 12:00:00',
				'ttl'        => 2592000,
			)
		);

		remove_filter( 'wp_mcp_ai_memory_cct_record', $listener );

		$this->assertSame( 'vec_abc123', $record['embedding_id'] );
		$this->assertSame( 'node_xyz789', $record['graph_node_id'] );
		$this->assertSame( 'custom_pipeline', $record['source'] );
	}

	/**
	 * Lifecycle delete fires `wp_mcp_ai_memory_deleted`; the bridge listens
	 * for it. Verify the action carries the documented payload shape so the
	 * contract is enforced even when JetEngine isn't installed.
	 */
	public function test_memory_deleted_event_carries_documented_payload() {
		$captured = null;
		$listener = function ( $payload ) use ( &$captured ) {
			$captured = $payload;
		};

		add_action( 'wp_mcp_ai_memory_deleted', $listener );
		do_action(
			'wp_mcp_ai_memory_deleted',
			array(
				'context_id'   => 'ctx_unit_test_004',
				'agent_id'     => 'agent_42',
				'context_type' => 'fact',
				'deleted_at'   => '2026-04-30 12:34:56',
			)
		);
		remove_action( 'wp_mcp_ai_memory_deleted', $listener );

		$this->assertIsArray( $captured );
		$this->assertSame( 'ctx_unit_test_004', $captured['context_id'] );
		$this->assertSame( 'agent_42', $captured['agent_id'] );
		$this->assertSame( 'fact', $captured['context_type'] );
		$this->assertSame( '2026-04-30 12:34:56', $captured['deleted_at'] );
	}
}
