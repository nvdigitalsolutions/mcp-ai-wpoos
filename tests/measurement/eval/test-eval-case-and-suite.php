<?php
/**
 * Tests for the Eval Case & Suite value objects.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Eval Case and Eval Suite.
 */
class Test_WP_MCP_AI_Eval_Case_And_Suite extends WP_UnitTestCase {

	public function test_case_requires_slug() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Eval_Case( array( 'verifier_slug' => 'rule_verifier' ) );
	}

	public function test_case_requires_verifier_slug() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Eval_Case( array( 'slug' => 'c1' ) );
	}

	public function test_case_defaults() {
		$c = new WP_MCP_AI_Eval_Case(
			array(
				'slug'          => 'c1',
				'verifier_slug' => 'schema_verifier',
			)
		);
		$this->assertSame( 'c1', $c->get_slug() );
		$this->assertSame( 'c1', $c->get_label() );
		$this->assertSame( array(), $c->get_input() );
		$this->assertNull( $c->get_expected() );
		$this->assertNull( $c->get_target_confidence() );
	}

	public function test_case_metadata_and_target_confidence() {
		$c = new WP_MCP_AI_Eval_Case(
			array(
				'slug'              => 'c1',
				'label'             => 'Case 1',
				'verifier_slug'     => 'rule_verifier',
				'input'             => array( 'q' => 'hi' ),
				'expected'          => 'hi there',
				'verifier_args'     => array( 'opt' => true ),
				'metadata'          => array( 'difficulty' => 'easy' ),
				'target_confidence' => 0.8,
			)
		);
		$this->assertSame( 'Case 1', $c->get_label() );
		$this->assertSame( array( 'q' => 'hi' ), $c->get_input() );
		$this->assertSame( 'hi there', $c->get_expected() );
		$this->assertSame( 'easy', $c->get_metadata()['difficulty'] );
		$this->assertSame( 0.8, $c->get_target_confidence() );
	}

	public function test_suite_requires_slug() {
		$this->expectException( 'InvalidArgumentException' );
		new WP_MCP_AI_Eval_Suite( array() );
	}

	public function test_suite_accepts_cases_as_arrays_or_instances() {
		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => 's1',
				'cases' => array(
					array( 'slug' => 'a', 'verifier_slug' => 'rule_verifier' ),
					new WP_MCP_AI_Eval_Case( array( 'slug' => 'b', 'verifier_slug' => 'rule_verifier' ) ),
				),
			)
		);
		$this->assertSame( 2, $suite->count_cases() );
		$this->assertNotNull( $suite->get_case( 'a' ) );
		$this->assertNotNull( $suite->get_case( 'b' ) );
	}

	public function test_duplicate_case_slug_replaces_earlier() {
		$suite = new WP_MCP_AI_Eval_Suite( array( 'slug' => 's1' ) );
		$suite->add_case( new WP_MCP_AI_Eval_Case( array( 'slug' => 'a', 'verifier_slug' => 'rule_verifier', 'label' => 'old' ) ) );
		$suite->add_case( new WP_MCP_AI_Eval_Case( array( 'slug' => 'a', 'verifier_slug' => 'rule_verifier', 'label' => 'new' ) ) );
		$this->assertSame( 1, $suite->count_cases() );
		$this->assertSame( 'new', $suite->get_case( 'a' )->get_label() );
	}

	public function test_suite_tags_filtered_to_scalars() {
		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug' => 's1',
				'tags' => array( 'easy', 42, array( 'nope' ), null, 'gsm8k' ),
			)
		);
		$tags = $suite->get_tags();
		$this->assertContains( 'easy', $tags );
		$this->assertContains( '42', $tags );
		$this->assertContains( 'gsm8k', $tags );
		$this->assertCount( 3, $tags );
	}
}
