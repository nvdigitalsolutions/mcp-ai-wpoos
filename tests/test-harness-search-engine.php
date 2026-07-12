<?php
/**
 * Tests for the Harness Search Engine.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Harness Search Engine tests.
 *
 * @since 1.9.0
 */
class Test_Harness_Search_Engine extends WP_UnitTestCase {

	private $assistant_id;

	public function setUp(): void {
		parent::setUp();
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		// Set up a minimal seed profile.
		$seed            = WP_MCP_AI_Harness_Profile::defaults();
		$seed['enabled'] = true;
		WP_MCP_AI_Harness_Profile::save( $this->assistant_id, $seed );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_validate_candidate_accepts_valid_profile() {
		$profile                   = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled']        = true;
		$profile['retrieval']['k'] = 10;

		$result = WP_MCP_AI_Harness_Search_Engine::validate_candidate( $profile );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['enabled'] );
		$this->assertSame( 10, $result['retrieval']['k'] );
	}

	public function test_validate_candidate_rejects_disabled() {
		$profile = WP_MCP_AI_Harness_Profile::defaults();
		// enabled is false by default.

		$result = WP_MCP_AI_Harness_Search_Engine::validate_candidate( $profile );
		$this->assertWPError( $result );
	}

	public function test_validate_candidate_rejects_empty() {
		$result = WP_MCP_AI_Harness_Search_Engine::validate_candidate( array() );
		$this->assertWPError( $result );
	}

	public function test_compute_pareto_frontier_returns_frontier() {
		$population = array(
			'hash_a' => array(
				'hash'      => 'hash_a',
				'profile'   => array( 'enabled' => true ),
				'eval'      => array(
					'aggregate' => array(
						'score'              => 0.9,
						'context_tokens'     => 100,
						'estimated_cost_usd' => 0.01,
					),
				),
				'iteration' => 1,
			),
			'hash_b' => array(
				'hash'      => 'hash_b',
				'profile'   => array( 'enabled' => true ),
				'eval'      => array(
					'aggregate' => array(
						'score'              => 0.7,
						'context_tokens'     => 50,
						'estimated_cost_usd' => 0.005,
					),
				),
				'iteration' => 1,
			),
			'hash_c' => array(
				'hash'      => 'hash_c',
				'profile'   => array( 'enabled' => true ),
				'eval'      => array(
					'aggregate' => array(
						'score'              => 0.5,
						'context_tokens'     => 200,
						'estimated_cost_usd' => 0.02,
					),
				),
				'iteration' => 1,
			),
		);

		$frontier = WP_MCP_AI_Harness_Search_Engine::compute_pareto_frontier( $population );
		$this->assertNotEmpty( $frontier );

		// hash_c (0.5, 200, 0.02) should be dominated by hash_a (0.9, 100, 0.01).
		$hashes = array_column( $frontier, 'hash' );
		$this->assertContains( 'hash_a', $hashes );
		$this->assertContains( 'hash_b', $hashes );
		$this->assertNotContains( 'hash_c', $hashes );
	}

	public function test_compute_pareto_frontier_ignores_unevaluated() {
		$population = array(
			'hash_a' => array(
				'hash'      => 'hash_a',
				'profile'   => array( 'enabled' => true ),
				'eval'      => array(
					'aggregate' => array(
						'score'              => 0.9,
						'context_tokens'     => 0,
						'estimated_cost_usd' => 0.0,
					),
				),
				'iteration' => 1,
			),
			'hash_b' => array(
				'hash'      => 'hash_b',
				'profile'   => array( 'enabled' => true ),
				'eval'      => null, // Not evaluated.
				'iteration' => 1,
			),
		);

		$frontier = WP_MCP_AI_Harness_Search_Engine::compute_pareto_frontier( $population );
		$this->assertCount( 1, $frontier );
		$this->assertSame( 'hash_a', $frontier[0]['hash'] );
	}

	public function test_compute_pareto_frontier_empty_population() {
		$frontier = WP_MCP_AI_Harness_Search_Engine::compute_pareto_frontier( array() );
		$this->assertEmpty( $frontier );
	}

	public function test_get_population_returns_persisted_data() {
		$seed            = WP_MCP_AI_Harness_Profile::defaults();
		$seed['enabled'] = true;

		// The population is initially empty since no search has run.
		$population = WP_MCP_AI_Harness_Search_Engine::get_population( $this->assistant_id );
		$this->assertIsArray( $population );
	}

	public function test_get_search_status_returns_null_without_search() {
		$status = WP_MCP_AI_Harness_Search_Engine::get_search_status( $this->assistant_id );
		$this->assertNull( $status );
	}

	public function test_cancel_search_is_idempotent() {
		$result = WP_MCP_AI_Harness_Search_Engine::cancel_search( $this->assistant_id );
		$this->assertTrue( $result );

		// Cancelling again is still fine.
		$result2 = WP_MCP_AI_Harness_Search_Engine::cancel_search( $this->assistant_id );
		$this->assertTrue( $result2 );
	}

	public function test_diff_profiles_returns_differences() {
		$profile_a                   = WP_MCP_AI_Harness_Profile::defaults();
		$profile_a['enabled']        = true;
		$profile_a['retrieval']['k'] = 5;

		$profile_b                   = WP_MCP_AI_Harness_Profile::defaults();
		$profile_b['enabled']        = true;
		$profile_b['retrieval']['k'] = 10;

		// Build a mock population with two entries.
		$hash_a   = md5( wp_json_encode( $profile_a ) );
		$hash_b   = md5( wp_json_encode( $profile_b ) );
		$option   = 'wp_mcp_ai_harness_population_' . $this->assistant_id;
		$mock_pop = array(
			$hash_a => array(
				'hash'      => $hash_a,
				'profile'   => $profile_a,
				'eval'      => null,
				'iteration' => 0,
			),
			$hash_b => array(
				'hash'      => $hash_b,
				'profile'   => $profile_b,
				'eval'      => null,
				'iteration' => 0,
			),
		);
		update_option( $option, $mock_pop );

		$diff = WP_MCP_AI_Harness_Search_Engine::diff_profiles( $this->assistant_id, $hash_a, $hash_b );
		$this->assertIsArray( $diff );

		// Find the retrieval.k diff.
		$k_diff = null;
		foreach ( $diff as $entry ) {
			if ( 'retrieval.k' === $entry['path'] ) {
				$k_diff = $entry;
				break;
			}
		}
		$this->assertNotNull( $k_diff );
		$this->assertTrue( $k_diff['changed'] );

		delete_option( $option );
	}
}
