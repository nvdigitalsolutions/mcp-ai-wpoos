<?php
/**
 * Tests for the harness PII / secret filter, reasoning trace, tool router,
 * retrieval harness, and self-refine loop.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * PII / secret filter tests.
 *
 * @since 1.4.0
 */
class Test_Harness_Pii_Filter extends WP_UnitTestCase {

	public function test_email_is_redacted() {
		$r = WP_MCP_AI_Pii_Filter::scrub( 'Contact me at jane.doe@example.com please.' );
		$this->assertSame( 'Contact me at [REDACTED_EMAIL] please.', $r['text'] );
		$this->assertSame( 1, $r['redactions'] );
	}

	public function test_us_phone_is_redacted() {
		$r = WP_MCP_AI_Pii_Filter::scrub( 'Call (415) 555-1234 today.' );
		$this->assertStringContainsString( '[REDACTED_PHONE]', $r['text'] );
		$this->assertGreaterThanOrEqual( 1, $r['redactions'] );
	}

	public function test_ssn_is_redacted() {
		$r = WP_MCP_AI_Pii_Filter::scrub( 'SSN 123-45-6789 on file.' );
		$this->assertStringContainsString( '[REDACTED_SSN]', $r['text'] );
	}

	public function test_openai_api_key_is_redacted() {
		$key = 'sk-' . str_repeat( 'A', 40 );
		$r   = WP_MCP_AI_Pii_Filter::scrub( "Bearer key is {$key} keep it safe." );
		$this->assertStringContainsString( '[REDACTED_KEY]', $r['text'] );
		$this->assertStringNotContainsString( $key, $r['text'] );
	}

	public function test_clean_text_passes_through() {
		$r = WP_MCP_AI_Pii_Filter::scrub( 'Hello world, no secrets here.' );
		$this->assertSame( 'Hello world, no secrets here.', $r['text'] );
		$this->assertSame( 0, $r['redactions'] );
	}

	public function test_contains_secret_predicate() {
		$this->assertTrue( WP_MCP_AI_Pii_Filter::contains_secret( 'Email: foo@bar.io' ) );
		$this->assertFalse( WP_MCP_AI_Pii_Filter::contains_secret( 'Just plain prose, nothing to see.' ) );
	}

	public function test_filter_can_extend_patterns() {
		add_filter(
			'wp_mcp_ai_pii_filter_patterns',
			function ( $patterns ) {
				$patterns[] = array( '/HUSH-\d+/', '[REDACTED_INTERNAL]' );
				return $patterns;
			}
		);
		$r = WP_MCP_AI_Pii_Filter::scrub( 'See ticket HUSH-42 for details.' );
		$this->assertStringContainsString( '[REDACTED_INTERNAL]', $r['text'] );
		remove_all_filters( 'wp_mcp_ai_pii_filter_patterns' );
	}
}

/**
 * Reasoning trace tests.
 *
 * @since 1.4.0
 */
class Test_Harness_Reasoning_Trace extends WP_UnitTestCase {

	public function test_new_trace_has_canonical_keys() {
		$t = WP_MCP_AI_Reasoning_Trace::new_trace();
		foreach ( array( 'assumptions', 'constraints', 'plan', 'intermediate_results', 'verification', 'answer' ) as $key ) {
			$this->assertArrayHasKey( $key, $t );
		}
		$this->assertSame( '1.0', $t['schema_version'] );
	}

	public function test_sanitize_caps_list_growth() {
		$big = array();
		for ( $i = 0; $i < 200; ++$i ) {
			$big[] = "step {$i}";
		}
		$t = WP_MCP_AI_Reasoning_Trace::sanitize( array( 'plan' => $big ) );
		$this->assertCount( 50, $t['plan'] );
	}

	public function test_sanitize_drops_blank_list_entries() {
		$t = WP_MCP_AI_Reasoning_Trace::sanitize( array( 'assumptions' => array( '  ', 'real one', '' ) ) );
		$this->assertSame( array( 'real one' ), $t['assumptions'] );
	}

	public function test_self_consistency_picks_majority() {
		$result = WP_MCP_AI_Reasoning_Trace::self_consistency_vote(
			array( '42', '42', '42', '7', '13' )
		);
		$this->assertSame( '42', $result['answer'] );
		$this->assertSame( 5, $result['total'] );
		$this->assertEqualsWithDelta( 0.6, $result['agreement'], 0.0001 );
	}

