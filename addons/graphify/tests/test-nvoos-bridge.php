<?php
/**
 * NV oOS Graphify — NV oOS Bridge Tests
 *
 * Tests for the NV oOS ↔ Graphify bridge covering:
 *   - Transcript CCT label/content synthesis (JSON envelope decoding)
 *   - MemPalace wing/room/agent edge emission for ai_chat_agent_memories rows
 *   - Private CPT inclusion via nvoos_graphify_indexed_post_types
 *   - External table node ID generation
 *
 * @package NV_oOS_Graphify
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tests for NV_oOS_Graphify_NV_oOS_Bridge.
 */
class Test_NV_oOS_Graphify_NV_oOS_Bridge extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Ensure the bridge class is loaded before any test runs.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$bridge_file = __DIR__ . '/../includes/class-nvoos-graphify-nvoos-bridge.php';
		if ( ! class_exists( 'NV_oOS_Graphify_NV_oOS_Bridge' ) && file_exists( $bridge_file ) ) {
			require_once $bridge_file;
		}
	}

	// -------------------------------------------------------------------------
	// Transcript label synthesis
	// -------------------------------------------------------------------------

	/**
	 * Label should be synthesised from assistant_id + session_key + date.
	 */
	public function test_transcript_label_synthesis() {
		$item = array(
			'_ID'          => 42,
			'assistant_id' => '7',
			'session_key'  => 'abc123def456',
			'cct_created'  => '2025-01-15 09:30:00',
		);

		$label = NV_oOS_Graphify_NV_oOS_Bridge::resolve_transcript_label( '', 'ai_chat_transcripts', $item );

		$this->assertStringContainsString( 'Assistant 7', $label );
		$this->assertStringContainsString( '…23def456', $label ); // Last 8 chars of 'abc123def456'.
		$this->assertStringContainsString( '2025-01-15', $label );
	}

	/**
	 * A non-transcript slug should return the $label param unchanged.
	 */
	public function test_transcript_label_passthrough_for_other_slugs() {
		$item = array(
			'_ID'   => 1,
			'title' => 'My Memory',
		);

		$result = NV_oOS_Graphify_NV_oOS_Bridge::resolve_transcript_label( 'unchanged', 'ai_chat_agent_memories', $item );

		$this->assertSame( 'unchanged', $result );
	}

	/**
	 * Fallback label when no identifier columns are set.
	 */
	public function test_transcript_label_fallback_id() {
		$item  = array( '_ID' => 99 );
		$label = NV_oOS_Graphify_NV_oOS_Bridge::resolve_transcript_label( '', 'ai_chat_transcripts', $item );
		$this->assertStringContainsString( '99', $label );
	}

	// -------------------------------------------------------------------------
	// Transcript content decoding
	// -------------------------------------------------------------------------

	/**
	 * Content should be decoded from a standard OpenAI messages-array payload.
	 */
	public function test_transcript_content_messages_array() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello world',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hi there',
			),
		);

		$item = array(
			'_ID'             => 1,
			'request_payload' => wp_json_encode( array( 'messages' => $messages ) ),
		);

		$content = NV_oOS_Graphify_NV_oOS_Bridge::resolve_transcript_content( '', 'ai_chat_transcripts', $item );

		$this->assertStringContainsString( 'Hello world', $content );
	}

	/**
	 * Content decoding from OpenAI chat completion response shape.
	 */
	public function test_transcript_content_completion_response() {
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'I can help with that.',
					),
				),
			),
		);

		$item = array(
			'_ID'              => 2,
			'response_payload' => wp_json_encode( $response ),
		);

		$content = NV_oOS_Graphify_NV_oOS_Bridge::resolve_transcript_content( '', 'ai_chat_transcripts', $item );

		$this->assertStringContainsString( 'I can help with that.', $content );
	}

	/**
	 * Non-transcript slug passes content through unchanged.
	 */
	public function test_transcript_content_passthrough() {
		$item = array(
			'_ID'  => 1,
			'body' => 'Hello',
		);

		$result = NV_oOS_Graphify_NV_oOS_Bridge::resolve_transcript_content( 'original', 'channel_messages', $item );

		$this->assertSame( 'original', $result );
	}

	/**
	 * Empty payload columns should return empty string.
	 */
	public function test_transcript_content_empty_when_no_payload() {
		$item    = array( '_ID' => 1 );
		$content = NV_oOS_Graphify_NV_oOS_Bridge::resolve_transcript_content( '', 'ai_chat_transcripts', $item );
		$this->assertSame( '', $content );
	}

	// -------------------------------------------------------------------------
	// MemPalace wing/room/agent edges
	// -------------------------------------------------------------------------

	/**
	 * Wing + room + agent columns should produce three typed edges.
	 */
	public function test_memory_palace_edges_full() {
		if ( ! defined( 'NV_oOS_Graphify_Memory_Bridge::NODE_PREFIX_WING' ) ) {
			$this->markTestSkipped( 'NV_oOS_Graphify_Memory_Bridge constants not available' );
		}

		$item = array(
			'_ID'         => 10,
			'wing'        => 'Projects',
			'room'        => 'Alpha',
			'agent_id'    => 'assistant-7',
			'memory_tier' => 'episodic',
			'title'       => 'Memory about project Alpha',
			'content'     => 'The Alpha project started in Q1.',
		);

		$node_id = 'cct_ai_chat_agent_memories_10';
		$edges   = NV_oOS_Graphify_NV_oOS_Bridge::emit_memory_palace_edges( array(), 'ai_chat_agent_memories', $item, $node_id );

		$relations = array_column( $edges, 'relation' );

		$this->assertContains( 'MEMBER_OF', $relations, 'Wing MEMBER_OF edge expected' );
		$this->assertContains( 'CONTAINED_IN', $relations, 'Room CONTAINED_IN wing edge expected' );
		$this->assertContains( 'OBSERVED_BY', $relations, 'Agent OBSERVED_BY edge expected' );
		$this->assertContains( 'HAS_TIER', $relations, 'Tier edge expected' );
	}

	/**
	 * Memory without wing/room/agent columns should produce no edges.
	 */
	public function test_memory_palace_edges_empty_item() {
		$item  = array(
			'_ID'   => 11,
			'title' => 'Bare memory',
		);
		$edges = NV_oOS_Graphify_NV_oOS_Bridge::emit_memory_palace_edges( array(), 'ai_chat_agent_memories', $item, 'cct_ai_chat_agent_memories_11' );
		$this->assertEmpty( $edges );
	}

	/**
	 * Non-memory-palace slug should return the accumulator unchanged.
	 */
	public function test_memory_palace_edges_other_slug() {
		$item       = array(
			'_ID'  => 1,
			'wing' => 'TestWing',
		);
		$seed_edges = array(
			array(
				'relation'       => 'AUTHORED_BY',
				'source_node_id' => 'a',
				'target_node_id' => 'b',
			),
		);
		$result     = NV_oOS_Graphify_NV_oOS_Bridge::emit_memory_palace_edges( $seed_edges, 'ai_chat_transcripts', $item, 'node_1' );
		$this->assertCount( 1, $result );
	}

	// -------------------------------------------------------------------------
	// CCT label / content field filters
	// -------------------------------------------------------------------------

	/**
	 * Should return slug-specific columns for known slugs.
	 */
	public function test_cct_label_fields_known_slug() {
		$result = NV_oOS_Graphify_NV_oOS_Bridge::filter_cct_label_fields( array( 'title' ), 'vitals_log', array() );
		$this->assertContains( 'measurement_type', $result );
	}

	/**
	 * Should pass through unchanged for unknown slugs.
	 */
	public function test_cct_label_fields_unknown_slug() {
		$defaults = array( 'title', 'name' );
		$result   = NV_oOS_Graphify_NV_oOS_Bridge::filter_cct_label_fields( $defaults, 'some_other_cct', array() );
		$this->assertSame( $defaults, $result );
	}

	// -------------------------------------------------------------------------
	// External table node ID helper
	// -------------------------------------------------------------------------

	/**
	 * Should combine node_type and primary key.
	 */
	public function test_external_node_id() {
		$id = NV_oOS_Graphify_Detector::external_node_id( 'ext_slash_cmd_audit', 42 );
		$this->assertSame( 'ext_slash_cmd_audit_42', $id );
	}

	/**
	 * Should sanitize the type string.
	 */
	public function test_external_node_id_sanitises_type() {
		$id = NV_oOS_Graphify_Detector::external_node_id( 'Ext Slash Audit!', 1 );
		// sanitize_key converts to lowercase, strips non-alphanumeric except underscores/hyphens.
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+_1$/', $id );
	}

	// -------------------------------------------------------------------------
	// CPT registry
	// -------------------------------------------------------------------------

	/**
	 * CPT registry should include all base-plugin CPTs.
	 */
	public function test_cpt_registry_contains_base_cpts() {
		$registry = NV_oOS_Graphify_NV_oOS_Bridge::get_cpt_registry();
		$slugs    = array_column( $registry, 'slug' );
		$this->assertContains( 'mcp_ai_assistant', $slugs );
		$this->assertContains( 'mcp_ai_workflow_run', $slugs );
		$this->assertContains( 'mcp_ai_approval', $slugs );
		$this->assertContains( 'mcp_ai_audit', $slugs );
	}

	/**
	 * Default-off CPTs should not be auto-added to the indexed list.
	 */
	public function test_filter_indexed_post_types_excludes_default_off() {
		// Remove any existing settings filters.
		$s = NV_oOS_Graphify::get_settings();

		$post_types = NV_oOS_Graphify_NV_oOS_Bridge::filter_indexed_post_types( array( 'post', 'page' ) );
		$this->assertNotContains( 'mcp_ai_approval', $post_types, 'HITL approval CPT is sensitive and should not be auto-indexed' );
		$this->assertNotContains( 'mcp_ai_audit', $post_types, 'Audit CPT is sensitive and should not be auto-indexed' );
	}

	/**
	 * Default-on CPTs should be added to the indexed list.
	 */
	public function test_filter_indexed_post_types_includes_default_on() {
		$post_types = NV_oOS_Graphify_NV_oOS_Bridge::filter_indexed_post_types( array( 'post', 'page' ) );
		$this->assertContains( 'mcp_ai_assistant', $post_types );
		$this->assertContains( 'mcp_ai_workflow_run', $post_types );
	}
}
