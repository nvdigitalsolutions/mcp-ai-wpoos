<?php
/**
 * Tests for the research-completion trait (thinking-model recovery).
 *
 * The Pro research tools each resolve a provider/model/client and run a
 * single AI completion. Thinking-mode models share the output budget between
 * the chain of thought and the answer, so low max_tokens budgets can return
 * empty content with reasoning_content populated. These tests pin the shared
 * WP_MCP_AI_Tool_Research_Completion trait's recoveries: provider-aware
 * DeepSeek sampling, budget-extension retries, continuation retries, the
 * last-resort reasoning fallback, and array content flattening.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test research completion trait.
 */
class Test_Research_Completion_Trait extends WP_UnitTestCase {

	/**
	 * Tear down: remove filters added by tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_research_completion_max_attempts' );
		remove_all_filters( 'wp_mcp_ai_research_completion_budget_multiplier' );
		remove_all_filters( 'wp_mcp_ai_research_completion_temperature' );
		parent::tearDown();
	}

	/**
	 * Build a probe class exposing the trait method.
	 *
	 * @return object Probe instance with a public run() shim.
	 */
	private function make_probe() {
		$trait_file = dirname( __DIR__ ) . '/includes/tools/research/trait-wp-mcp-ai-tool-research-completion.php';

		if ( ! trait_exists( 'WP_MCP_AI_Tool_Research_Completion' ) && file_exists( $trait_file ) ) {
			require_once $trait_file;
		}

		if ( ! trait_exists( 'WP_MCP_AI_Tool_Research_Completion' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Research_Completion trait not loaded.' );
		}

		return new class() {
			use WP_MCP_AI_Tool_Research_Completion;

			/**
			 * Public shim for the protected trait method.
			 *
			 * @param object $client   Fake AI client.
			 * @param array  $messages Chat messages.
			 * @param string $provider Provider id.
			 * @param string $model    Model identifier.
			 * @param array  $options  Completion options.
			 * @return array|WP_Error
			 */
			public function run( $client, array $messages, $provider, $model, array $options = array() ) {
				return $this->perform_research_completion( $client, $messages, $provider, $model, $options );
			}
		};
	}

	/**
	 * Build a fake client returning the given completion payloads in order.
	 *
	 * @param array $responses Ordered responses (one per call).
	 * @return object Fake client with a public call log.
	 */
	private function fake_client( array $responses ) {
		return new class( $responses ) {
			/**
			 * Response queue.
			 *
			 * @var array
			 */
			private $responses;

			/**
			 * Call log (messages + options per call).
			 *
			 * @var array
			 */
			public $calls = array();

			/**
			 * Constructor.
			 *
			 * @param array $responses Ordered response payloads.
			 */
			public function __construct( array $responses ) {
				$this->responses = $responses;
			}

			/**
			 * Fake chat completion.
			 *
			 * @param array $messages Messages.
			 * @param array $options  Options.
			 * @return array|WP_Error
			 */
			public function create_chat_completion( array $messages, array $options = array() ) {
				$this->calls[] = array(
					'messages' => $messages,
					'options'  => $options,
				);

				if ( empty( $this->responses ) ) {
					return new WP_Error( 'fake_exhausted', 'No responses left.' );
				}

				return array_shift( $this->responses );
			}
		};
	}

	/**
	 * Build a completion payload for the given text.
	 *
	 * @param string $text Completion text.
	 * @return array Completion payload.
	 */
	private function completion( $text ) {
		return array(
			'choices'       => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => $text,
					),
					'finish_reason' => 'stop',
				),
			),
			'finish_reason' => 'stop',
		);
	}

	/**
	 * Build a reasoning-only payload.
	 *
	 * @param string $reasoning    Reasoning text.
	 * @param string $finish_reason Finish reason (length or stop).
	 * @return array Completion payload.
	 */
	private function reasoning_only( $reasoning, $finish_reason = 'length' ) {
		return array(
			'choices'           => array(
				array(
					'message'       => array(
						'role'    => 'assistant',
						'content' => '',
					),
					'finish_reason' => $finish_reason,
				),
			),
			'finish_reason'     => $finish_reason,
			'reasoning_content' => $reasoning,
		);
	}

	/**
	 * A successful completion returns the canonical envelope.
	 */
	public function test_success_returns_envelope() {
		$client = $this->fake_client( array( $this->completion( '{"ok":true}' ) ) );
		$probe  = $this->make_probe();

		$result = $probe->run(
			$client,
			array(
				array(
					'role'    => 'system',
					'content' => 's',
				),
				array(
					'role'    => 'user',
					'content' => 'u',
				),
			),
			'openai',
			'gpt-4.1',
			array(
				'temperature' => 0.2,
				'max_tokens'  => 2500,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '{"ok":true}', $result['content'] );
		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'gpt-4.1', $result['model'] );
		$this->assertSame( 1, $result['attempts'] );
		$this->assertArrayNotHasKey( 'used_reasoning_fallback', $result );

		// Non-DeepSeek providers keep the caller's temperature.
		$this->assertSame( 0.2, $client->calls[0]['options']['temperature'] );
		$this->assertArrayNotHasKey( 'top_p', $client->calls[0]['options'] );
	}

	/**
	 * DeepSeek completions use the vendor V4 guidance: temperature 1.0 and
	 * top_p 1.0, overriding the tool's requested temperature.
	 */
	public function test_deepseek_gets_vendor_sampling() {
		$client = $this->fake_client( array( $this->completion( 'ok' ) ) );
		$probe  = $this->make_probe();

		$probe->run(
			$client,
			array(
				array(
					'role'    => 'user',
					'content' => 'u',
				),
			),
			'deepseek',
			'deepseek-flash',
			array(
				'temperature' => 0.2,
				'max_tokens'  => 2000,
			)
		);

		$this->assertSame( 1.0, $client->calls[0]['options']['temperature'] );
		$this->assertSame( 1.0, $client->calls[0]['options']['top_p'] );
	}

	/**
	 * Reasoning-only output with finish_reason=length retries once with a
	 * multiplied budget and completes.
	 */
	public function test_reasoning_length_retries_with_larger_budget() {
		$client = $this->fake_client(
			array(
				$this->reasoning_only( 'thinking about the answer', 'length' ),
				$this->completion( '{"ok":true}' ),
			)
		);
		$probe  = $this->make_probe();

		$result = $probe->run(
			$client,
			array(
				array(
					'role'    => 'user',
					'content' => 'u',
				),
			),
			'deepseek',
			'deepseek-flash',
			array(
				'temperature' => 0.2,
				'max_tokens'  => 2000,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '{"ok":true}', $result['content'] );
		$this->assertArrayNotHasKey( 'used_reasoning_fallback', $result );
		$this->assertSame( 2, count( $client->calls ) );
		$this->assertSame( 2000, $client->calls[0]['options']['max_tokens'] );
		// Default budget multiplier of 4.
		$this->assertSame( 8000, $client->calls[1]['options']['max_tokens'] );
	}

	/**
	 * Reasoning-only output with a stop finish triggers a continuation retry
	 * that asks for the finished output.
	 */
	public function test_reasoning_stop_uses_continuation_retry() {
		$client = $this->fake_client(
			array(
				$this->reasoning_only( 'let me think about this first', 'stop' ),
				$this->completion( '{"ok":true}' ),
			)
		);
		$probe  = $this->make_probe();

		$result = $probe->run(
			$client,
			array(
				array(
					'role'    => 'system',
					'content' => 's',
				),
				array(
					'role'    => 'user',
					'content' => 'u',
				),
			),
			'deepseek',
			'deepseek-flash',
			array(
				'temperature' => 0.2,
				'max_tokens'  => 2000,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '{"ok":true}', $result['content'] );
		$this->assertArrayNotHasKey( 'used_reasoning_fallback', $result );
		$this->assertSame( 2, count( $client->calls ) );

		// The continuation attempt appends an explicit instruction.
		$second_messages = $client->calls[1]['messages'];
		$this->assertSame( 3, count( $second_messages ) );
		$this->assertStringContainsString( 'Produce the finished output', $second_messages[2]['content'] );
	}

	/**
	 * When attempts are exhausted the reasoning text is returned as an
	 * explicit fallback.
	 */
	public function test_reasoning_fallback_when_attempts_exhausted() {
		// Force a single attempt: __return_true casts to int 1.
		add_filter( 'wp_mcp_ai_research_completion_max_attempts', '__return_true' );
		$client = $this->fake_client(
			array(
				$this->reasoning_only( 'thinking about the answer', 'length' ),
			)
		);
		$probe  = $this->make_probe();

		$result = $probe->run(
			$client,
			array(
				array(
					'role'    => 'user',
					'content' => 'u',
				),
			),
			'deepseek',
			'deepseek-flash',
			array( 'max_tokens' => 2000 )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'thinking about the answer', $result['content'] );
		$this->assertTrue( $result['used_reasoning_fallback'] );
		$this->assertSame( 1, count( $client->calls ) );
	}

	/**
	 * An empty completion without reasoning surfaces a WP_Error.
	 */
	public function test_empty_completion_returns_error() {
		// Two empty responses: the retry exhausts on the same empty-completion
		// condition so the surfaced error stays the specific empty-completion
		// code (a transport failure on the retry would win otherwise).
		$client = $this->fake_client( array( $this->completion( '' ), $this->completion( '' ) ) );
		$probe  = $this->make_probe();

		$result = $probe->run(
			$client,
			array(
				array(
					'role'    => 'user',
					'content' => 'u',
				),
			),
			'openai',
			'gpt-4.1',
			array( 'max_tokens' => 2000 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_empty_completion', $result->get_error_code() );
	}

	/**
	 * Truncated non-empty output retries once with a larger budget; when the
	 * retry also truncates, the envelope carries the truncated flag.
	 */
	public function test_truncated_output_retries_and_flags() {
		$client = $this->fake_client(
			array(
				array(
					'choices'       => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => 'partial',
							),
							'finish_reason' => 'length',
						),
					),
					'finish_reason' => 'length',
				),
				array(
					'choices'       => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => 'still partial',
							),
							'finish_reason' => 'length',
						),
					),
					'finish_reason' => 'length',
				),
			)
		);
		$probe  = $this->make_probe();

		$result = $probe->run(
			$client,
			array(
				array(
					'role'    => 'user',
					'content' => 'u',
				),
			),
			'openai',
			'gpt-4.1',
			array( 'max_tokens' => 2000 )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'still partial', $result['content'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 2, count( $client->calls ) );
		$this->assertSame( 8000, $client->calls[1]['options']['max_tokens'] );
	}

	/**
	 * Array content parts are flattened to a string.
	 */
	public function test_array_content_parts_are_flattened() {
		$client = $this->fake_client(
			array(
				array(
					'choices'       => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => array(
									array(
										'type' => 'text',
										'text' => '{"ok":',
									),
									array(
										'type' => 'text',
										'text' => 'true}',
									),
								),
							),
							'finish_reason' => 'stop',
						),
					),
					'finish_reason' => 'stop',
				),
			)
		);
		$probe  = $this->make_probe();

		$result = $probe->run(
			$client,
			array(
				array(
					'role'    => 'user',
					'content' => 'u',
				),
			),
			'gemini',
			'gemini-2.5-flash',
			array( 'max_tokens' => 2000 )
		);

		$this->assertIsArray( $result );
		$this->assertIsString( $result['content'] );
		$this->assertSame( '{"ok":' . "\n\n" . 'true}', $result['content'] );
	}
}
