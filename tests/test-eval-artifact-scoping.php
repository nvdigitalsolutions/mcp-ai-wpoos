<?php
/**
 * Tests for artifact scoping across eval suites, the suite registry, and the
 * eval run store (Phase B.1 + B.4).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test eval artifact scoping.
 */
class Test_Eval_Artifact_Scoping extends WP_UnitTestCase {

	/**
	 * Clean up registry/option state.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
			WP_MCP_AI_Eval_Suite_Registry::reset_instance();
		}
		if ( class_exists( 'WP_MCP_AI_Eval_Run_Store' ) ) {
			WP_MCP_AI_Eval_Run_Store::reset_instance();
		}
	}

	/**
	 * Clean up options between tests.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Eval_Run_Store::OPTION_ARTIFACT_INDEX );
		delete_option( WP_MCP_AI_Eval_Run_Store::option_name( 'scope-suite-a' ) );
		delete_option( WP_MCP_AI_Eval_Run_Store::option_name( 'scope-suite-b' ) );
		if ( class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
			WP_MCP_AI_Eval_Suite_Registry::reset_instance();
		}
		if ( class_exists( 'WP_MCP_AI_Eval_Run_Store' ) ) {
			WP_MCP_AI_Eval_Run_Store::reset_instance();
		}

		parent::tearDown();
	}

	/**
	 * Suite round-trips artifact scoping and exposes it in to_array().
	 */
	public function test_suite_artifact_scoping_round_trip() {
		if ( ! class_exists( 'WP_MCP_AI_Eval_Suite' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Eval_Suite class not available.' );
		}

		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'          => 'scoped-suite',
				'artifact_type' => 'prompt',
				'artifact_id'   => 'assistant-42',
			)
		);

		$this->assertTrue( $suite->is_artifact_scoped() );
		$this->assertSame( 'prompt', $suite->get_artifact_type() );
		$this->assertSame( 'assistant-42', $suite->get_artifact_id() );
		$this->assertSame( 'prompt', $suite->to_array()['artifact_type'] );
	}

	/**
	 * Invalid artifact types fall back to general ('').
	 */
	public function test_suite_invalid_artifact_type_falls_back() {
		if ( ! class_exists( 'WP_MCP_AI_Eval_Suite' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Eval_Suite class not available.' );
		}

		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'          => 'invalid-scope-suite',
				'artifact_type' => 'bogus',
			)
		);

		$this->assertFalse( $suite->is_artifact_scoped() );
		$this->assertSame( '', $suite->get_artifact_type() );
	}

	/**
	 * Registry lookups partition general vs artifact-scoped suites.
	 */
	public function test_registry_artifact_lookups() {
		if ( ! class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Eval_Suite_Registry class not available.' );
		}

		$registry = WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$registry->register(
			array(
				'slug' => 'general-suite',
			)
		);
		$registry->register(
			array(
				'slug'          => 'prompt-suite',
				'artifact_type' => 'prompt',
				'artifact_id'   => 'a-1',
			)
		);
		$registry->register(
			array(
				'slug'          => 'prompt-suite-wildcard',
				'artifact_type' => 'prompt',
			)
		);

		$prompt_suites = $registry->get_suites_for_artifact( 'prompt', 'a-1' );
		$this->assertCount( 2, $prompt_suites );
		$this->assertArrayHasKey( 'prompt-suite', $prompt_suites );
		$this->assertArrayHasKey( 'prompt-suite-wildcard', $prompt_suites );

		// A different artifact ID only matches the wildcard suite.
		$other_suites = $registry->get_suites_for_artifact( 'prompt', 'a-2' );
		$this->assertCount( 1, $other_suites );
		$this->assertArrayHasKey( 'prompt-suite-wildcard', $other_suites );

		$general = $registry->get_general_suites();
		$this->assertCount( 1, $general );
		$this->assertArrayHasKey( 'general-suite', $general );

		// Unknown types return nothing.
		$this->assertSame( array(), $registry->get_suites_for_artifact( 'skill' ) );
	}

	/**
	 * Run-store records carry artifact scoping and are retrievable per artifact.
	 */
	public function test_run_store_artifact_indexing() {
		if ( ! class_exists( 'WP_MCP_AI_Eval_Run_Store' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Eval_Run_Store class not available.' );
		}

		$store = WP_MCP_AI_Eval_Run_Store::get_instance();

		$store->record(
			'scope-suite-a',
			array( 'pass_rate' => 0.8 ),
			100,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => '42',
			)
		);
		$store->record(
			'scope-suite-b',
			array( 'pass_rate' => 0.5 ),
			200,
			array(
				'artifact_type' => 'prompt',
				'artifact_id'   => '7',
			)
		);

		$for_42 = $store->get_runs_for_artifact( 'prompt', '42' );
		$this->assertCount( 1, $for_42 );
		$this->assertSame( 'scope-suite-a', $for_42[0]['slug'] );
		$this->assertSame( 'prompt', $for_42[0]['artifact_type'] );
		$this->assertSame( '42', $for_42[0]['artifact_id'] );

		// Any ID of the type (empty ID) merges all matching suites.
		$all_prompts = $store->get_runs_for_artifact( 'prompt' );
		$this->assertCount( 2, $all_prompts );
		$this->assertSame( 'scope-suite-b', $all_prompts[0]['slug'] ); // Newest first.

		// Unknown artifact types return nothing.
		$this->assertSame( array(), $store->get_runs_for_artifact( 'skill' ) );
	}

	/**
	 * Backward-compatible record() without artifact scoping.
	 */
	public function test_run_store_record_without_artifact_is_backward_compatible() {
		if ( ! class_exists( 'WP_MCP_AI_Eval_Run_Store' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Eval_Run_Store class not available.' );
		}

		$store  = WP_MCP_AI_Eval_Run_Store::get_instance();
		$record = $store->record( 'scope-suite-a', array( 'pass_rate' => 1.0 ), 300 );

		$this->assertSame( 'scope-suite-a', $record['slug'] );
		$this->assertArrayNotHasKey( 'artifact_type', $record );
	}
}
