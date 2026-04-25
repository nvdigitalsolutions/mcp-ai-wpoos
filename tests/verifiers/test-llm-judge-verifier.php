<?php
/**
 * Tests for the LLM Judge Verifier.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test LLM Judge Verifier.
 */
class Test_WP_MCP_AI_LLM_Judge_Verifier extends WP_UnitTestCase {

	/**
	 * Tear down filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_llm_judge_callable' );
		parent::tearDown();
	}

	/**
	 * No callable configured => abstention result.
	 */
	public function test_abstains_without_callable() {
		$v      = new WP_MCP_AI_LLM_Judge_Verifier();
		$result = $v->verify( array( 'text' => 'anything' ) );
		$this->assertFalse( $result['passed'] );
		$this->assertSame( 0.5, $result['score'] );
		$this->assertSame( 0.0, $result['confidence'] );
		$this->assertTrue( $result['evidence']['abstained'] );
	}

	/**
	 * Callable via constructor.
	 */
	public function test_constructor_callable() {
		$v = new WP_MCP_AI_LLM_Judge_Verifier(
			'llm_judge',
			static function () {
				return array(
					'passed'     => true,
					'score'      => 0.9,
					'confidence' => 0.7,
					'reasons'    => array( 'looks good' ),
				);
			}
		);
		$result = $v->verify( array() );
		$this->assertTrue( $result['passed'] );
		$this->assertSame( 0.9, $result['score'] );
		$this->assertSame( 0.7, $result['confidence'] );
		$this->assertSame( array( 'looks good' ), $result['reasons'] );
	}

	/**
	 * Filter can substitute the callable without subclassing.
	 */
	public function test_filter_substitutes_callable() {
		$v = new WP_MCP_AI_LLM_Judge_Verifier();
		add_filter(
			'wp_mcp_ai_llm_judge_callable',
			static function () {
				return static function () {
					return array( 'passed' => false, 'score' => 0.1, 'confidence' => 0.9, 'reasons' => array( 'bad' ) );
				};
			}
		);
		$result = $v->verify( array() );
		$this->assertFalse( $result['passed'] );
		$this->assertSame( 0.1, $result['score'] );
	}

	/**
	 * Non-array return is a WP_Error.
	 */
	public function test_invalid_return_is_error() {
		$v = new WP_MCP_AI_LLM_Judge_Verifier(
			'llm_judge',
			static function () {
				return 'bad';
			}
		);
		$result = $v->verify( array() );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * WP_Error from callable is passed through.
	 */
	public function test_wp_error_passthrough() {
		$v = new WP_MCP_AI_LLM_Judge_Verifier(
			'llm_judge',
			static function () {
				return new WP_Error( 'boom', 'oops' );
			}
		);
		$this->assertInstanceOf( 'WP_Error', $v->verify( array() ) );
	}

	/**
	 * Score / confidence get clamped; unexpected reason types filtered.
	 */
	public function test_result_normalization() {
		$v = new WP_MCP_AI_LLM_Judge_Verifier(
			'llm_judge',
			static function () {
				return array(
					'passed'     => true,
					'score'      => 5.0,
					'confidence' => -1.0,
					'reasons'    => array( 'ok', array( 'nested' ), null, 123 ),
				);
			}
		);
		$result = $v->verify( array() );
		$this->assertSame( 1.0, $result['score'] );
		$this->assertSame( 0.0, $result['confidence'] );
		// Nested arrays filtered, scalars preserved as strings.
		$this->assertContains( 'ok', $result['reasons'] );
		$this->assertContains( '123', $result['reasons'] );
	}

	/**
	 * Independence profile from constructor flows through.
	 */
	public function test_independence_profile() {
		$v = new WP_MCP_AI_LLM_Judge_Verifier(
			'llm_judge',
			null,
			array( 'disallowed_providers' => array( 'openai' ) )
		);
		$profile = $v->get_independence_profile();
		$this->assertContains( 'openai', $profile['disallowed_providers'] );
	}
}
