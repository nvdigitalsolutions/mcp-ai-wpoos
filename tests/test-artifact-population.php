<?php
/**
 * Tests for the Artifact Population & Selection (Phase C).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test artifact population storage and parent selection.
 */
class Test_Artifact_Population extends WP_UnitTestCase {

	/**
	 * Clean up population and filter state between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_artifact_population_max' );
		remove_all_filters( 'wp_mcp_ai_artifact_population_min_weight' );
		remove_all_filters( 'wp_mcp_ai_artifact_population_sharpness' );
		remove_all_filters( 'wp_mcp_ai_artifact_population_percentile' );
		remove_all_filters( 'wp_mcp_ai_artifact_population_novelty_weight' );
		remove_all_filters( 'wp_mcp_ai_artifact_population_weights' );
		remove_all_filters( 'wp_mcp_ai_artifact_population_per_assistant_max' );
		if ( class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			WP_MCP_AI_Artifact_Population::clear();
		}

		parent::tearDown();
	}

	/**
	 * Archive helper returning the entry hash.
	 *
	 * @param string      $type       Artifact type.
	 * @param string      $id         Artifact identifier.
	 * @param mixed       $artifact   Artifact payload.
	 * @param float       $score      Score.
	 * @param string|null $parent_hash Parent hash.
	 * @param int         $source      Source assistant.
	 * @param int         $timestamp  Timestamp.
	 * @return string Entry hash.
	 */
	private function archive( $type, $id, $artifact, $score, $parent_hash = null, $source = 0, $timestamp = 0 ) {
		$result = WP_MCP_AI_Artifact_Population::archive( $type, $id, $artifact, $score, array(), $parent_hash, $source, $timestamp );

		$this->assertNotWPError( $result, 'Archive must succeed for valid input.' );

		return $result;
	}

	/**
	 * Archiving links parent→child lineage.
	 */
	public function test_archive_creates_entry_with_lineage() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$parent = $this->archive( 'prompt', '42', array( 'prompt' => 'p' ), 0.8 );
		$child  = $this->archive( 'prompt', '42', array( 'prompt' => 'c' ), 0.9, $parent );

		$parent_entry = WP_MCP_AI_Artifact_Population::get_entry( $parent );
		$child_entry  = WP_MCP_AI_Artifact_Population::get_entry( $child );

