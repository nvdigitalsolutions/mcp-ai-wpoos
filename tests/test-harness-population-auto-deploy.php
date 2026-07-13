<?php
/**
 * Tests for the Harness Population and Auto-Deploy.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Harness Population tests.
 *
 * @since 1.9.0
 */
class Test_Harness_Population extends WP_UnitTestCase {

	private $assistant_id;

	public function setUp(): void {
		parent::setUp();
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( 1 );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		// Clean up global population.
		delete_option( 'wp_mcp_ai_harness_population_global' );
		parent::tearDown();
	}

	public function test_archive_stores_profile() {
		$profile                   = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled']        = true;
		$profile['retrieval']['k'] = 7;

		$eval = array(
			'aggregate' => array(
				'score'        => 0.85,
				'total_cases'  => 10,
				'total_passed' => 8,
			),
		);

		$hash = WP_MCP_AI_Harness_Population::archive( $profile, $eval, $this->assistant_id );
		$this->assertNotEmpty( $hash );

		$population = WP_MCP_AI_Harness_Population::get_population();
		$this->assertNotEmpty( $population );

		$found = false;
		foreach ( $population as $entry ) {
			if ( $entry['hash'] === $hash ) {
				$found = true;
				$this->assertSame( $this->assistant_id, $entry['source'] );
				$this->assertSame( 0.85, $entry['eval']['aggregate']['score'] );
				break;
			}
		}
		$this->assertTrue( $found );
	}

	public function test_archive_increments_shared_count_on_duplicate() {
		$profile            = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled'] = true;

		$eval = array( 'aggregate' => array( 'score' => 0.5 ) );

		$hash1 = WP_MCP_AI_Harness_Population::archive( $profile, $eval, $this->assistant_id );
		$hash2 = WP_MCP_AI_Harness_Population::archive( $profile, $eval, $this->assistant_id );

		$this->assertSame( $hash1, $hash2 );

		$population = WP_MCP_AI_Harness_Population::get_population();
		foreach ( $population as $entry ) {
			if ( $entry['hash'] === $hash1 ) {
				$this->assertGreaterThanOrEqual( 2, $entry['shared_count'] );
				return;
			}
		}
		$this->fail( 'Archived profile not found in population.' );
	}

	public function test_transfer_requires_edit_capability() {
		$profile            = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled'] = true;
		$eval               = array( 'aggregate' => array( 'score' => 0.9 ) );

		$hash = WP_MCP_AI_Harness_Population::archive( $profile, $eval, $this->assistant_id );

		// Set user to someone without edit_post capability.
		wp_set_current_user( 0 );

		$result = WP_MCP_AI_Harness_Population::transfer( $hash, $this->assistant_id );
		$this->assertFalse( $result );
	}

	public function test_get_population_filters_by_source() {
		$profile            = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled'] = true;
		$eval               = array( 'aggregate' => array( 'score' => 0.8 ) );

		WP_MCP_AI_Harness_Population::archive( $profile, $eval, $this->assistant_id );

		$filtered = WP_MCP_AI_Harness_Population::get_population(
			array( 'source_assistant_id' => $this->assistant_id )
		);
		$this->assertNotEmpty( $filtered );

		// Non-existent source should return empty.
		$empty = WP_MCP_AI_Harness_Population::get_population(
			array( 'source_assistant_id' => 99999 )
		);
		$this->assertEmpty( $empty );
	}

	public function test_get_population_filters_by_min_score() {
		$profile_high                   = WP_MCP_AI_Harness_Profile::defaults();
		$profile_high['enabled']        = true;
		$profile_high['retrieval']['k'] = 5;
		WP_MCP_AI_Harness_Population::archive(
			$profile_high,
			array( 'aggregate' => array( 'score' => 0.9 ) ),
			$this->assistant_id
		);

		$profile_low                   = WP_MCP_AI_Harness_Profile::defaults();
		$profile_low['enabled']        = true;
		$profile_low['retrieval']['k'] = 10;
		WP_MCP_AI_Harness_Population::archive(
			$profile_low,
			array( 'aggregate' => array( 'score' => 0.3 ) ),
			$this->assistant_id
		);

		$filtered = WP_MCP_AI_Harness_Population::get_population( array( 'min_score' => 0.8 ) );
		$this->assertCount( 1, $filtered );
		$this->assertSame( 0.9, $filtered[0]['eval']['aggregate']['score'] );
	}

	public function test_count_returns_population_size() {
		$count_before = WP_MCP_AI_Harness_Population::count();

		$profile            = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled'] = true;
		WP_MCP_AI_Harness_Population::archive(
			$profile,
			array( 'aggregate' => array( 'score' => 0.5 ) ),
			$this->assistant_id
		);

		$this->assertSame( $count_before + 1, WP_MCP_AI_Harness_Population::count() );
	}

	public function test_suggest_for_assistant_excludes_own_profiles() {
		$profile                         = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled']              = true;
		$profile['memory']['task_class'] = 'qa';
		WP_MCP_AI_Harness_Population::archive(
			$profile,
			array( 'aggregate' => array( 'score' => 0.85 ) ),
			$this->assistant_id
		);

		$suggestions = WP_MCP_AI_Harness_Population::suggest_for_assistant(
			$this->assistant_id,
			'qa',
			5
		);

		// The suggestion should NOT include the profile from this assistant.
		$this->assertEmpty( $suggestions );
	}

