<?php
/**
 * Tests for the deep_research report validation gate, query scaling,
 * source deduplication, page reading, and diagnostics.
 *
 * Covers the reliability layer added alongside the multi-stage pipeline:
 * a generated report is validated (length, drafting notes, abrupt endings,
 * sections, citations) before being marked complete; a failed draft triggers
 * one feedback-driven rewrite and otherwise returns an explicit partial.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test deep_research validation behaviour.
 *
 * Uses an anonymous harness subclass to inject fake provider clients and
 * expose the protected pipeline methods.
 */
class Test_Deep_Research_Validation extends WP_UnitTestCase {

	/**
	 * Tear down: remove filters added by tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_deep_research_max_queries' );
		remove_all_filters( 'wp_mcp_ai_deep_research_min_words' );
		remove_all_filters( 'wp_mcp_ai_deep_research_enable_validation' );
		remove_all_filters( 'wp_mcp_ai_deep_research_time_budget' );
		remove_all_filters( 'wp_mcp_ai_deep_research_max_pages' );
		remove_all_filters( 'wp_mcp_ai_deep_research_max_sources_per_domain' );
		parent::tearDown();
	}

	/**
	 * Build a harness exposing the protected pipeline methods.
	 *
	 * @param array $setups Ordered provider setups (provider, model, client).
	 * @return WP_MCP_AI_Tool_Deep_Research Harness.
	 */
	private function make_harness( array $setups = array() ) {
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
			 * Public shim for analyze_findings.
			 *
			 * @param string     $topic           Research topic.
			 * @param array      $search_results  Search results.
			 * @param string     $depth           Research depth.
			 * @param array      $focus_areas     Focus areas.
			 * @param bool       $include_sources Include sources.
			 * @param array      $context         Execution context.
			 * @param float|null $start_time      Start time for budget checks.
			 * @return array|WP_Error
			 */
			public function run_analysis( $topic, $search_results, $depth = 'standard', $focus_areas = array(), $include_sources = true, $context = array(), $start_time = null ) {
				return $this->analyze_findings( $topic, $search_results, $depth, $focus_areas, $include_sources, $context, array(), array(), array(), $start_time );
			}

			/**
			 * Public shim for validate_report_content.
			 *
			 * @param string $content         Report content.
			 * @param string $depth           Research depth.
			 * @param bool   $include_sources Whether citations were requested.
			 * @return array
			 */
			public function run_validation( $content, $depth = 'standard', $include_sources = true ) {
				return $this->validate_report_content( $content, $depth, $include_sources );
			}

			/**
			 * Public shim for generate_search_queries.
			 *
			 * @param string $topic       Research topic.
			 * @param string $depth       Research depth.
			 * @param array  $focus_areas Focus areas.
			 * @return array
			 */
			public function run_queries( $topic, $depth, $focus_areas = array() ) {
				return $this->generate_search_queries( $topic, $depth, $focus_areas );
			}

			/**
			 * Public shim for deduplicate_sources.
			 *
			 * @param array $sources Sources.
			 * @return array
			 */
			public function run_dedupe( array $sources ) {
				return $this->deduplicate_sources( $sources );
			}

			/**
			 * Public shim for read_source_pages.
			 *
			 * @param array      $sources    Sources.
			 * @param string     $depth      Research depth.
			 * @param float|null $start_time Start time.
			 * @return array
			 */
			public function run_pages( $sources, $depth, $start_time = null ) {
				return $this->read_source_pages( $sources, $depth, $start_time );
			}

			/**
			 * Public shim for build_research_report.
			 *
			 * @param string $topic          Topic.
			 * @param string $depth          Depth.
			 * @param array  $analysis       Analysis result.
			 * @param array  $search_results Search results.
			 * @param bool   $include_sources Include sources.
			 * @return array
			 */
			public function run_report( $topic, $depth, $analysis, $search_results, $include_sources = true ) {
				return $this->build_research_report( $topic, $depth, $analysis, $search_results, $include_sources );
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
	 * @param string $text Report text.
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
	 * A report that should pass every quality check for standard depth.
	 *
	 * @return string
	 */
	private function complete_report() {
		return '## Overview'
			. "\n" . 'This research covers the topic in detail. The overview explains the background, the scope, and the methods used to gather information from the available sources [1]. It also describes the limitations of the evidence and the approach taken to cross-check claims across independent sources [2].'
			. "\n\n" . '## Main Findings'
			. "\n" . 'Multiple sources agree on the core facts presented in the findings [1][2]. The first source provides the historical record and the original documentation of the behavior, while the second source provides current statistics and recent developments [2].'
			. "\n\n" . '## Analysis'
			. "\n" . 'Analysis of the evidence supports the conclusion that the documented capabilities are real, the observed behavior matches the documentation, and the remaining unknowns are limited to edge cases that no source addresses directly [1]. The findings are internally consistent and no source contradicts another on the central claims.'
			. "\n\n" . '## Conclusion'
			. "\n" . 'The topic is well documented across the gathered sources, and the central findings are supported by at least one citable source each. Further research should focus on the identified unknowns. The report ends with a complete sentence.';
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

	// -------------------------------------------------------------------------
	// Validation unit checks
	// -------------------------------------------------------------------------

	/**
	 * A complete report passes validation.
	 */
	public function test_validation_accepts_complete_report() {
		$harness = $this->make_harness();
		$result  = $harness->run_validation( $this->complete_report() );

		$this->assertTrue( $result['passed'] );
		$this->assertEmpty( $result['issues'] );
	}

	/**
	 * Drafting notes are detected and rejected.
	 */
	public function test_validation_detects_drafting_notes() {
		$harness = $this->make_harness();
		$result  = $harness->run_validation( "I'll draft a full research report once I have more information. Let me check the sources first." );

		$this->assertFalse( $result['passed'] );
		$this->assertContains( 'drafting_notes_detected', $result['issues'] );
	}

	/**
	 * Abrupt endings (no sentence punctuation in the tail) are detected.
	 */
	public function test_validation_detects_abrupt_ending() {
		$harness = $this->make_harness();
		$result  = $harness->run_validation( 'The research report continues with several important findings about the topic and then' );

		$this->assertFalse( $result['passed'] );
		$this->assertContains( 'abrupt_ending', $result['issues'] );
	}

	/**
	 * A dangling Sources: label at the end counts as an abrupt ending.
	 */
	public function test_validation_detects_dangling_sources_label() {
		$harness = $this->make_harness();
		$result  = $harness->run_validation( "The findings are documented in the report [1].\n\nSources:" );

		$this->assertFalse( $result['passed'] );
		$this->assertContains( 'abrupt_ending', $result['issues'] );
	}

	/**
	 * Missing citations fail validation when sources were requested.
	 */
	public function test_validation_requires_citations() {
		$report_without_citations = preg_replace( '/\[\d+\]/', '', $this->complete_report() );

		$harness = $this->make_harness();
		$result  = $harness->run_validation( $report_without_citations, 'standard', true );

		$this->assertFalse( $result['passed'] );
		$this->assertContains( 'missing_citations', $result['issues'] );
	}

	/**
	 * Missing citations are not an issue when sources were not requested.
	 */
	public function test_validation_skips_citations_when_sources_disabled() {
		$report_without_citations = preg_replace( '/\[\d+\]/', '', $this->complete_report() );

		$harness = $this->make_harness();
		$result  = $harness->run_validation( $report_without_citations, 'standard', false );

		$this->assertTrue( $result['passed'] );
	}

	/**
	 * Validation can be disabled via filter.
	 */
	public function test_validation_can_be_disabled() {
		add_filter( 'wp_mcp_ai_deep_research_enable_validation', '__return_false' );

		$harness = $this->make_harness();
		$result  = $harness->run_validation( 'TBD' );

		$this->assertTrue( $result['passed'] );
	}

	// -------------------------------------------------------------------------
	// Validation gate inside the synthesis loop
	// -------------------------------------------------------------------------

	/**
	 * A failed draft triggers one feedback-driven rewrite that fixes it.
	 */
	public function test_validation_failure_triggers_feedback_rewrite() {
		$draft = "I'll draft a full report later. To be completed.";

		$client = $this->fake_client(
			array(
				$this->completion( $draft ),
				$this->completion( $this->complete_report() ),
			)
		);

		$harness = $this->make_harness(
			array(
				array(
					'provider' => 'deepseek',
					'model'    => 'deepseek-flash',
					'client'   => $client,
				),
			)
		);

		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['validated'] );
		$this->assertSame( 2, count( $client->calls ) );

		// The rewrite prompt must carry the feedback from the failed draft.
		$second_prompt = $client->calls[1]['messages'][1]['content'];
		$this->assertStringContainsString( 'FAILED QUALITY CHECKS', $second_prompt );
		$this->assertStringContainsString( 'drafting_notes_detected', $second_prompt );
	}

	/**
	 * When the retry also fails, the last draft is salvaged as a partial.
	 */
	public function test_validation_exhausted_returns_salvaged_draft() {
		$draft = "I'll draft a full report later. To be completed.";

		$client = $this->fake_client(
			array(
				$this->completion( $draft ),
			)
		);

		$harness = $this->make_harness(
			array(
				array(
					'provider' => 'deepseek',
					'model'    => 'deepseek-flash',
					'client'   => $client,
				),
			)
		);

		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['validated'] );
		$this->assertStringContainsString( "I'll draft", $result['content'] );
		$this->assertContains( 'drafting_notes_detected', $result['validation_issues'] );
	}

	/**
	 * A failed draft is returned immediately as partial when the time budget
	 * is already exhausted (no rewrite retry).
	 */
	public function test_validation_retry_skipped_when_budget_exceeded() {
		$draft = "I'll draft a full report later. To be completed.";

		$client = $this->fake_client(
			array(
				$this->completion( $draft ),
			)
		);

		$harness = $this->make_harness(
			array(
				array(
					'provider' => 'deepseek',
					'model'    => 'deepseek-flash',
					'client'   => $client,
				),
			)
		);

		// Start time in the past: the default 90 s budget is already spent.
		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture(), 'standard', array(), true, array(), microtime( true ) - 200 );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['validated'] );
		$this->assertSame( 1, count( $client->calls ) );
	}

	/**
	 * The reasoning fallback runs quality checks so callers see whether the
	 * fallback output is usable.
	 */
	public function test_reasoning_fallback_carries_validation_outcome() {
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
					'model'    => 'deepseek-flash',
					'client'   => $client,
				),
			)
		);

		$result = $harness->run_analysis( 'garden frost dates', $this->search_results_fixture() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['used_reasoning_fallback'] );
		$this->assertArrayHasKey( 'validated', $result );
		$this->assertFalse( $result['validated'] );
	}

	// -------------------------------------------------------------------------
	// Depth measurability: query scaling
	// -------------------------------------------------------------------------

	/**
	 * Query counts scale with depth.
	 */
	public function test_query_scaling_by_depth() {
		$harness = $this->make_harness();

		$this->assertSame( 1, count( $harness->run_queries( 'composting basics', 'basic' ) ) );
		$this->assertSame( 3, count( $harness->run_queries( 'composting basics', 'standard' ) ) );
		$this->assertSame( 6, count( $harness->run_queries( 'composting basics', 'comprehensive' ) ) );
	}

	/**
	 * Comprehensive runs include evidence-oriented queries.
	 */
	public function test_comprehensive_queries_include_official_and_counter_evidence() {
		$harness = $this->make_harness();
		$queries = $harness->run_queries( 'composting basics', 'comprehensive' );

		$this->assertContains( 'composting basics official documentation', $queries );
		$this->assertContains( 'composting basics statistics and data', $queries );
		$this->assertContains( 'composting basics limitations criticism controversy', $queries );
	}

	/**
	 * Focus areas expand the query set within the depth budget.
	 */
	public function test_focus_areas_expand_queries() {
		$harness = $this->make_harness();
		$queries = $harness->run_queries( 'composting basics', 'standard', array( 'vermiculture' ) );

		$this->assertSame( 3, count( $queries ) );
		$this->assertContains( 'composting basics vermiculture', $queries );
	}

	/**
	 * The query count respects the filterable cap.
	 */
	public function test_query_count_respects_filter_cap() {
		add_filter(
			'wp_mcp_ai_deep_research_max_queries',
			static function () {
				return 2;
			}
		);

		$harness = $this->make_harness();
		$queries = $harness->run_queries( 'composting basics', 'comprehensive' );

		$this->assertSame( 2, count( $queries ) );
	}

	// -------------------------------------------------------------------------
	// Source handling
	// -------------------------------------------------------------------------

	/**
	 * Sources from the same domain are capped to keep the evidence diverse.
	 */
	public function test_deduplicate_sources_caps_per_domain() {
		$harness = $this->make_harness();
		$sources = array(
			array(
				'url'   => 'https://example.com/one',
				'title' => 'One',
			),
			array(
				'url'   => 'https://example.com/two',
				'title' => 'Two',
			),
			array(
				'url'   => 'https://example.com/three',
				'title' => 'Three',
			),
			array(
				'url'   => 'https://other.org/page',
				'title' => 'Other',
			),
		);

		$deduped = $harness->run_dedupe( $sources );

		$this->assertSame( 3, count( $deduped ) );
		$this->assertSame( 'https://other.org/page', $deduped[2]['url'] );
	}

	/**
	 * WWW and non-WWW hosts are treated as the same domain.
	 */
	public function test_deduplicate_sources_treats_www_as_same_domain() {
		$harness = $this->make_harness();
		$sources = array(
			array(
				'url'   => 'https://www.example.com/one',
				'title' => 'One',
			),
			array(
				'url'   => 'https://example.com/two',
				'title' => 'Two',
			),
			array(
				'url'   => 'https://example.com/three',
				'title' => 'Three',
			),
		);

		$deduped = $harness->run_dedupe( $sources );

		$this->assertSame( 2, count( $deduped ) );
	}

	// -------------------------------------------------------------------------
	// Page reading
	// -------------------------------------------------------------------------

	/**
	 * Fetch and strip full pages for standard depth via read_source_pages.
	 */
	public function test_read_source_pages_fetches_and_strips_html() {
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				if ( 'https://example.com/frost' === $url ) {
					return array(
						'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
						'body'     => '<html><head><title>Frost</title></head><body><p>Plant garlic six weeks before the first frost date for best results.</p></body></html>',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return $response;
			},
			10,
			3
		);

		$harness = $this->make_harness();
		$pages   = $harness->run_pages(
			array(
				array(
					'url'     => 'https://example.com/frost',
					'title'   => 'Frost date calculator',
					'snippet' => 'Count back from first frost.',
				),
			),
			'standard'
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $pages['read'] );
		$this->assertSame( 0, $pages['failed'] );
		$this->assertStringContainsString( 'Plant garlic six weeks', $pages['pages'][0]['content'] );
		$this->assertStringNotContainsString( '<html>', $pages['pages'][0]['content'] );
	}

	/**
	 * Basic depth reads no pages.
	 */
	public function test_read_source_pages_disabled_for_basic_depth() {
		$harness = $this->make_harness();
		$pages   = $harness->run_pages(
			array(
				array(
					'url'   => 'https://example.com/frost',
					'title' => 'Frost',
				),
			),
			'basic'
		);

		$this->assertSame( 0, $pages['read'] );
	}

	/**
	 * Page reads are skipped when the time budget is already spent.
	 */
	public function test_read_source_pages_skipped_when_budget_exceeded() {
		$harness = $this->make_harness();
		$pages   = $harness->run_pages(
			array(
				array(
					'url'   => 'https://example.com/frost',
					'title' => 'Frost',
				),
			),
			'standard',
			microtime( true ) - 200
		);

		$this->assertSame( 0, $pages['read'] );
		$this->assertTrue( $pages['budget_exceeded'] );
	}

	// -------------------------------------------------------------------------
	// Report diagnostics
	// -------------------------------------------------------------------------

	/**
	 * The report builder marks failed validation as partial with warnings.
	 */
	public function test_report_builder_marks_partial_on_validation_failure() {
		$harness = $this->make_harness();
		$report  = $harness->run_report(
			'garden frost dates',
			'standard',
			array(
				'content'           => 'Incomplete draft',
				'provider'          => 'deepseek',
				'model'             => 'deepseek-flash',
				'attempts'          => 1,
				'validated'         => false,
				'validation_issues' => array( 'below_min_word_count' ),
			),
			$this->search_results_fixture()
		);

		$this->assertSame( 'partial', $report['status'] );
		$this->assertContains( 'report_failed_quality_checks', $report['warnings'] );
		$this->assertFalse( $report['validation']['passed'] );
		$this->assertSame( 'standard', $report['depth'] );
		$this->assertArrayHasKey( 'elapsed_seconds', $report );
		$this->assertArrayHasKey( 'pages_read', $report );
	}

	/**
	 * A valid analysis produces a complete report with clean diagnostics.
	 */
	public function test_report_builder_marks_complete_on_valid_analysis() {
		$harness = $this->make_harness();
		$report  = $harness->run_report(
			'garden frost dates',
			'standard',
			array(
				'content'           => $this->complete_report(),
				'provider'          => 'deepseek',
				'model'             => 'deepseek-flash',
				'attempts'          => 1,
				'validated'         => true,
				'validation_issues' => array(),
			),
			$this->search_results_fixture()
		);

		$this->assertSame( 'complete', $report['status'] );
		$this->assertEmpty( $report['warnings'] );
		$this->assertTrue( $report['validation']['passed'] );
	}
}