		$this->assertSame( $parent, $child_entry['parent'] );
		$this->assertContains( $child, $parent_entry['children'] );
		$this->assertSame( 1, $parent_entry['children_count'] );
		$this->assertSame( 'prompt', $child_entry['artifact_type'] );
		$this->assertSame( '42', $child_entry['artifact_id'] );
	}

	/**
	 * Re-archiving the same content upserts: scores aggregate, sources merge.
	 */
	public function test_archive_upserts_and_aggregates_score() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$hash_a = $this->archive( 'prompt', '42', array( 'prompt' => 'same' ), 0.5, null, 1 );
		$hash_b = $this->archive( 'prompt', '42', array( 'prompt' => 'same' ), 0.9, null, 2 );

		$this->assertSame( $hash_a, $hash_b, 'Identical content must merge into one entry.' );

		$entry = WP_MCP_AI_Artifact_Population::get_entry( $hash_a );
		$this->assertEqualsWithDelta( 0.7, $entry['score'], 0.0001 );
		$this->assertCount( 2, $entry['score_history'] );
		$this->assertSame( array( 1, 2 ), $entry['sources'] );
	}

	/**
	 * Invalid artifact types are rejected.
	 */
	public function test_archive_invalid_type_returns_error() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$result = WP_MCP_AI_Artifact_Population::archive( 'bogus', '42', array(), 0.5 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_artifact_population_invalid_type', $result->get_error_code() );
	}

	/**
	 * The population cap prunes the least recently seen entries.
	 */
	public function test_population_cap_prunes_fifo() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		add_filter(
			'wp_mcp_ai_artifact_population_max',
			static function () {
				return 3;
			}
		);

		$oldest = $this->archive( 'prompt', '42', array( 'prompt' => 'a' ), 0.1, null, 0, 100 );
		$this->archive( 'prompt', '42', array( 'prompt' => 'b' ), 0.2, null, 0, 200 );
		$this->archive( 'prompt', '42', array( 'prompt' => 'c' ), 0.3, null, 0, 300 );
		$this->archive( 'prompt', '42', array( 'prompt' => 'd' ), 0.4, null, 0, 400 );

		$population = WP_MCP_AI_Artifact_Population::get_population();
		$this->assertCount( 3, $population );
		$this->assertArrayNotHasKey( $oldest, $population, 'Oldest entry must be pruned first.' );
	}

	/**
	 * Population lookups respect type/id/min_score/source filters.
	 */
	public function test_get_population_filters() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$this->archive( 'prompt', '42', array( 'prompt' => 'p' ), 0.8, null, 1 );
		$this->archive( 'skill', '42', array( 'name' => 's' ), 0.5, null, 1 );

		$this->assertCount( 1, WP_MCP_AI_Artifact_Population::get_population( array( 'artifact_type' => 'prompt' ) ) );
		$this->assertCount(
			1,
			WP_MCP_AI_Artifact_Population::get_population(
				array(
					'artifact_type' => 'skill',
					'artifact_id'   => '42',
				)
			)
		);
		$this->assertCount(
			0,
			WP_MCP_AI_Artifact_Population::get_population(
				array(
					'artifact_type' => 'skill',
					'artifact_id'   => '7',
				)
			)
		);
		$this->assertCount(
			1,
			WP_MCP_AI_Artifact_Population::get_population(
				array(
					'artifact_type' => 'prompt',
					'min_score'     => 0.7,
				)
			)
		);
		$this->assertCount( 2, WP_MCP_AI_Artifact_Population::get_population( array( 'source_assistant_id' => 1 ) ) );
	}

	/**
	 * Higher scores receive higher sampling weights.
	 */
	public function test_compute_weights_prefer_higher_scores() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$low  = $this->archive( 'prompt', '42', array( 'prompt' => 'low' ), 0.0 );
		$high = $this->archive( 'prompt', '42', array( 'prompt' => 'high' ), 1.0 );

		$entries = WP_MCP_AI_Artifact_Population::get_population( array( 'artifact_type' => 'prompt' ) );
		$weights = WP_MCP_AI_Artifact_Population::compute_weights( $entries );

		$this->assertGreaterThan( $weights[ $low ], $weights[ $high ] );
	}

	/**
	 * Novelty decays with children: a fresh entry out-weights a parent with
	 * the same score.
	 */
	public function test_compute_weights_novelty_decays_with_children() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$parent = $this->archive( 'prompt', '42', array( 'prompt' => 'parent' ), 1.0 );
		$this->archive( 'prompt', '42', array( 'prompt' => 'kid-1' ), 0.5, $parent );
		$this->archive( 'prompt', '42', array( 'prompt' => 'kid-2' ), 0.5, $parent );
		$this->archive( 'prompt', '42', array( 'prompt' => 'kid-3' ), 0.5, $parent );
		$fresh = $this->archive( 'prompt', '42', array( 'prompt' => 'fresh' ), 1.0 );

		$entries = WP_MCP_AI_Artifact_Population::get_population( array( 'artifact_type' => 'prompt' ) );
		$weights = WP_MCP_AI_Artifact_Population::compute_weights( $entries );

		$this->assertSame( 3, $entries[ $parent ]['children_count'] );
		$this->assertGreaterThan( $weights[ $parent ], $weights[ $fresh ] );
	}

	/**
	 * All weights respect the (filtered) minimum — never zero.
	 */
	public function test_compute_weights_respect_min_weight_filter() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		add_filter(
			'wp_mcp_ai_artifact_population_min_weight',
			static function () {
				return 0.5;
			}
		);

		$this->archive( 'prompt', '42', array( 'prompt' => 'a' ), 0.0 );

		$entries = WP_MCP_AI_Artifact_Population::get_population( array( 'artifact_type' => 'prompt' ) );
		$weights = WP_MCP_AI_Artifact_Population::compute_weights( $entries );

		foreach ( $weights as $weight ) {
			$this->assertGreaterThanOrEqual( 0.5, $weight );
		}
	}

	/**
	 * The dynamic midpoint is the nearest-rank Nth percentile.
	 */
	public function test_dynamic_midpoint() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$this->archive( 'prompt', '42', array( 'prompt' => 'a' ), 0.1 );
		$this->archive( 'prompt', '42', array( 'prompt' => 'b' ), 0.2 );
		$this->archive( 'prompt', '42', array( 'prompt' => 'c' ), 0.3 );

		$entries = WP_MCP_AI_Artifact_Population::get_population( array( 'artifact_type' => 'prompt' ) );

		$this->assertEqualsWithDelta( 0.2, WP_MCP_AI_Artifact_Population::get_dynamic_midpoint( $entries, 50.0 ), 0.0001 );
		$this->assertEqualsWithDelta( 0.3, WP_MCP_AI_Artifact_Population::get_dynamic_midpoint( $entries, 90.0 ), 0.0001 );
	}

	/**
	 * Sampling is deterministic under an injected RNG.
	 */
	public function test_sample_parents_deterministic_with_rng() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$this->archive( 'prompt', '42', array( 'prompt' => 'a' ), 0.1 );
		$this->archive( 'prompt', '42', array( 'prompt' => 'b' ), 0.9 );

		// rng = 0.0 → the first entry by cumulative weight.
		$picked = WP_MCP_AI_Artifact_Population::sample_parents(
			'prompt',
			'42',
			2,
			array(
				'rng' => static function () {
					return 0.0;
				},
			)
		);
		$this->assertCount( 2, $picked );
		$this->assertSame( array( 'prompt' => 'a' ), $picked[0]['artifact'] );

		// rng ≈ 1.0 → the last entry by cumulative weight.
		$picked_last = WP_MCP_AI_Artifact_Population::sample_parents(
			'prompt',
			'42',
			1,
			array(
				'rng' => static function () {
					return 0.999999;
				},
			)
		);
		$this->assertCount( 1, $picked_last );
		$this->assertSame( array( 'prompt' => 'b' ), $picked_last[0]['artifact'] );
	}

	/**
	 * Sampling respects artifact-type scope.
	 */
	public function test_sample_parents_respects_scope() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$this->archive( 'prompt', '42', array( 'prompt' => 'p' ), 0.9 );
		$this->archive( 'skill', '42', array( 'name' => 's' ), 0.9 );

		$picked = WP_MCP_AI_Artifact_Population::sample_parents( 'prompt', '42', 2 );

		$this->assertCount( 1, $picked );
		$this->assertSame( 'prompt', $picked[0]['artifact_type'] );
	}

	/**
	 * Empty populations return no parents.
	 */
	public function test_sample_parents_empty_population() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		$this->assertSame( array(), WP_MCP_AI_Artifact_Population::sample_parents( 'prompt' ) );
	}

	/**
	 * Per-assistant cap evicts the lowest-scored entries from that assistant only.
	 */
	public function test_enforce_per_assistant_cap() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Population class not available.' );
		}

		add_filter(
			'wp_mcp_ai_artifact_population_per_assistant_max',
			static function () {
				return 2;
			}
		);

		$low  = $this->archive( 'prompt', '42', array( 'prompt' => 'low' ), 0.1, null, 1 );
		$mid  = $this->archive( 'prompt', '42', array( 'prompt' => 'mid' ), 0.5, null, 1 );
		$high = $this->archive( 'prompt', '42', array( 'prompt' => 'high' ), 0.9, null, 1 );
		$this->archive( 'prompt', '42', array( 'prompt' => 'other-assistant' ), 0.05, null, 2 );

		$evicted = WP_MCP_AI_Artifact_Population::enforce_per_assistant_cap( 1 );

		$this->assertSame( 1, $evicted );
		$this->assertNull( WP_MCP_AI_Artifact_Population::get_entry( $low ), 'Lowest score must be evicted first.' );
		$this->assertNotNull( WP_MCP_AI_Artifact_Population::get_entry( $mid ) );
		$this->assertNotNull( WP_MCP_AI_Artifact_Population::get_entry( $high ) );

		// The other assistant's entry is untouched.
		$other = WP_MCP_AI_Artifact_Population::get_population( array( 'source_assistant_id' => 2 ) );
		$this->assertCount( 1, $other );

		// A second pass under the cap is a no-op.
		$this->assertSame( 0, WP_MCP_AI_Artifact_Population::enforce_per_assistant_cap( 1 ) );
	}
}
