<?php
/**
 * Tests for the JetEngine agent-memories Custom Content Type registration.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the durable agent memory CCT.
 */
class WP_MCP_AI_JetEngine_Agent_Memories_CCT_Test extends WP_UnitTestCase {

	/**
	 * The slug must match the public, documented value so the dashboard
	 * widget, bridge, and external integrations can hard-code it.
	 */
	public function test_get_slug_returns_public_value() {
		$this->assertSame( 'ai_agent_memories', WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug() );
	}

	/**
	 * Registration request payload exposes the four keys JetEngine requires.
	 */
	public function test_registration_request_structure() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Agent_Memories_CCT::class, 'get_registration_request' );
		$reflection->setAccessible( true );
		$request = $reflection->invoke( null );

		$this->assertIsArray( $request );
		$this->assertArrayHasKey( 'name', $request );
		$this->assertArrayHasKey( 'slug', $request );
		$this->assertArrayHasKey( 'args', $request );
		$this->assertArrayHasKey( 'meta_fields', $request );
		$this->assertSame( 'ai_agent_memories', $request['slug'] );
	}

	/**
	 * The CCT must lock REST writes to manage_options because the verbatim
	 * discipline and `wp_mcp_ai_memory_pre_store_transform` filter only run
	 * through the tool entry-points; raw REST writes would bypass them.
	 */
	public function test_cct_args_disable_external_rest_writes() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Agent_Memories_CCT::class, 'get_cct_args' );
		$reflection->setAccessible( true );
		$args = $reflection->invoke( null, 'AI Agent Memories' );

		$this->assertIsArray( $args );
		$this->assertSame( 'ai_agent_memories', $args['slug'] );
		$this->assertSame( 'manage_options', $args['capability'] );
		$this->assertTrue( $args['rest_get_enabled'] );
		$this->assertFalse( $args['rest_post_enabled'] );
		$this->assertFalse( $args['rest_put_enabled'] );
		$this->assertFalse( $args['rest_delete_enabled'] );
	}

	/**
	 * The schema must cover every column the bridge writes; missing any one
	 * silently drops industry-standard provenance/temporal data.
	 */
	public function test_meta_fields_cover_industry_schema() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Agent_Memories_CCT::class, 'get_meta_fields' );
		$reflection->setAccessible( true );
		$fields      = $reflection->invoke( null );
		$field_names = array_column( $fields, 'name' );

		// Identity (mem0/Letta).
		$this->assertContains( 'context_id', $field_names );
		$this->assertContains( 'agent_id', $field_names );
		// Tiering (Letta/Cognee).
		$this->assertContains( 'memory_tier', $field_names );
		$this->assertContains( 'context_type', $field_names );
		// Hierarchical scope (Phase 4a + Cognee).
		$this->assertContains( 'wing', $field_names );
		$this->assertContains( 'room', $field_names );
		// Content + retrieval metadata.
		$this->assertContains( 'title', $field_names );
		$this->assertContains( 'content', $field_names );
		$this->assertContains( 'tags', $field_names );
		$this->assertContains( 'importance', $field_names );
		$this->assertContains( 'verbatim', $field_names );
		// Bi-temporal validity (Zep).
		$this->assertContains( 'transaction_time', $field_names );
		$this->assertContains( 'valid_from', $field_names );
		$this->assertContains( 'valid_until', $field_names );
		$this->assertContains( 'expires_at', $field_names );
		$this->assertContains( 'ttl_seconds', $field_names );
		// Provenance (mem0/Letta).
		$this->assertContains( 'source', $field_names );
		$this->assertContains( 'source_post_id', $field_names );
		$this->assertContains( 'source_url', $field_names );
		$this->assertContains( 'source_type', $field_names );
		// Forward-compatibility hooks for Phase 4c (vector + graph refs).
		$this->assertContains( 'embedding_id', $field_names );
		$this->assertContains( 'graph_node_id', $field_names );
		// Auxiliary.
		$this->assertContains( 'metadata', $field_names );

		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'show_in_rest', $field );
			$this->assertTrue( $field['show_in_rest'], "Field {$field['name']} must enable show_in_rest." );
		}
	}

	/**
	 * `context_id` and `agent_id` are required because the bridge keys lookups
	 * and dedupe on them; defaulting them to empty would break mirror writes.
	 */
	public function test_required_identity_fields_are_marked_required() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Agent_Memories_CCT::class, 'get_meta_fields' );
		$reflection->setAccessible( true );
		$fields = $reflection->invoke( null );

		$by_name = array();
		foreach ( $fields as $field ) {
			$by_name[ $field['name'] ] = $field;
		}

		$this->assertArrayHasKey( 'context_id', $by_name );
		$this->assertArrayHasKey( 'agent_id', $by_name );
		$this->assertTrue( ! empty( $by_name['context_id']['is_required'] ) );
		$this->assertTrue( ! empty( $by_name['agent_id']['is_required'] ) );
	}

	/**
	 * Field IDs must live in the 30000+ range so they don't collide with the
	 * transcripts (10000), assistants (20000), peers (40000), or submissions
	 * (50000) CCTs already shipped by the plugin.
	 */
	public function test_field_ids_avoid_existing_ranges() {
		$reflection = new ReflectionMethod( WP_MCP_AI_JetEngine_Agent_Memories_CCT::class, 'get_meta_fields' );
		$reflection->setAccessible( true );
		$fields = $reflection->invoke( null );

		foreach ( $fields as $field ) {
			$this->assertGreaterThanOrEqual( 30001, (int) $field['id'], "Field {$field['name']} id should be >= 30001." );
			$this->assertLessThan( 40000, (int) $field['id'], "Field {$field['name']} id should be < 40000." );
		}
	}
}