	public function test_self_consistency_normalizes_whitespace_and_case() {
		$result = WP_MCP_AI_Reasoning_Trace::self_consistency_vote(
			array( 'Yes', '  yes  ', 'YES', 'No' )
		);
		$this->assertSame( 'Yes', $result['answer'] );
		$this->assertEqualsWithDelta( 0.75, $result['agreement'], 0.0001 );
	}

	public function test_self_consistency_handles_empty_input() {
		$result = WP_MCP_AI_Reasoning_Trace::self_consistency_vote( array() );
		$this->assertSame( '', $result['answer'] );
		$this->assertSame( 0, $result['total'] );
	}
}

/**
 * Self-Refine loop tests.
 *
 * @since 1.4.0
 */
class Test_Harness_Self_Refine_Loop extends WP_UnitTestCase {

	public function test_accept_verdict_stops_early() {
		$gen_calls = 0;
		$generator = function ( $task, $prev = null, $crit = null ) use ( &$gen_calls ) {
			++$gen_calls;
			return 'final answer';
		};
		$critic = function ( $task, $candidate ) {
			return array(
				'verdict'  => 'accept',
				'feedback' => '',
			);
		};
		$result = WP_MCP_AI_Self_Refine_Loop::run( 'compute 2+2', $generator, $critic, array( 'max_iters' => 3 ) );
		$this->assertSame( 'final answer', $result['answer'] );
		$this->assertSame( 'accepted', $result['stopped_reason'] );
		$this->assertSame( 1, $gen_calls );
	}

	public function test_revise_then_accept() {
		$gen_calls = 0;
		$generator = function ( $task, $prev = null, $crit = null ) use ( &$gen_calls ) {
			++$gen_calls;
			return 1 === $gen_calls ? 'first try' : 'better try';
		};
		$state  = array( 'count' => 0 );
		$critic = function ( $task, $candidate ) use ( &$state ) {
			++$state['count'];
			if ( 1 === $state['count'] ) {
				return array(
					'verdict'  => 'revise',
					'feedback' => 'try again with detail',
				);
			}
			return array(
				'verdict'  => 'accept',
				'feedback' => '',
			);
		};
		$result = WP_MCP_AI_Self_Refine_Loop::run( 'task', $generator, $critic, array( 'max_iters' => 3 ) );
		$this->assertSame( 'better try', $result['answer'] );
		$this->assertSame( 'accepted', $result['stopped_reason'] );
		$this->assertSame( 2, $gen_calls );
	}

	public function test_max_iters_is_clamped() {
		$generator = function ( $task, $prev = null, $crit = null ) {
			return 'x';
		};
		$critic = function ( $task, $candidate ) {
			return array(
				'verdict'  => 'revise',
				'feedback' => 'keep going',
			);
		};
		$result = WP_MCP_AI_Self_Refine_Loop::run(
			'task',
			$generator,
			$critic,
			array( 'max_iters' => 999 )
		);
		// Iterations should never exceed the hard cap.
		$this->assertLessThanOrEqual( WP_MCP_AI_Harness_Profile::MAX_REFINE_ITERATIONS, $result['iterations'] );
	}

	public function test_cost_ceiling_aborts_loop() {
		$generator = function ( $task, $prev = null, $crit = null ) {
			return 'x';
		};
		$critic = function ( $task, $candidate ) {
			return array(
				'verdict'  => 'revise',
				'feedback' => 'more',
			);
		};
		$result = WP_MCP_AI_Self_Refine_Loop::run(
			'task',
			$generator,
			$critic,
			array(
				'max_iters'     => 4,
				'cost_per_iter' => 0.5,
				'cost_ceiling'  => 1.0,
			)
		);
		$this->assertSame( 'cost_ceiling', $result['stopped_reason'] );
	}

	public function test_empty_task_returns_wp_error() {
		$result = WP_MCP_AI_Self_Refine_Loop::run(
			'',
			function () {
				return '';
			},
			function () {
				return array( 'verdict' => 'accept' );
			}
		);
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_generator_wp_error_propagates() {
		$gen = function () {
			return new WP_Error( 'boom', 'generator failed' );
		};
		$crit = function () {
			return array( 'verdict' => 'accept' );
		};
		$result = WP_MCP_AI_Self_Refine_Loop::run( 'task', $gen, $crit );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'boom', $result->get_error_code() );
	}
}

