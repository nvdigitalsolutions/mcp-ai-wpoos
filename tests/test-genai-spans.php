<?php
/**
 * Tests for the GenAI span emitter (Proposal 091, Track E).
 *
 * @package WP_MCP_AI
 */

/**
 * GenAI span emitter test case.
 */
class Test_GenAI_Spans extends WP_UnitTestCase {

	/**
	 * Captured span payloads (collected by the test subscriber).
	 *
	 * @var array[]
	 */
	private static $captured = array();

	/**
	 * Subscriber that records payloads.
	 *
	 * @param array $payload Span payload.
	 * @return void
	 */
	public static function capture( $payload ) {
		self::$captured[] = $payload;
	}

	/**
	 * Reset captured payloads between tests.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		self::$captured = array();
		remove_all_filters( WP_MCP_AI_GenAI_Span_Emitter::SPAN_HOOK );
	}

	/**
	 * Emitting without a subscriber is a no-op returning null.
	 *
	 * @return void
	 */
	public function test_emit_noop_without_subscriber() {
		$this->assertNull( WP_MCP_AI_GenAI_Span_Emitter::emit( 'chat' ) );
		$this->assertEmpty( self::$captured );
	}

	/**
	 * Emitted payloads use OTel GenAI convention attribute names.
	 *
	 * @return void
	 */
	public function test_emit_uses_genai_convention_names() {
		add_action( WP_MCP_AI_GenAI_Span_Emitter::SPAN_HOOK, array( __CLASS__, 'capture' ) );

		$payload = WP_MCP_AI_GenAI_Span_Emitter::emit(
			'chat',
			array(
				'model'         => 'gemini-3.6-flash',
				'input_tokens'  => 120,
				'output_tokens' => 45,
				'finish_reason' => 'stop',
				'assistant_id'  => 7,
				'transcript_id' => 9,
				'provider'      => 'gemini',
			)
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'chat', $payload['gen_ai.operation.name'] );
		$this->assertSame( 'gemini-3.6-flash', $payload['gen_ai.request.model'] );
		$this->assertSame( 120, $payload['gen_ai.usage.input_tokens'] );
		$this->assertSame( 45, $payload['gen_ai.usage.output_tokens'] );
		$this->assertSame( 'stop', $payload['gen_ai.response.finish_reasons'] );
		$this->assertSame( 7, $payload['nvoos.assistant_id'] );
		$this->assertCount( 1, self::$captured );
	}

	/**
	 * Tool spans carry the tool slug extension attribute.
	 *
	 * @return void
	 */
	public function test_emit_tool_span_carries_tool_slug() {
		add_action( WP_MCP_AI_GenAI_Span_Emitter::SPAN_HOOK, array( __CLASS__, 'capture' ) );

		$payload = WP_MCP_AI_GenAI_Span_Emitter::emit( 'execute_tool', array( 'tool_slug' => 'web_search_validated' ) );

		$this->assertSame( 'execute_tool', $payload['gen_ai.operation.name'] );
		$this->assertSame( 'web_search_validated', $payload['nvoos.tool_slug'] );
	}
}
