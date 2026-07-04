<?php
/**
 * Tests for the Conversation RAG Bridge.
 *
 * @package WP_MCP_AI
 */

/**
 * Test conversation RAG bridge functionality.
 */
class Test_Conversation_RAG_Bridge extends WP_UnitTestCase {

	/**
	 * RAG bridge instance.
	 *
	 * @var WP_MCP_AI_Conversation_RAG_Bridge
	 */
	protected $rag_bridge;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Conversation_RAG_Bridge' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-conversation-rag-bridge.php';
		}

		$this->rag_bridge = new WP_MCP_AI_Conversation_RAG_Bridge();
	}

	/**
	 * Test that the RAG bridge class exists and is instantiable.
	 */
	public function test_rag_bridge_exists() {
		$this->assertInstanceOf( WP_MCP_AI_Conversation_RAG_Bridge::class, $this->rag_bridge );
	}

	/**
	 * Test build_rag_context_message with empty memories.
	 */
	public function test_build_rag_context_message_empty() {
		$result = $this->rag_bridge->build_rag_context_message( array() );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test build_rag_context_message with valid memories.
	 */
	public function test_build_rag_context_message_with_memories() {
		$memories = array(
			array(
				'content'    => 'The user prefers dark mode for all interfaces.',
				'source'     => 'chat_memory',
				'importance' => 0.8,
			),
			array(
				'content'    => 'Project deadline is set for July 15th.',
				'source'     => 'paper_store',
				'importance' => 0.9,
			),
		);

		$result = $this->rag_bridge->build_rag_context_message( $memories );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'role', $result );
		$this->assertSame( 'user', $result['role'] );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertStringContainsString( 'Retrieved memories', $result['content'] );
		$this->assertStringContainsString( 'dark mode', $result['content'] );
		$this->assertStringContainsString( 'July 15th', $result['content'] );
	}

	/**
	 * Test apply_memory_decay with fresh memories.
	 */
	public function test_memory_decay_fresh() {
		$memories = array(
			array(
				'content'    => 'Fresh memory.',
				'importance' => 0.8,
				'stored_at'  => gmdate( 'c' ), // Now.
			),
		);

		$result = $this->rag_bridge->apply_memory_decay( $memories, 0.1 );

		$this->assertCount( 1, $result );
		$this->assertEquals( 0.8, $result[0]['importance'] );
	}

	/**
	 * Test apply_memory_decay with week-old memories (25% decay).
	 */
	public function test_memory_decay_week_old() {
		$memories = array(
			array(
				'content'    => 'Week-old memory.',
				'importance' => 0.8,
				'stored_at'  => gmdate( 'c', strtotime( '-8 days' ) ),
			),
		);

		$result = $this->rag_bridge->apply_memory_decay( $memories, 0.1 );

		$this->assertCount( 1, $result );
		$this->assertEqualsWithDelta( 0.6, $result[0]['importance'], 0.01 );
	}

	/**
	 * Test apply_memory_decay with month-old memories (50% decay).
	 */
	public function test_memory_decay_month_old() {
		$memories = array(
			array(
				'content'    => 'Month-old memory.',
				'importance' => 0.8,
				'stored_at'  => gmdate( 'c', strtotime( '-31 days' ) ),
			),
		);

		$result = $this->rag_bridge->apply_memory_decay( $memories, 0.1 );

		$this->assertCount( 1, $result );
		$this->assertEqualsWithDelta( 0.4, $result[0]['importance'], 0.01 );
	}

	/**
	 * Test apply_memory_decay filters below threshold.
	 */
	public function test_memory_decay_filters_below_threshold() {
		$memories = array(
			array(
				'content'    => 'Low importance memory.',
				'importance' => 0.2,
				'stored_at'  => gmdate( 'c' ),
			),
			array(
				'content'    => 'High importance memory.',
				'importance' => 0.9,
				'stored_at'  => gmdate( 'c' ),
			),
		);

		$result = $this->rag_bridge->apply_memory_decay( $memories, 0.5 );

		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'High importance', $result[0]['content'] );
	}

	/**
	 * Test retrieve_relevant_memories returns empty when no memory systems available.
	 */
	public function test_retrieve_relevant_memories_empty() {
		$result = $this->rag_bridge->retrieve_relevant_memories(
			'What is the weather?',
			array( 'assistant_id' => 999 )
		);

		$this->assertIsArray( $result );
		// In a test environment without Paper Store or chat-memory, should be empty.
		$this->assertCount( 0, $result );
	}

	/**
	 * Test bme_rag strategy is available in settings.
	 */
	public function test_bme_rag_strategy_available() {
		$settings = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		$this->assertArrayHasKey( 'context_strategy', $settings );
		$this->assertSame( 'sliding_window', $settings['context_strategy'] );
	}

	/**
	 * Test inject_rag_context method exists on REST class.
	 */
	public function test_rest_has_inject_rag_context_method() {
		$this->assertTrue(
			method_exists( WP_MCP_AI_REST::class, 'inject_rag_context' ),
			'REST class should have inject_rag_context method'
		);
	}
}
