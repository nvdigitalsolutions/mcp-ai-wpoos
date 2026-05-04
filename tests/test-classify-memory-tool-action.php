<?php
/**
 * Tests for WP_MCP_AI_REST::classify_memory_tool_action() — G8 Phase 2.
 *
 * Mid-stream `memory_event` SSE frames are gated by this helper, which
 * mirrors the JS lists in `assets/js/chat-memory-drawer.js`. Drift between
 * the PHP and JS lists would break the toast/badge UX, so we test each
 * supported tool name explicitly.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class.
 */
class Test_Classify_Memory_Tool_Action extends WP_UnitTestCase {

	/**
	 * Reflection-friendly invoker for the protected helper.
	 *
	 * @param string $tool_name Tool function name.
	 * @return string|null
	 */
	private function classify( $tool_name ) {
		$rest   = new WP_MCP_AI_REST();
		$method = new ReflectionMethod( $rest, 'classify_memory_tool_action' );
		$method->setAccessible( true );
		return $method->invoke( $rest, $tool_name );
	}

	/**
	 * @dataProvider provide_retrieve_tools
	 *
	 * @param string $tool_name Tool function name.
	 */
	public function test_retrieve_tools_classify_as_retrieved( $tool_name ) {
		$this->assertSame( 'retrieved', $this->classify( $tool_name ) );
	}

	/**
	 * Data provider for retrieve tools.
	 *
	 * @return array
	 */
	public function provide_retrieve_tools() {
		return array(
			array( 'recall_memory' ),
			array( 'wake_up_context' ),
			array( 'semantic_context_search' ),
			array( 'retrieve_agent_memory' ),
		);
	}

	/**
	 * @dataProvider provide_store_tools
	 *
	 * @param string $tool_name Tool function name.
	 */
	public function test_store_tools_classify_as_stored( $tool_name ) {
		$this->assertSame( 'stored', $this->classify( $tool_name ) );
	}

	/**
	 * Data provider for store tools.
	 *
	 * @return array
	 */
	public function provide_store_tools() {
		return array(
			array( 'store_agent_context' ),
			array( 'update_agent_memory' ),
			array( 'capture_memory' ),
		);
	}

	/**
	 * Non-memory tool names (and bad input) must classify as null so no
	 * `memory_event` SSE frame is emitted.
	 *
	 * @dataProvider provide_non_memory_inputs
	 *
	 * @param mixed $input Tool name or invalid input.
	 */
	public function test_non_memory_tools_return_null( $input ) {
		$this->assertNull( $this->classify( $input ) );
	}

	/**
	 * Data provider.
	 *
	 * @return array
	 */
	public function provide_non_memory_inputs() {
		return array(
			'unrelated tool' => array( 'create_post' ),
			'empty string'   => array( '' ),
			'null'           => array( null ),
			'integer'        => array( 42 ),
			'array'          => array( array( 'recall_memory' ) ),
			'similar prefix' => array( 'recall_memory_v2' ),
		);
	}
}
