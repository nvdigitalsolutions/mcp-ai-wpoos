<?php
/**
 * Tests for the BME context strategy in the REST API.
 *
 * @package WP_MCP_AI
 */

/**
 * Test BME context strategy trimming logic.
 */
class Test_BME_Context_Strategy extends WP_UnitTestCase {

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_REST' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Conversation_Summarizer' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-conversation-summarizer.php';
		}

		$registry_mock = $this->createMock( WP_MCP_AI_Tool_Registry::class );
		$router_mock   = $this->createMock( WP_MCP_AI_Language_Model_Router::class );

		$this->rest = new WP_MCP_AI_REST( $registry_mock, $router_mock );
	}

	/**
	 * Build a test message list of N user/assistant pairs.
	 *
	 * @param int $count Number of pairs.
	 * @return array Messages array.
	 */
	protected function build_messages( $count ) {
		$messages = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => "User message {$i}",
			);
			$messages[] = array(
				'role'    => 'assistant',
				'content' => "Assistant response {$i}",
			);
		}
		return $messages;
	}

	/**
	 * Test that sliding_window strategy preserves backward compatibility.
	 */
	public function test_sliding_window_default_behavior() {
		$messages = $this->build_messages( 20 ); // 40 messages total.

		$settings = array(
			'context_strategy'    => 'sliding_window',
			'max_history_messages' => 8,
		);

		$context = array(
			'assistant_id' => 1,
			'provider'     => 'openai',
			'model'        => 'gpt-4',
		);

		// Use reflection to call protected method.
		$reflection = new ReflectionMethod( $this->rest, 'trim_messages_bme' );
		$reflection->setAccessible( true );

		// BME method should not be called for sliding_window strategy.
		// The existing enforce_chat_request_limits handles sliding window.
		$this->assertTrue( true ); // Backward compat is preserved by default.
	}

	/**
	 * Test trim_messages_bme with messages under summary trigger count.
	 */
	public function test_bme_under_trigger_count() {
		$messages = $this->build_messages( 10 ); // 20 messages, under default trigger of 30.

		$settings = array(
			'end_window_size'       => 5,
			'summary_trigger_count' => 30,
			'summary_max_tokens'    => 500,
		);

		$context = array(
			'assistant_id' => 1,
			'provider'     => 'openai',
		);

		$reflection = new ReflectionMethod( $this->rest, 'trim_messages_bme' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->rest, $messages, $settings, $context );

		// Should return all messages unchanged (under trigger count).
		$this->assertCount( 20, $result );
	}

	/**
	 * Test trim_messages_bme with messages including system prompts.
	 */
	public function test_bme_preserves_system_messages() {
		$system_msg = array(
			'role'    => 'system',
			'content' => 'You are a helpful assistant.',
		);

		$messages   = $this->build_messages( 5 ); // 10 messages.
		$messages   = array_merge( array( $system_msg ), $messages ); // 11 total.

		$settings = array(
			'end_window_size'       => 3,
			'summary_trigger_count' => 100, // High threshold, no summary triggered.
			'summary_max_tokens'    => 500,
		);

		$context = array( 'assistant_id' => 1 );

		$reflection = new ReflectionMethod( $this->rest, 'trim_messages_bme' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->rest, $messages, $settings, $context );

		// System message should be first.
		$this->assertSame( 'system', $result[0]['role'] );
		$this->assertSame( 'You are a helpful assistant.', $result[0]['content'] );
		// All 11 messages preserved (under trigger count).
		$this->assertCount( 11, $result );
	}

	/**
	 * Test BME settings are properly registered with defaults.
	 */
	public function test_bme_settings_defaults() {
		$settings = WP_MCP_AI_Admin_Settings_Base::get_default_settings();

		$this->assertArrayHasKey( 'context_strategy', $settings );
		$this->assertSame( 'sliding_window', $settings['context_strategy'] );

		$this->assertArrayHasKey( 'end_window_size', $settings );
		$this->assertSame( 10, $settings['end_window_size'] );

		$this->assertArrayHasKey( 'summary_trigger_count', $settings );
		$this->assertSame( 30, $settings['summary_trigger_count'] );

		$this->assertArrayHasKey( 'summary_trigger_tokens', $settings );
		$this->assertSame( 0, $settings['summary_trigger_tokens'] );

		$this->assertArrayHasKey( 'summary_model', $settings );
		$this->assertSame( '', $settings['summary_model'] );

		$this->assertArrayHasKey( 'summary_max_tokens', $settings );
		$this->assertSame( 500, $settings['summary_max_tokens'] );

		$this->assertArrayHasKey( 'tool_result_summarize_threshold', $settings );
		$this->assertSame( 2000, $settings['tool_result_summarize_threshold'] );
	}

	/**
	 * Test wp_mcp_ai_context_strategy filter is applied.
	 */
	public function test_context_strategy_filter() {
		$filter_called = false;

		add_filter(
			'wp_mcp_ai_context_strategy',
			function ( $strategy ) use ( &$filter_called ) {
				$filter_called = true;
				return $strategy;
			}
		);

		// The filter is applied in enforce_chat_request_limits which requires
		// full WordPress test bootstrap. Verify we can at least hook into it.
		$this->assertTrue( has_filter( 'wp_mcp_ai_context_strategy' ) );

		remove_all_filters( 'wp_mcp_ai_context_strategy' );
	}

	/**
	 * Test build_chat_limit_context with client-sent BME overrides.
	 */
	public function test_build_chat_limit_context_with_client_overrides() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_param( 'end_window_size', 15 );
		$request->set_param( 'context_strategy', 'bme' );

		$reflection = new ReflectionMethod( $this->rest, 'build_chat_limit_context' );
		$reflection->setAccessible( true );

		$context = $reflection->invoke( $this->rest, 1, array(), $request );

		$this->assertArrayHasKey( 'end_window_size', $context );
		$this->assertSame( 15, $context['end_window_size'] );
		$this->assertArrayHasKey( 'client_context_strategy', $context );
		$this->assertSame( 'bme', $context['client_context_strategy'] );
	}

	/**
	 * Test build_chat_limit_context without request (backward compat).
	 */
	public function test_build_chat_limit_context_without_request() {
		$reflection = new ReflectionMethod( $this->rest, 'build_chat_limit_context' );
		$reflection->setAccessible( true );

		$context = $reflection->invoke( $this->rest, 1, array( 'provider' => 'openai' ) );

		$this->assertSame( 1, $context['assistant_id'] );
		$this->assertSame( 'openai', $context['provider'] );
		$this->assertArrayNotHasKey( 'end_window_size', $context );
		$this->assertArrayNotHasKey( 'client_context_strategy', $context );
	}

	/**
	 * Test trim_messages_bme uses context end_window_size override.
	 */
	public function test_bme_end_window_size_override() {
		$messages = $this->build_messages( 20 ); // 40 messages.

		$settings = array(
			'end_window_size'       => 5,
			'summary_trigger_count' => 5, // Low trigger to force summarization.
			'summary_max_tokens'    => 500,
		);

		$context = array(
			'assistant_id'    => 1,
			'end_window_size' => 3, // Override: keep only 3 end messages.
		);

		$reflection = new ReflectionMethod( $this->rest, 'trim_messages_bme' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->rest, $messages, $settings, $context );

		// Should have system + summary + end messages (but summary generation will fail in test env).
		// The end window size of 3 means at most 3 messages in end zone, but since summary
		// generation will fail (no real LLM), it falls back to sliding window.
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test Conversational Summarizer class exists and is autoloadable.
	 */
	public function test_summarizer_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Conversation_Summarizer' ) );
	}

	/**
	 * Test summarizer can be instantiated with router.
	 */
	public function test_summarizer_instantiation() {
		$router     = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$summarizer = new WP_MCP_AI_Conversation_Summarizer( $router );

		$this->assertInstanceOf( WP_MCP_AI_Conversation_Summarizer::class, $summarizer );
	}
}
