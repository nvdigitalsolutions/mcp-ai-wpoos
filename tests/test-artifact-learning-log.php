<?php
/**
 * Tests for the Artifact Learning Log (Phase D.2).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test learning-log persistence and neighborhood retrieval.
 */
class Test_Artifact_Learning_Log extends WP_UnitTestCase {

	/**
	 * Clean up log, population, and filter state.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_artifact_learning_log_max' );
		if ( class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) ) {
			WP_MCP_AI_Artifact_Learning_Log::clear();
		}
		if ( class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			WP_MCP_AI_Artifact_Population::clear();
		}

		parent::tearDown();
	}

	/**
	 * Record a valid entry and verify retrieval.
	 */
	public function test_record_and_retrieve() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Learning_Log class not available.' );
		}

		$id = WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type'  => 'prompt',
				'artifact_id'    => '42',
				'parent_hash'    => 'parent-hash',
				'child_hash'     => 'child-hash',
				'kind'           => 'failure_driven',
				'diff'           => '- old line\n+ new line',
				'change_summary' => 'Fixed search instructions.',
				'score_delta'    => 0.25,
				'assistant_id'   => 7,
			)
		);

		$this->assertNotWPError( $id );
		$this->assertNotEmpty( $id );

		$entries = WP_MCP_AI_Artifact_Learning_Log::get_entries( array( 'artifact_type' => 'prompt' ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( $id, $entries[0]['id'] );
		$this->assertSame( 'parent-hash', $entries[0]['parent_hash'] );
		$this->assertEqualsWithDelta( 0.25, $entries[0]['score_delta'], 0.0001 );
		$this->assertSame( 7, $entries[0]['assistant_id'] );
	}

	/**
	 * Entries without an artifact type are rejected.
	 */
	public function test_record_requires_artifact_type() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Learning_Log class not available.' );
		}

		$result = WP_MCP_AI_Artifact_Learning_Log::record( array( 'diff' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_learning_log_invalid_type', $result->get_error_code() );
	}

	/**
	 * The cap drops the oldest entries first.
	 */
	public function test_cap_prunes_fifo() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Learning_Log class not available.' );
		}

		add_filter(
			'wp_mcp_ai_artifact_learning_log_max',
			static function () {
				return 2;
			}
		);

		$first = WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type' => 'prompt',
				'parent_hash'   => 'a',
			)
		);
		WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type' => 'prompt',
				'parent_hash'   => 'b',
			)
		);
		WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type' => 'prompt',
				'parent_hash'   => 'c',
			)
		);

		$entries = WP_MCP_AI_Artifact_Learning_Log::get_entries();
		$this->assertCount( 2, $entries );
		$this->assertNotSame( $first, $entries[0]['id'], 'Oldest entry must be pruned first.' );
	}

	/**
	 * Ancestor neighborhoods walk the population parent chain.
	 */
	public function test_ancestor_neighborhood() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) || ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'Phase C/D classes not available.' );
		}

		$p1 = WP_MCP_AI_Artifact_Population::archive( 'prompt', '42', array( 'prompt' => 'p1' ), 0.5 );
		$p2 = WP_MCP_AI_Artifact_Population::archive( 'prompt', '42', array( 'prompt' => 'p2' ), 0.6, array(), $p1 );
		WP_MCP_AI_Artifact_Population::archive( 'prompt', '42', array( 'prompt' => 'p3' ), 0.7, array(), $p2 );
		WP_MCP_AI_Artifact_Population::archive( 'prompt', '42', array( 'prompt' => 'unrelated' ), 0.1 );

		WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type' => 'prompt',
				'parent_hash'   => $p1,
			)
		);
		WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type' => 'prompt',
				'parent_hash'   => $p2,
			)
		);

		// Neighborhood of the chain head (p1's child) reaches p1 and p2.
		$chain_head = WP_MCP_AI_Artifact_Population::get_population(
			array(
				'artifact_type' => 'prompt',
				'min_score'     => 0.7,
			)
		);
		$head_hash  = array_keys( $chain_head )[0];

		$near = WP_MCP_AI_Artifact_Learning_Log::get_for_neighborhood( $head_hash, 5, 'ancestors' );
		$this->assertCount( 2, $near, 'Entries from the whole ancestor chain are returned.' );

		// Neighborhood of the root (p1) only reaches entries parented at p1.
		$root = WP_MCP_AI_Artifact_Learning_Log::get_for_neighborhood( $p1, 5, 'ancestors' );
		$this->assertCount( 1, $root );
		$this->assertSame( $p1, $root[0]['parent_hash'] );
	}

	/**
	 * Sibling neighborhoods return mutations from the same parent's children.
	 */
	public function test_sibling_neighborhood() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) || ! class_exists( 'WP_MCP_AI_Artifact_Population' ) ) {
			$this->markTestSkipped( 'Phase C/D classes not available.' );
		}

		$parent = WP_MCP_AI_Artifact_Population::archive( 'prompt', '42', array( 'prompt' => 'parent' ), 0.5 );
		$c1     = WP_MCP_AI_Artifact_Population::archive( 'prompt', '42', array( 'prompt' => 'c1' ), 0.6, array(), $parent );
		$c2     = WP_MCP_AI_Artifact_Population::archive( 'prompt', '42', array( 'prompt' => 'c2' ), 0.7, array(), $parent );

		WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type' => 'prompt',
				'parent_hash'   => $c1,
			)
		);
		WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type' => 'prompt',
				'parent_hash'   => $c2,
			)
		);

		// Siblings of c2 = other children of parent = c1.
		$near = WP_MCP_AI_Artifact_Learning_Log::get_for_neighborhood( $c2, 5, 'siblings' );
		$this->assertCount( 1, $near );
		$this->assertSame( $c1, $near[0]['parent_hash'] );
	}

	/**
	 * PII in diffs/summaries is scrubbed when the filter is loaded.
	 */
	public function test_pii_scrubbed() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Learning_Log' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Learning_Log class not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pii_Filter' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pii_Filter class not available.' );
		}

		WP_MCP_AI_Artifact_Learning_Log::record(
			array(
				'artifact_type'  => 'prompt',
				'diff'           => '+ Use bob@example.com',
				'change_summary' => 'Add bob@example.com contact',
			)
		);

		$entries = WP_MCP_AI_Artifact_Learning_Log::get_entries();
		$this->assertStringNotContainsString( 'bob@example.com', $entries[0]['diff'] );
		$this->assertStringNotContainsString( 'bob@example.com', $entries[0]['change_summary'] );
	}
}
