<?php
/**
 * Tests for the Rule Verifier.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Rule Verifier.
 */
class Test_WP_MCP_AI_Rule_Verifier extends WP_UnitTestCase {

	/**
	 * No rules => trivial pass.
	 */
	public function test_empty_rules_passes() {
		$verifier = new WP_MCP_AI_Rule_Verifier();
		$result   = $verifier->verify( array( 'any' => 'thing' ) );
		$this->assertTrue( $result['passed'] );
		$this->assertSame( 1.0, $result['score'] );
	}

	/**
	 * Required rule at a dotted path.
	 */
	public function test_required_rule() {
		$verifier = new WP_MCP_AI_Rule_Verifier(
			'rule_verifier',
			array(
				array( 'type' => 'required', 'path' => 'answer.text' ),
			)
		);
		$this->assertFalse( $verifier->verify( array( 'answer' => array() ) )['passed'] );
		$this->assertTrue( $verifier->verify( array( 'answer' => array( 'text' => 'hi' ) ) )['passed'] );
	}

	/**
	 * Pattern rule.
	 */
	public function test_pattern_rule() {
		$verifier = new WP_MCP_AI_Rule_Verifier(
			'rule_verifier',
			array(
				array( 'type' => 'pattern', 'path' => 'url', 'value' => '#^https://#' ),
			)
		);
		$this->assertTrue( $verifier->verify( array( 'url' => 'https://example.com' ) )['passed'] );
		$this->assertFalse( $verifier->verify( array( 'url' => 'http://example.com' ) )['passed'] );
	}

	/**
	 * Enum rule.
	 */
	public function test_enum_rule() {
		$verifier = new WP_MCP_AI_Rule_Verifier(
			'rule_verifier',
			array(
				array( 'type' => 'enum', 'path' => 'status', 'value' => array( 'ok', 'warn' ) ),
			)
		);
		$this->assertTrue( $verifier->verify( array( 'status' => 'ok' ) )['passed'] );
		$this->assertFalse( $verifier->verify( array( 'status' => 'bad' ) )['passed'] );
	}

	/**
	 * Min / max numeric rules.
	 */
	public function test_min_max_rules() {
		$verifier = new WP_MCP_AI_Rule_Verifier(
			'rule_verifier',
			array(
				array( 'type' => 'min', 'path' => 'score', 'value' => 0 ),
				array( 'type' => 'max', 'path' => 'score', 'value' => 1 ),
			)
		);
		$this->assertTrue( $verifier->verify( array( 'score' => 0.5 ) )['passed'] );
		$this->assertFalse( $verifier->verify( array( 'score' => 1.5 ) )['passed'] );
	}

	/**
	 * Callback rule.
	 */
	public function test_callback_rule() {
		$verifier = new WP_MCP_AI_Rule_Verifier(
			'rule_verifier',
			array(
				array(
					'type'     => 'callback',
					'path'     => 'value',
					'callback' => static function ( $v ) {
						return is_int( $v ) && ( $v % 2 === 0 );
					},
				),
			)
		);
		$this->assertTrue( $verifier->verify( array( 'value' => 4 ) )['passed'] );
		$this->assertFalse( $verifier->verify( array( 'value' => 5 ) )['passed'] );
	}

	/**
	 * Weighted rules produce partial score.
	 */
	public function test_weighted_partial_score() {
		$verifier = new WP_MCP_AI_Rule_Verifier(
			'rule_verifier',
			array(
				array( 'type' => 'required', 'path' => 'a', 'weight' => 3 ),
				array( 'type' => 'required', 'path' => 'b', 'weight' => 1 ),
			)
		);
		$res = $verifier->verify( array( 'a' => 'x' ) );
		$this->assertFalse( $res['passed'] );
		$this->assertEqualsWithDelta( 0.75, $res['score'], 0.0001 );
	}

	/**
	 * Invalid rule definitions are silently dropped (no crash).
	 */
	public function test_invalid_rules_dropped() {
		$verifier = new WP_MCP_AI_Rule_Verifier(
			'rule_verifier',
			array(
				array( 'type' => 'not-a-type', 'path' => 'x' ),
				array( 'type' => 'callback', 'path' => 'x' ), // missing callback.
				'not an array',
			)
		);
		$this->assertSame( array(), $verifier->get_rules() );
		$result = $verifier->verify( array() );
		$this->assertTrue( $result['passed'] );
	}

	/**
	 * Pattern rule with invalid regex returns fail, not error.
	 */
	public function test_invalid_pattern_returns_fail() {
		$verifier = new WP_MCP_AI_Rule_Verifier(
			'rule_verifier',
			array(
				array( 'type' => 'pattern', 'path' => 'x', 'value' => '(((' ),
			)
		);
		$result = $verifier->verify( array( 'x' => 'y' ) );
		$this->assertFalse( $result['passed'] );
	}
}