/**
 * Tool Router scoring tests.
 *
 * @since 1.4.0
 */
class Test_Harness_Tool_Router extends WP_UnitTestCase {

	public function test_read_only_tool_outranks_state_changing_for_qa() {
		$read   = $this->build_stub_tool( 'reader', array( 'read-only' ) );
		$write  = $this->build_stub_tool( 'writer', array( 'write', 'state-changing' ) );
		$ranked = WP_MCP_AI_Tool_Router_Harness::rank( array( $read, $write ), 'qa' );
		$keys   = array_keys( $ranked );
		$this->assertSame( 'reader', reset( $keys ) );
		$this->assertGreaterThan( $ranked['writer'], $ranked['reader'] );
	}

	public function test_assistant_preferences_boost_score() {
		$tool       = $this->build_stub_tool( 'preferred', array( 'read-only' ) );
		$base_score = WP_MCP_AI_Tool_Router_Harness::score_tool( $tool, 'general' );
		$boosted    = WP_MCP_AI_Tool_Router_Harness::score_tool( $tool, 'general', array( 'preferred' => 5.0 ) );
		$this->assertEqualsWithDelta( $base_score + 5.0, $boosted, 0.0001 );
	}

	public function test_filter_can_override_score() {
		$tool = $this->build_stub_tool( 'filtered', array( 'read-only' ) );
		add_filter(
			'wp_mcp_ai_harness_tool_score',
			function ( $score ) {
				return $score + 100.0;
			}
		);
		$score = WP_MCP_AI_Tool_Router_Harness::score_tool( $tool, 'general' );
		$this->assertGreaterThan( 100.0, $score );
		remove_all_filters( 'wp_mcp_ai_harness_tool_score' );
	}

	/**
	 * Layer C preset-weights matrix: a tool that belongs to a weighted preset
	 * receives the preset's weight added to its base score.
	 */
	public function test_preset_weight_boosts_tool_in_that_family() {
		// `web_search` is a member of `agentic_workflow`, `research`, and
		// `seo_marketing` in the canonical preset library.
		$tool = $this->build_stub_tool( 'web_search', array( 'read-only', 'external-api' ) );
		$base = WP_MCP_AI_Tool_Router_Harness::score_tool( $tool, 'research' );

		$boosted = WP_MCP_AI_Tool_Router_Harness::score_tool(
			$tool,
			'research',
			array(),
			array( 'agentic_workflow' => 2.5 )
		);

		$this->assertEqualsWithDelta( $base + 2.5, $boosted, 0.0001 );
	}

	/**
	 * A tool slug that is not present in any preset must not gain (or lose)
	 * any score from the preset-weights matrix.
	 */
	public function test_preset_weight_ignored_for_tool_outside_family() {
		$tool = $this->build_stub_tool( 'made_up_tool_xyz', array( 'read-only' ) );
		$base = WP_MCP_AI_Tool_Router_Harness::score_tool( $tool, 'general' );

		$weighted = WP_MCP_AI_Tool_Router_Harness::score_tool(
			$tool,
			'general',
			array(),
			array( 'agentic_workflow' => 5.0 )
		);

		$this->assertEqualsWithDelta( $base, $weighted, 0.0001 );
	}

	/**
	 * Negative preset weights must dampen tools in that family (Goodhart
	 * mitigation — admins can opt out of an entire family).
	 */
	public function test_negative_preset_weight_dampens_family() {
		$tool = $this->build_stub_tool( 'web_search', array( 'read-only', 'external-api' ) );
		$base = WP_MCP_AI_Tool_Router_Harness::score_tool( $tool, 'research' );

		$dampened = WP_MCP_AI_Tool_Router_Harness::score_tool(
			$tool,
			'research',
			array(),
			array( 'agentic_workflow' => -3.0 )
		);

		$this->assertEqualsWithDelta( $base - 3.0, $dampened, 0.0001 );
	}