	public function test_lineage_returns_null_for_unknown_hash() {
		$lineage = WP_MCP_AI_Harness_Population::get_lineage( 'nonexistent_hash' );
		$this->assertNull( $lineage );
	}

	public function test_delete_removes_profile() {
		$profile            = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled'] = true;
		$hash               = WP_MCP_AI_Harness_Population::archive(
			$profile,
			array( 'aggregate' => array( 'score' => 0.5 ) ),
			$this->assistant_id
		);

		$deleted = WP_MCP_AI_Harness_Population::delete( $hash );
		$this->assertTrue( $deleted );

		$population = WP_MCP_AI_Harness_Population::get_population();
		foreach ( $population as $entry ) {
			$this->assertNotSame( $hash, $entry['hash'] );
		}
	}
}

/**
 * Harness Auto-Deploy tests.
 *
 * @since 1.9.0
 */
class Test_Harness_Auto_Deploy extends WP_UnitTestCase {

	private $assistant_id;

	public function setUp(): void {
		parent::setUp();
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( 1 );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_evaluate_approves_with_improvement() {
		$candidate            = WP_MCP_AI_Harness_Profile::defaults();
		$candidate['enabled'] = true;

		$baseline = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.70 ) ),
		);
		$held_in  = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.75 ) ),
		);
		$held_out = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.74 ) ),
		);

		$result = WP_MCP_AI_Harness_Auto_Deploy::evaluate(
			$this->assistant_id,
			$candidate,
			$held_in,
			$held_out,
			$baseline
		);

		$this->assertTrue( $result['approved'] );
		$this->assertSame( 'All safety gates passed.', $result['reason'] );
	}

	public function test_evaluate_rejects_without_improvement() {
		$candidate            = WP_MCP_AI_Harness_Profile::defaults();
		$candidate['enabled'] = true;

		$baseline = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.70 ) ),
		);
		$held_in  = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.68 ) ),
		);
		$held_out = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.69 ) ),
		);

		$result = WP_MCP_AI_Harness_Auto_Deploy::evaluate(
			$this->assistant_id,
			$candidate,
			$held_in,
			$held_out,
			$baseline
		);

		$this->assertFalse( $result['approved'] );
	}

	public function test_evaluate_rejects_below_min_improvement() {
		$candidate            = WP_MCP_AI_Harness_Profile::defaults();
		$candidate['enabled'] = true;

		$baseline = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.70 ) ),
		);
		// 0.5% improvement — below the 2% minimum threshold.
		$held_in  = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.7035 ) ),
		);
		$held_out = array(
			'suite_a' => array( 'aggregate' => array( 'score' => 0.70 ) ),
		);

		$result = WP_MCP_AI_Harness_Auto_Deploy::evaluate(
			$this->assistant_id,
			$candidate,
			$held_in,
			$held_out,
			$baseline
		);

		$this->assertFalse( $result['approved'] );
	}

	public function test_apply_with_rollback_saves_previous() {
		$new_profile                   = WP_MCP_AI_Harness_Profile::defaults();
		$new_profile['enabled']        = true;
		$new_profile['retrieval']['k'] = 15;

		$applied = WP_MCP_AI_Harness_Auto_Deploy::apply_with_rollback(
			$this->assistant_id,
			$new_profile
		);
		$this->assertTrue( $applied );

		// Check that rollback target was saved.
		$can_rollback = WP_MCP_AI_Harness_Auto_Deploy::can_rollback( $this->assistant_id );
		$this->assertTrue( $can_rollback );

		// Verify the profile was actually applied.
		$active = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );
		$this->assertSame( 15, $active['retrieval']['k'] );
	}

	public function test_rollback_restores_previous() {
		// Apply first profile.
		$profile_a                   = WP_MCP_AI_Harness_Profile::defaults();
		$profile_a['enabled']        = true;
		$profile_a['retrieval']['k'] = 5;
		WP_MCP_AI_Harness_Auto_Deploy::apply_with_rollback( $this->assistant_id, $profile_a );

		// Apply second profile.
		$profile_b                   = WP_MCP_AI_Harness_Profile::defaults();
		$profile_b['enabled']        = true;
		$profile_b['retrieval']['k'] = 20;
		WP_MCP_AI_Harness_Auto_Deploy::apply_with_rollback( $this->assistant_id, $profile_b );

		$this->assertSame( 20, WP_MCP_AI_Harness_Profile::get( $this->assistant_id )['retrieval']['k'] );

		// Rollback to profile_a.
		$rolled = WP_MCP_AI_Harness_Auto_Deploy::rollback( $this->assistant_id );
		$this->assertTrue( $rolled );

		$this->assertSame( 5, WP_MCP_AI_Harness_Profile::get( $this->assistant_id )['retrieval']['k'] );

		// Can't rollback again.
		$this->assertFalse( WP_MCP_AI_Harness_Auto_Deploy::can_rollback( $this->assistant_id ) );
	}

	public function test_get_deploy_history_records_events() {
		$profile            = WP_MCP_AI_Harness_Profile::defaults();
		$profile['enabled'] = true;

		WP_MCP_AI_Harness_Auto_Deploy::apply_with_rollback( $this->assistant_id, $profile );
		WP_MCP_AI_Harness_Auto_Deploy::rollback( $this->assistant_id );

		$history = WP_MCP_AI_Harness_Auto_Deploy::get_deploy_history( $this->assistant_id, 10 );
		$this->assertCount( 2, $history );
		$this->assertSame( 'rollback', $history[0]['event'] );
		$this->assertSame( 'deploy', $history[1]['event'] );
	}
}
