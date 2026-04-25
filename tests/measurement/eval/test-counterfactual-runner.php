<?php
/**
 * Tests for WP_MCP_AI_Counterfactual_Runner.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * A length-scoring verifier: score = length-of-value / 100, clamped.
 * Deterministic and cheap — perfect for counterfactual asserts.
 */
class CR_Test_Length_Verifier extends WP_MCP_AI_Verifier_Base {

	public function __construct() {
		$this->slug                 = 'cr_length';
		$this->kind                 = 'rule';
		$this->label                = 'CR Length';
		$this->independence_profile = array(
			'disallowed_providers' => array(),
			'disallowed_models'    => array(),
			'disallowed_tools'     => array(),
			'allowed_domains'      => array(),
		);
	}

	public function verify( array $subject, array $context = array() ) {
		$value = array_key_exists( 'value', $subject ) ? $subject['value'] : '';
		$len   = is_string( $value ) ? strlen( $value ) : ( is_array( $value ) ? count( $value ) : 0 );
		$score = min( 1.0, $len / 100.0 );
		return $score >= 0.5
			? $this->result_pass( $score, 1.0, array() )
			: $this->result_fail( $score, 1.0, array() );
	}
}

/**
 * A flat verifier that always returns the same score — the canonical
 * "no discriminative signal" case the counterfactual helper exists
 * to surface.
 */
class CR_Test_Flat_Verifier extends WP_MCP_AI_Verifier_Base {

	public function __construct() {
		$this->slug                 = 'cr_flat';
		$this->kind                 = 'rule';
		$this->label                = 'CR Flat';
		$this->independence_profile = array(
			'disallowed_providers' => array(),
			'disallowed_models'    => array(),
			'disallowed_tools'     => array(),
			'allowed_domains'      => array(),
		);
	}

	public function verify( array $subject, array $context = array() ) {
		return $this->result_pass( 0.42, 1.0, array() );
	}
}

/**
 * Counterfactual runner tests.
 */
class Test_WP_MCP_AI_Counterfactual_Runner extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_Verifier_Registry
	 */
	private $verifiers;

	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Verifier_Registry::reset_instance();
		$this->verifiers = WP_MCP_AI_Verifier_Registry::get_instance();
		$this->verifiers->register( new CR_Test_Length_Verifier() );
		$this->verifiers->register( new CR_Test_Flat_Verifier() );
	}

	public function tearDown(): void {
		WP_MCP_AI_Verifier_Registry::reset_instance();
		parent::tearDown();
	}

	public function test_candidate_wins_strictly_over_truncated_variant() {
		$runner = new WP_MCP_AI_Counterfactual_Runner( $this->verifiers );

		$subject = array( 'value' => str_repeat( 'a', 100 ) ); // Max-scoring.
		$report  = $runner->run( 'cr_length', $subject, array( 'truncate_to_prefix' ) );

		$this->assertTrue( $report['preferred'], 'Candidate must win strictly over its truncated variant.' );
		$this->assertFalse( $report['flat'] );
		$this->assertEqualsWithDelta( 1.0, $report['candidate_score'], 0.001 );
		$this->assertLessThan( 1.0, $report['variant_scores']['truncate_to_prefix'] );
	}

	public function test_flat_verifier_is_flagged_as_flat() {
		$runner = new WP_MCP_AI_Counterfactual_Runner( $this->verifiers );

		$subject = array( 'value' => str_repeat( 'b', 50 ) );
		$report  = $runner->run( 'cr_flat', $subject, array( 'shuffle_tokens', 'truncate_to_prefix' ) );

		$this->assertFalse( $report['preferred'] );
		$this->assertTrue( $report['flat'], 'Flat verifier must be flagged as having no discriminative signal.' );
		$this->assertNotEmpty( $report['reasons'] );
	}

	public function test_verifier_error_records_error_not_preference() {
		$runner = new WP_MCP_AI_Counterfactual_Runner( $this->verifiers );
		$report = $runner->run( 'no_such_verifier', array( 'value' => 'x' ), array( 'shuffle_tokens' ) );

		$this->assertFalse( $report['preferred'] );
		$this->assertArrayHasKey( 'error', $report );
	}

	public function test_inline_callable_variant_is_supported() {
		$runner = new WP_MCP_AI_Counterfactual_Runner( $this->verifiers );

		$subject = array( 'value' => str_repeat( 'a', 80 ) );
		$report  = $runner->run(
			'cr_length',
			$subject,
			array(
				static function () {
					return 'x';
				},
			)
		);

		$this->assertTrue( $report['preferred'] );
		$this->assertArrayHasKey( 'inline', $report['variant_scores'] );
	}

	public function test_unknown_degrader_slug_is_skipped_with_reason() {
		$runner  = new WP_MCP_AI_Counterfactual_Runner( $this->verifiers );
		$subject = array( 'value' => str_repeat( 'a', 80 ) );
		$report  = $runner->run( 'cr_length', $subject, array( 'not_a_real_degrader' ) );

		$this->assertFalse( $report['preferred'], 'No usable variants means no preference.' );
		$this->assertArrayNotHasKey( 'not_a_real_degrader', $report['variant_scores'] );
		$this->assertNotEmpty( $report['reasons'] );
	}

	public function test_stock_degraders_filter_extends_catalogue() {
		add_filter(
			'wp_mcp_ai_counterfactual_degraders',
			static function ( $d ) {
				$d['test_constant'] = static function () {
					return 'zz';
				};
				return $d;
			}
		);
		try {
			$runner = new WP_MCP_AI_Counterfactual_Runner( $this->verifiers );
			$report = $runner->run( 'cr_length', array( 'value' => str_repeat( 'a', 100 ) ), array( 'test_constant' ) );
			$this->assertArrayHasKey( 'test_constant', $report['variant_scores'] );
			$this->assertEqualsWithDelta( 0.02, $report['variant_scores']['test_constant'], 0.001 );
		} finally {
			remove_all_filters( 'wp_mcp_ai_counterfactual_degraders' );
		}
	}

	public function test_degrade_shuffle_tokens_reverses_multi_token_string() {
		$out = WP_MCP_AI_Counterfactual_Runner::degrade_shuffle_tokens( 'one two three' );
		$this->assertSame( 'three two one', $out );

		// Single token → returned unchanged.
		$this->assertSame( 'word', WP_MCP_AI_Counterfactual_Runner::degrade_shuffle_tokens( 'word' ) );

		// Array → reversed.
		$this->assertSame( array( 'c', 'b', 'a' ), WP_MCP_AI_Counterfactual_Runner::degrade_shuffle_tokens( array( 'a', 'b', 'c' ) ) );
	}

	public function test_degrade_drop_citations_removes_markdown_and_urls() {
		$text = 'Citing [src](https://a.com) and [1] see https://b.com for more';
		$out  = WP_MCP_AI_Counterfactual_Runner::degrade_drop_citations( $text );
		$this->assertStringNotContainsString( '[src]', $out );
		$this->assertStringNotContainsString( '[1]', $out );
		$this->assertStringNotContainsString( 'https://', $out );
	}

	public function test_degrade_truncate_to_prefix_keeps_first_quarter() {
		$text = str_repeat( 'a', 100 );
		$out  = WP_MCP_AI_Counterfactual_Runner::degrade_truncate_to_prefix( $text );
		$this->assertSame( 25, strlen( $out ) );
	}
}
