<?php
/**
 * Tests for WP_MCP_AI_Session_Distiller — session-end memory distillation.
 *
 * @package WP_MCP_AI
 * @since   1.1.97
 */

/**
 * Session distiller test suite.
 */
class Test_Session_Distiller extends WP_UnitTestCase {

	/**
	 * Captured memory events.
	 *
	 * @var array
	 */
	public $events = array();

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-session-distiller.php';

		$this->events = array();
		add_action( 'wp_mcp_ai_memory_stored', array( $this, 'spy_on_memory_stored' ), 99, 1 );

		delete_option( WP_MCP_AI_Session_Distiller::OPTION_SUMMARIES );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_action( 'wp_mcp_ai_memory_stored', array( $this, 'spy_on_memory_stored' ), 99 );

		remove_all_filters( 'wp_mcp_ai_session_distill_enabled' );
		remove_all_filters( 'wp_mcp_ai_session_distill_min_messages' );
		remove_all_filters( 'wp_mcp_ai_session_distill_summarizer' );
		remove_all_filters( 'wp_mcp_ai_session_summaries_max' );

		delete_option( WP_MCP_AI_Session_Distiller::OPTION_SUMMARIES );

		parent::tearDown();
	}

	/**
	 * Spy callback for the memory event.
	 *
	 * @param array $event Memory event payload.
	 * @return void
	 */
	public function spy_on_memory_stored( $event ) {
		$this->events[] = $event;
	}

	/**
	 * Build a session message list with tool calls.
	 *
	 * @param int $count Message count.
	 * @return array
	 */
	private function messages( $count = 6 ) {
		$messages = array();

		for ( $i = 0; $i < $count; $i++ ) {
			if ( 0 === $i % 2 ) {
				$messages[] = array(
					'role'    => 'user',
					'content' => 'User question number ' . ( $i + 1 ) . ' about the store.',
				);
			} elseif ( 1 === $i ) {
				$messages[] = array(
					'role'       => 'assistant',
					'content'    => 'Checking the product catalogue.',
					'tool_calls' => array(
						array(
							'id'       => 'call_1',
							'function' => array( 'name' => 'get_products' ),
						),
						array(
							'id'       => 'call_2',
							'function' => array( 'name' => 'get_orders' ),
						),
					),
				);
			} else {
				$messages[] = array(
					'role'    => 'assistant',
					'content' => 'Assistant answer number ' . ( $i + 1 ),
				);
			}
		}

		return $messages;
	}

	/**
	 * Bootstrap registers the transcript handler.
	 */
	public function test_bootstrap_registers_transcript_handler() {
		$this->assertSame( 20, has_action( 'wp_mcp_ai_chat_transcript_recorded', array( 'WP_MCP_AI_Session_Distiller', 'on_transcript_recorded' ) ) );
	}

	/**
	 * Distillation is opt-in: disabled by default.
	 */
	public function test_disabled_by_default() {
		do_action(
			'wp_mcp_ai_chat_transcript_recorded',
			'session-off',
			42,
			7,
			$this->messages(),
			'All done.',
			'gpt-4o',
			'openai'
		);

		$this->assertCount( 0, $this->events );
		$this->assertFalse( get_option( WP_MCP_AI_Session_Distiller::OPTION_SUMMARIES, false ) );
	}

	/**
	 * An enabled session emits the canonical memory event with the
	 * session/episodic shape and records the fallback option.
	 */
	public function test_enabled_distills_session() {
		add_filter( 'wp_mcp_ai_session_distill_enabled', '__return_true' );

		do_action(
			'wp_mcp_ai_chat_transcript_recorded',
			'session-1',
			42,
			7,
			$this->messages(),
			'All done: two products found.',
			'gpt-4o',
			'openai'
		);

		$this->assertCount( 1, $this->events );

		$event = $this->events[0];
		$this->assertSame( 'session', $event['context_type'] );
		$this->assertSame( 'episodic', $event['memory_tier'] );
		$this->assertSame( 'session', $event['wing'] );
		$this->assertSame( '42', $event['room'] );
		$this->assertSame( '42', $event['agent_id'] );
		$this->assertSame( 'session_distiller', $event['source'] );
		$this->assertStringStartsWith( 'session_', $event['context_id'] );
		$this->assertStringContainsString( 'Tool calls: 2', $event['content'] );

		$summaries = get_option( WP_MCP_AI_Session_Distiller::OPTION_SUMMARIES, array() );
		$this->assertArrayHasKey( 'session-1', $summaries );
		$this->assertSame( 42, $summaries['session-1']['assistant_id'] );
	}

	/**
	 * Short sessions under the minimum message count are skipped.
	 */
	public function test_min_messages_gate() {
		add_filter( 'wp_mcp_ai_session_distill_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_session_distill_min_messages',
			function () {
				return 6;
			}
		);

		do_action(
			'wp_mcp_ai_chat_transcript_recorded',
			'session-short',
			42,
			7,
			$this->messages( 3 ),
			'Done.',
			'gpt-4o',
			'openai'
		);

		$this->assertCount( 0, $this->events );
	}

	/**
	 * A session key is distilled only once within the dedupe window.
	 */
	public function test_dedupe_one_distillation_per_session() {
		add_filter( 'wp_mcp_ai_session_distill_enabled', '__return_true' );

		do_action( 'wp_mcp_ai_chat_transcript_recorded', 'session-dup', 42, 7, $this->messages(), 'First.', 'gpt-4o', 'openai' );
		do_action( 'wp_mcp_ai_chat_transcript_recorded', 'session-dup', 42, 7, $this->messages(), 'Second.', 'gpt-4o', 'openai' );

		$this->assertCount( 1, $this->events );
	}

	/**
	 * The summarizer filter replaces the extractive default.
	 */
	public function test_summarizer_filter_overrides_content() {
		add_filter( 'wp_mcp_ai_session_distill_enabled', '__return_true' );
		add_filter(
			'wp_mcp_ai_session_distill_summarizer',
			function () {
				return 'Custom LLM summary.';
			},
			10,
			5
		);

		do_action( 'wp_mcp_ai_chat_transcript_recorded', 'session-custom', 42, 7, $this->messages(), 'Done.', 'gpt-4o', 'openai' );

		$this->assertCount( 1, $this->events );
		$this->assertSame( 'Custom LLM summary.', $this->events[0]['content'] );
	}

	/**
	 * The legacy dual-shape emitter (array first argument) is tolerated.
	 */
	public function test_legacy_array_shape_does_not_fatal() {
		add_filter( 'wp_mcp_ai_session_distill_enabled', '__return_true' );

		WP_MCP_AI_Session_Distiller::on_transcript_recorded( array( 'messages' => array() ), array() );

		$this->assertCount( 0, $this->events );
	}

	/**
	 * Extractive summaries capture intents, outcome, and tool-call counts.
	 */
	public function test_build_extractive_summary() {
		$summary = WP_MCP_AI_Session_Distiller::build_extractive_summary(
			$this->messages(),
			array(
				array(
					'type' => 'text',
					'text' => 'Final answer segment.',
				),
			)
		);

		$this->assertStringContainsString( 'User intents:', $summary );
		$this->assertStringContainsString( 'User question number 1', $summary );
		$this->assertStringContainsString( 'Outcome: Final answer segment.', $summary );
		$this->assertStringContainsString( 'Tool calls: 2', $summary );
	}

	/**
	 * Segment-shaped content flattens to a string; scalars pass through.
	 */
	public function test_flatten_content() {
		$this->assertSame( 'plain', WP_MCP_AI_Session_Distiller::flatten_content( 'plain' ) );
		$this->assertSame(
			"one\ntwo",
			WP_MCP_AI_Session_Distiller::flatten_content(
				array(
					array(
						'type' => 'text',
						'text' => 'one',
					),
					array(
						'type' => 'text',
						'text' => 'two',
					),
				)
			)
		);
		$this->assertSame( '', WP_MCP_AI_Session_Distiller::flatten_content( null ) );
	}
}
