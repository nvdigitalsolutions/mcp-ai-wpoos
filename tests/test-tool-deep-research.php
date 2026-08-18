<?php
/**
 * Tests for deep_research report-generation reliability (empty completions,
 * reasoning fallback, truncation retry, provider fallback chain).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test deep_research reliability behaviour.
 *
 * Uses an anonymous harness subclass to inject fake provider clients and
 * expose the protected analyze_findings method.
 */
class Test_Tool_Deep_Research extends WP_UnitTestCase {

	/**
	 * Tear down: remove filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_deep_research_max_attempts' );
		parent::tearDown();
	}

	/**
	 * Build a harness with the given fake provider setups.
	 *
	 * @param array $setups Ordered provider setups (provider, model, client).
	 * @return WP_MCP_AI_Tool_Deep_Research Harness exposing run_analysis().
	 */
	private function make_harness( array $setups ) {
		return new class( $setups ) extends WP_MCP_AI_Tool_Deep_Research {
			/**
			 * Ordered provider setups.
			 *
			 * @var array
			 */
			public $setups = array();

			/**
			 * Constructor.
			 *
			 * @param array $setups Ordered provider setups.
			 */
			public function __construct( array $setups ) {
				$this->setups = $setups;
			}

			/**
			 * Hands out setups in order, honouring the exclude list.
			 *
			 * @param array $exclude_providers Providers already tried.
			 * @return array|WP_Error
			 */
			protected function get_ai_setup( $exclude_providers = array() ) {
				foreach ( $this->setups as $setup ) {
					if ( in_array( $setup['provider'], (array) $exclude_providers, true ) ) {
						continue;
					}
					return $setup;
				}

				return new WP_Error( 'wp_mcp_ai_no_provider', 'No provider available.' );
			}

			/**
			 * Public shim for the protected analyze_findings method.
			 *
			 * @param string $topic           Research topic.
			 * @param array  $search_results  Search results.
			 * @param string $depth           Research depth.
			 * @param array  $focus_areas     Focus areas.
			 * @param bool   $include_sources Include sources.
			 * @param array  $context         Execution context.
			 * @return array|WP_Error
			 */
			public function run_analysis( $topic, $search_results, $depth = 'standard', $focus_areas = array(), $include_sources = true, $context = array() ) {
				return $this->analyze_findings( $topic, $search_results, $depth, $focus_areas, $include_sources, $context );
			}
		};
	}

	/**
	 * Build a fake client returning the given completion payload every call.
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
	 * Minimal search results fixture.
	 *
	 * @return array
	 */
	private function search_results_fixture() {
		return array(
			'results' => array(
				array(
					'title'   => 'Frost date calculator',
					'snippet' => 'Count back from first frost using days to maturity.',
					'url'     => 'https://example.com/frost',
				),
			),
			'sources' => array(
				array(
					'url'     => 'https://example.com/frost',
					'title'   => 'Frost date calculator',
					'snippet' => 'Count back from first frost.',
				),
			),
			'queries' => array( 'garden frost dates' ),
		);
	}

	/**
	 * Empty content + reasoning_content uses the reasoning as the report.
	 */
	public function test_reasoning_fallback_used_when_content_empty() {
		$client = $this->fake_client(
			array(
				array(
					'choices'           => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => '',
							),
							'finish_reason' => 'stop',
						),
					),
					'finish_reason'     => 'stop',
					'reasoning_content' => 'Reasoned analysis: plant garlic in October.',
				),
			)
		);

		$harness = $this->make_harness(
			array(
				array(
					'provider' => 'deepseek',
					'model'    => 'deepseek-v4-pro',
					'client'   => $client,
				),
			)
		);

		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture() );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'plant garlic in October', $result['content'] );
		$this->assertTrue( $result['used_reasoning_fallback'] );
		$this->assertSame( 'deepseek', $result['provider'] );
	}

	/**
	 * Empty completion retries with the next provider in the chain.
	 */
	public function test_empty_completion_falls_back_to_next_provider() {
		$first_client = $this->fake_client(
			array(
				array(
					'choices'       => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => '',
							),
							'finish_reason' => 'stop',
						),
					),
					'finish_reason' => 'stop',
				),
			)
		);

		$second_client = $this->fake_client(
			array(
				array(
					'choices'       => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => 'Full report text from the fallback provider.',
							),
							'finish_reason' => 'stop',
						),
					),
					'finish_reason' => 'stop',
				),
			)
		);

		$harness = $this->make_harness(
			array(
				array(
					'provider' => 'deepseek',
					'model'    => 'deepseek-v4-pro',
					'client'   => $first_client,
				),
				array(
					'provider' => 'gemini',
					'model'    => 'gemini-3.1-pro-preview',
					'client'   => $second_client,
				),
			)
		);

		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture() );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Full report text', $result['content'] );
		$this->assertSame( 'gemini', $result['provider'] );
		$this->assertSame( 2, $result['attempts'] );
		$this->assertSame( 1, count( $first_client->calls ) );
		$this->assertSame( 1, count( $second_client->calls ) );
	}

	/**
	 * Truncation (finish_reason=length) retries once with a doubled token budget.
	 */
	public function test_truncation_retries_with_larger_budget() {
		$client = $this->fake_client(
			array(
				array(
					'choices'       => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => '',
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
								'content' => 'Complete report after larger budget.',
							),
							'finish_reason' => 'stop',
						),
					),
					'finish_reason' => 'stop',
				),
			)
		);

		$harness = $this->make_harness(
			array(
				array(
					'provider' => 'deepseek',
					'model'    => 'deepseek-v4-pro',
					'client'   => $client,
				),
			)
		);

		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture() );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'Complete report', $result['content'] );
		$this->assertSame( 2, count( $client->calls ) );
		$this->assertSame( 2000, $client->calls[0]['options']['max_tokens'] );
		$this->assertSame( 4000, $client->calls[1]['options']['max_tokens'] );
	}

	/**
	 * When all providers fail, the tool returns an error carrying the sources.
	 */
	public function test_all_attempts_fail_returns_error_with_sources() {
		$client = $this->fake_client(
			array(
				array(
					'choices'       => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => '',
							),
							'finish_reason' => 'stop',
						),
					),
					'finish_reason' => 'stop',
				),
				array(
					'choices'       => array(
						array(
							'message'       => array(
								'role'    => 'assistant',
								'content' => '',
							),
							'finish_reason' => 'stop',
						),
					),
					'finish_reason' => 'stop',
				),
			)
		);

		$harness = $this->make_harness(
			array(
				array(
					'provider' => 'deepseek',
					'model'    => 'deepseek-v4-pro',
					'client'   => $client,
				),
			)
		);

		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_analysis_exhausted', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'sources', $data );
		$this->assertNotEmpty( $data['sources'] );
		$this->assertSame( array( 'deepseek' ), $data['attempted_providers'] );
	}

	/**
	 * Content returned as an array of text segments is normalised to a string.
	 */
	public function test_array_content_segments_are_normalised() {
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
										'text' => 'Segment one. ',
									),
									array(
										'type' => 'text',
										'text' => 'Segment two.',
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

		$harness = $this->make_harness(
			array(
				array(
					'provider' => 'openai',
					'model'    => 'gpt-4.1',
					'client'   => $client,
				),
			)
		);

		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture() );

		$this->assertIsArray( $result );
		$this->assertSame( 'Segment one. Segment two.', $result['content'] );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$harness = $this->make_harness( array() );
		$this->assertSame( 'deep_research', $harness->get_slug() );
		$this->assertNotEmpty( $harness->get_name() );
		$this->assertNotEmpty( $harness->get_description() );
	}
}