	/**
	 * Profile sanitizer must clamp preset weights into [-5, 5], drop zero
	 * entries, and reject non-string slugs without erroring.
	 */
	public function test_profile_sanitizer_clamps_and_normalizes_preset_weights() {
		$raw = array(
			'enabled' => true,
			'tools'   => array(
				'router'         => 'scored',
				'preset_weights' => array(
					'agentic_workflow' => 2.5,
					'ecommerce'        => 99.0,   // clamp to 5.
					'content_writing'  => -42.0,  // clamp to -5.
					'site_management'  => 0,      // dropped.
					''                 => 1.0,    // empty slug dropped.
				),
			),
		);

		$clean = WP_MCP_AI_Harness_Profile::sanitize( $raw );

		$this->assertArrayHasKey( 'preset_weights', $clean['tools'] );
		$weights = $clean['tools']['preset_weights'];
		$this->assertEqualsWithDelta( 2.5, $weights['agentic_workflow'], 0.0001 );
		$this->assertEqualsWithDelta( 5.0, $weights['ecommerce'], 0.0001 );
		$this->assertEqualsWithDelta( -5.0, $weights['content_writing'], 0.0001 );
		$this->assertArrayNotHasKey( 'site_management', $weights );
		$this->assertArrayNotHasKey( '', $weights );
	}

	/**
	 * Existing 3-arg call sites of score_tool() must continue to work
	 * unchanged (back-compat for the public Pro-extension surface).
	 */
	public function test_score_tool_three_arg_signature_still_works() {
		$tool  = $this->build_stub_tool( 'compat', array( 'read-only' ) );
		$score = WP_MCP_AI_Tool_Router_Harness::score_tool( $tool, 'qa', array( 'compat' => 1.0 ) );
		$this->assertGreaterThan( 1.0, $score );
	}

	/**
	 * Build a stub tool with the given slug and capability flags.
	 *
	 * @param string $slug  Tool slug.
	 * @param array  $flags Capability flags.
	 * @return WP_MCP_AI_Tool_Interface
	 */
	private function build_stub_tool( $slug, array $flags ) {
		return new class( $slug, $flags ) implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
			use WP_MCP_AI_Tool_Default_Capability;
			private $slug;
			private $flags;
			public function __construct( $slug, array $flags ) {
				$this->slug  = $slug;
				$this->flags = $flags;
			}
			public function get_slug() {
				return $this->slug; }
			public function get_name() {
				return $this->slug; }
			public function get_description() {
				return ''; }
			public function get_parameters_schema() {
				return array(); }
			public function execute( array $arguments = array(), array $context = array() ) {
				return array(); }
			public function get_capability_flags() {
				return $this->flags; }
		};
	}
}

/**
 * Retrieval harness tests (no underlying tools needed — sources_tried is
 * exercised via the public surface; verify_citations is the unit-testable core).
 *
 * @since 1.4.0
 */
class Test_Harness_Retrieval extends WP_UnitTestCase {

	public function test_verify_citations_passes_when_passages_overlap() {
		$answer   = 'The capital of France is Paris because Paris is the capital of France.';
		$passages = array(
			array( 'text' => 'Paris is the capital of France and home to the Eiffel Tower.' ),
		);
		$result = WP_MCP_AI_Retrieval_Harness::verify_citations( $answer, $passages );
		$this->assertTrue( $result['covered'] );
		$this->assertSame( 1.0, $result['coverage_ratio'] );
	}

	public function test_verify_citations_fails_for_unsupported_claim() {
		$answer   = 'The Eiffel Tower was built by Martians using alien technology.';
		$passages = array(
			array( 'text' => 'Paris is the capital of France and home to the Eiffel Tower.' ),
		);
		$result = WP_MCP_AI_Retrieval_Harness::verify_citations( $answer, $passages );
		$this->assertFalse( $result['covered'] );
		$this->assertNotEmpty( $result['unsupported'] );
	}

	public function test_verify_citations_handles_empty_answer() {
		$result = WP_MCP_AI_Retrieval_Harness::verify_citations( '', array() );
		$this->assertTrue( $result['covered'] );
	}

	public function test_retrieve_returns_well_formed_payload_when_no_tools() {
		// In the test environment retrieval source tools may not be registered;
		// the harness must still return a valid (possibly empty) shape.
		$result = WP_MCP_AI_Retrieval_Harness::retrieve( 'unit-test query', array(), 5 );
		$this->assertArrayHasKey( 'passages', $result );
		$this->assertArrayHasKey( 'citations', $result );
		$this->assertArrayHasKey( 'sources_tried', $result );
		$this->assertIsArray( $result['passages'] );
	}
}
