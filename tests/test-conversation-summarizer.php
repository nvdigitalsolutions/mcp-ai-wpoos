<?php
/**
 * Tests for the Conversation Summarizer class.
 *
 * @package WP_MCP_AI
 */

use PHPUnit\Framework\TestCase;

/**
 * Test conversation summarizer functionality.
 */
class Test_Conversation_Summarizer extends WP_UnitTestCase {

	/**
	 * Summarizer instance.
	 *
	 * @var WP_MCP_AI_Conversation_Summarizer
	 */
	protected $summarizer;

	/**
	 * Mock router.
	 *
	 * @var WP_MCP_AI_Language_Model_Router
	 */
	protected $mock_router;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Conversation_Summarizer' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-conversation-summarizer.php';
		}

		$this->mock_router = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$this->summarizer  = new WP_MCP_AI_Conversation_Summarizer( $this->mock_router );
	}

	/**
	 * Test should_summarize returns false when under threshold.
	 */
	public function test_should_summarize_under_threshold() {
		$messages = array_fill(
			0,
			5,
			array(
				'role'    => 'user',
				'content' => 'Hello',
			)
		);
		$this->assertFalse( $this->summarizer->should_summarize( $messages, 10 ) );
	}

	/**
	 * Test should_summarize returns true when over threshold.
	 */
	public function test_should_summarize_over_threshold() {
		$messages = array_fill(
			0,
			35,
			array(
				'role'    => 'user',
				'content' => 'Hello',
			)
		);
		$this->assertTrue( $this->summarizer->should_summarize( $messages, 30 ) );
	}

	/**
	 * Test should_summarize uses default trigger when 0 passed.
	 */
	public function test_should_summarize_default_trigger() {
		$messages = array_fill(
			0,
			35,
			array(
				'role'    => 'user',
				'content' => 'Hello',
			)
		);
		$this->assertTrue( $this->summarizer->should_summarize( $messages ) );
	}

	/**
	 * Test should_summarize_tool_result returns true when over threshold.
	 */
	public function test_should_summarize_tool_result_over_threshold() {
		$long_content = str_repeat( 'x', 3000 );
		$this->assertTrue( $this->summarizer->should_summarize_tool_result( $long_content, 2000 ) );
	}

	/**
	 * Test should_summarize_tool_result returns false when under threshold.
	 */
	public function test_should_summarize_tool_result_under_threshold() {
		$short_content = str_repeat( 'x', 500 );
		$this->assertFalse( $this->summarizer->should_summarize_tool_result( $short_content, 2000 ) );
	}

	/**
	 * Test should_summarize_tool_result returns false when threshold is 0 (disabled).
	 */
	public function test_should_summarize_tool_result_disabled() {
		$content = str_repeat( 'x', 5000 );
		$this->assertFalse( $this->summarizer->should_summarize_tool_result( $content, 0 ) );
	}

	/**
	 * Test summarize returns empty string for empty messages.
	 */
	public function test_summarize_empty_messages() {
		$result = $this->summarizer->summarize( array() );
		$this->assertSame( '', $result );
	}

	/**
	 * Test summarize calls router and returns summary text.
	 */
	public function test_summarize_calls_router() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What is the weather?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'The weather is sunny.',
			),
		);

		$this->mock_router
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'choices' => array(
						array(
							'message' => array(
								'content' => 'The user asked about weather and was told it is sunny.',
							),
						),
					),
				)
			);

		$result = $this->summarizer->summarize( $messages, array( 'max_tokens' => 100 ) );
		$this->assertSame( 'The user asked about weather and was told it is sunny.', $result );
	}

	/**
	 * Test summarize returns WP_Error when router fails.
	 */
	public function test_summarize_router_error() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$this->mock_router
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn( new WP_Error( 'api_error', 'API failure' ) );

		$result = $this->summarizer->summarize( $messages );
		$this->assertWPError( $result );
		$this->assertSame( 'api_error', $result->get_error_code() );
	}

	/**
	 * Test summarize handles array content (multi-modal messages).
	 */
	public function test_summarize_with_array_content() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array( 'text' => 'Look at this image.' ),
					array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => 'https://example.com/img.jpg' ),
					),
				),
			),
		);

		$this->mock_router
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'choices' => array(
						array(
							'message' => array( 'content' => 'User shared an image.' ),
						),
					),
				)
			);

		$result = $this->summarizer->summarize( $messages );
		$this->assertSame( 'User shared an image.', $result );
	}

	/**
	 * Test summarize skips system messages in the input.
	 */
	public function test_summarize_skips_system_messages() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hi there!',
			),
		);

		$this->mock_router
			->expects( $this->once() )
			->method( 'create_chat_completion' )
			->willReturn(
				array(
					'choices' => array(
						array(
							'message' => array( 'content' => 'User said hello, assistant responded.' ),
						),
					),
				)
			);

		$result = $this->summarizer->summarize( $messages );
		$this->assertStringNotContainsString( 'You are a helpful assistant', $result );
	}
}
