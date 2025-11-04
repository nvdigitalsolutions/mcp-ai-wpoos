<?php
/**
 * Test WP_MCP_AI_Enhanced_OpenAI_Client stabilization features.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Enhanced_OpenAI_Client_Stabilization
 */
class Test_WP_MCP_AI_Enhanced_OpenAI_Client_Stabilization extends WP_UnitTestCase {

	/**
	 * Mock OpenAI client.
	 *
	 * @var WP_MCP_AI_OpenAI_Client
	 */
	private $mock_client;

	/**
	 * Enhanced client instance.
	 *
	 * @var WP_MCP_AI_Enhanced_OpenAI_Client
	 */
	private $enhanced_client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a mock OpenAI client.
		$this->mock_client = $this->getMockBuilder( WP_MCP_AI_OpenAI_Client::class )
			->getMock();

		$this->enhanced_client = new WP_MCP_AI_Enhanced_OpenAI_Client( $this->mock_client );
	}

	/**
	 * Test that input token validation is applied.
	 */
	public function test_input_token_validation_applied() {
		// Create a message that exceeds 12k tokens.
		$long_text = str_repeat( 'This is a very long message with lots of content. ', 1000 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $long_text,
			),
		);

		// Should return error without calling underlying client.
		$result = $this->enhanced_client->create_chat_completion( $messages, array() );

		$this->assertWPError( $result, 'Should return WP_Error for token limit exceeded' );
		$this->assertEquals( 'wp_mcp_ai_input_tokens_exceeded', $result->get_error_code() );
	}

	/**
	 * Test that max_tokens is automatically set.
	 */
	public function test_max_tokens_automatically_set() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Short message',
			),
		);

		// Mock the client to capture the options passed to it.
		$captured_options = null;
		$this->mock_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $options ) use ( &$captured_options ) {
						$captured_options = $options;
						return true;
					}
				)
			)
			->willReturn( array( 'success' => true ) );

		$result = $this->enhanced_client->create_chat_completion( $messages, array() );

		$this->assertIsArray( $captured_options, 'Options should be passed to underlying client' );
		$this->assertArrayHasKey( 'max_tokens', $captured_options, 'max_tokens should be set' );
		$this->assertGreaterThan( 0, $captured_options['max_tokens'], 'max_tokens should be positive' );
		$this->assertLessThanOrEqual( 4096, $captured_options['max_tokens'], 'max_tokens should be capped at 4096' );
	}

	/**
	 * Test that explicit max_tokens is preserved.
	 */
	public function test_explicit_max_tokens_preserved() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Message',
			),
		);

		$captured_options = null;
		$this->mock_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $options ) use ( &$captured_options ) {
						$captured_options = $options;
						return true;
					}
				)
			)
			->willReturn( array( 'success' => true ) );

		$result = $this->enhanced_client->create_chat_completion(
			$messages,
			array( 'max_tokens' => 1000 )
		);

		$this->assertEquals( 1000, $captured_options['max_tokens'], 'Explicit max_tokens should be preserved' );
	}

	/**
	 * Test model routing is applied.
	 */
	public function test_model_routing_applied() {
		$simple_message = array(
			array(
				'role'    => 'user',
				'content' => 'What is 2+2?',
			),
		);

		$complex_message = array(
			array(
				'role'    => 'user',
				'content' => 'Please provide a detailed analysis of quantum computing.',
			),
		);

		$captured_options_simple  = null;
		$captured_options_complex = null;

		// Test simple message.
		$this->mock_client->expects( $this->exactly( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages, $options ) use ( &$captured_options_simple, &$captured_options_complex ) {
					static $call_count = 0;
					$call_count++;

					if ( 1 === $call_count ) {
						$captured_options_simple = $options;
					} else {
						$captured_options_complex = $options;
					}

					return array( 'success' => true );
				}
			);

		$this->enhanced_client->create_chat_completion( $simple_message, array() );
		$this->enhanced_client->create_chat_completion( $complex_message, array() );

		$this->assertEquals( 'gpt-4o-mini', $captured_options_simple['model'], 'Simple message should route to gpt-4o-mini' );
		$this->assertEquals( 'gpt-4o', $captured_options_complex['model'], 'Complex message should route to gpt-4o' );
	}

	/**
	 * Test that auto-routing can be disabled.
	 */
	public function test_disable_auto_routing() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Provide a comprehensive analysis.',
			),
		);

		$captured_options = null;
		$this->mock_client->expects( $this->once() )
			->method( 'create_chat_completion' )
			->with(
				$this->anything(),
				$this->callback(
					function ( $options ) use ( &$captured_options ) {
						$captured_options = $options;
						return true;
					}
				)
			)
			->willReturn( array( 'success' => true ) );

		$result = $this->enhanced_client->create_chat_completion(
			$messages,
			array( 'disable_auto_routing' => true )
		);

		// When auto-routing is disabled, the default model from settings is used.
		// The model selector won't override it.
		$this->assertArrayHasKey( 'model', $captured_options );
	}

	/**
	 * Test split_document uses recommended chunk size.
	 */
	public function test_split_document_uses_recommended_size() {
		$content = str_repeat( 'This is document content. ', 1000 );

		$chunks = $this->enhanced_client->split_document( $content, 'gpt-4o-mini' );

		$this->assertIsArray( $chunks );
		$this->assertGreaterThan( 0, count( $chunks ) );

		// Verify chunks are within expected size.
		foreach ( $chunks as $chunk ) {
			$token_count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $chunk );
			$this->assertLessThanOrEqual( 8000, $token_count, 'Chunk should not exceed 8k tokens (with margin)' );
		}
	}

	/**
	 * Test split_document with custom chunk size.
	 */
	public function test_split_document_custom_chunk_size() {
		$content = str_repeat( 'Content. ', 500 );

		$chunks = $this->enhanced_client->split_document( $content, 'gpt-4o-mini', 2000 );

		$this->assertIsArray( $chunks );

		foreach ( $chunks as $chunk ) {
			$token_count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $chunk );
			$this->assertLessThanOrEqual( 2500, $token_count, 'Chunk should respect custom size' );
		}
	}

	/**
	 * Test that rate limit manager is used for retries.
	 */
	public function test_rate_limit_retry_logic() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		$call_count = 0;
		$this->mock_client->expects( $this->atLeast( 2 ) )
			->method( 'create_chat_completion' )
			->willReturnCallback(
				function () use ( &$call_count ) {
					$call_count++;

					if ( $call_count < 3 ) {
						return new WP_Error(
							'wp_mcp_ai_api_error',
							'Rate limited',
							array( 'status' => 429 )
						);
					}

					return array( 'success' => true );
				}
			);

		$result = $this->enhanced_client->create_chat_completion( $messages, array() );

		$this->assertIsArray( $result, 'Should eventually succeed after retries' );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThanOrEqual( 2, $call_count, 'Should have retried at least once' );
	}

	/**
	 * Test max_tokens calculation.
	 */
	public function test_max_tokens_calculation() {
		// Test with varying message sizes.
		$test_cases = array(
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Short',
					),
				),
				'min'      => 512,
				'max'      => 4096,
			),
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => str_repeat( 'Longer message. ', 200 ),
					),
				),
				'min'      => 512,
				'max'      => 4096,
			),
		);

		foreach ( $test_cases as $case ) {
			$captured_options = null;
			$this->mock_client->expects( $this->once() )
				->method( 'create_chat_completion' )
				->with(
					$this->anything(),
					$this->callback(
						function ( $options ) use ( &$captured_options ) {
							$captured_options = $options;
							return true;
						}
					)
				)
				->willReturn( array( 'success' => true ) );

			$this->enhanced_client->create_chat_completion( $case['messages'], array() );

			$this->assertGreaterThanOrEqual(
				$case['min'],
				$captured_options['max_tokens'],
				'max_tokens should be at least minimum'
			);

			$this->assertLessThanOrEqual(
				$case['max'],
				$captured_options['max_tokens'],
				'max_tokens should not exceed maximum'
			);

			// Reset mock for next iteration.
			$this->setUp();
		}
	}
}
