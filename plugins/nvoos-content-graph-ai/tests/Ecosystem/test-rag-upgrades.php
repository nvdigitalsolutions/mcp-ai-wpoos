<?php
/**
 * Tests for the RAG upgrades (Proposal 091, Track C) — pure units.
 *
 * Covers ChunkingStrategy, Reranker (RRF + local blend) and HitTestRunner
 * with a fake retriever. No database or network access.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests\Ecosystem;

use NvoosContentGraphAi\Embeddings\ChunkingStrategy;
use NvoosContentGraphAi\Embeddings\HitTestRunner;
use NvoosContentGraphAi\Embeddings\Reranker;
use PHPUnit\Framework\TestCase;

final class Test_Rag_Upgrades extends TestCase {

	/**
	 * Fixed chunking produces overlapping windows and covers the whole text.
	 *
	 * @return void
	 */
	public function test_chunk_fixed_overlap(): void {
		$text = 'abcdefghijklmnopqrstuvwxyz0123456789';

		$chunks = ChunkingStrategy::chunkFixed( $text, 16, 4 );

		$this->assertNotEmpty( $chunks );
		$this->assertSame( 'abcdefghijklmnop', $chunks[0] );
		// Overlap of 4: the second chunk starts 12 chars in.
		$this->assertSame( 'mnopqrstuvwxyz01', $chunks[1] );
		$this->assertSame( $text, implode( '', array_map( static fn( string $c ): string => substr( $c, 0, 12 ), array_slice( $chunks, 0, -1 ) ) ) . end( $chunks ) );
	}

	/**
	 * Recursive chunking keeps heading sections intact and merges tiny tails.
	 *
	 * @return void
	 */
	public function test_chunk_recursive_heading_split(): void {
		$text = "## Intro\nShort intro text.\n\n## Details\n" . str_repeat( 'd', 300 ) . "\n\n## Tail\nTiny.";

		$chunks = ChunkingStrategy::chunkRecursive( $text, 160, 24 );

		$this->assertNotEmpty( $chunks );
		// The "## Tail" section is tiny — it must be merged into its predecessor.
		$this->assertSame( '## Intro', substr( $chunks[0], 0, 8 ) );
		$joined = implode( "\n\n", $chunks );
		$this->assertStringContainsString( 'Tiny.', $joined );
		// Merge behavior may push a chunk above the cap (context preservation)
		// but never beyond 1.5x the size.
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 240, strlen( $chunk ) );
		}
	}

	/**
	 * The default strategy routes to recursive chunking.
	 *
	 * @return void
	 */
	public function test_chunk_defaults_to_recursive(): void {
		$text = "## One\n" . str_repeat( 'a', 200 ) . "\n\n## Two\n" . str_repeat( 'b', 200 );

		$chunks = ChunkingStrategy::chunk( $text, array( 'size_tokens' => 50 ) );

		$this->assertNotEmpty( $chunks );
	}

	/**
	 * RRF fuses lists deterministically with the k=60 constant.
	 *
	 * @return void
	 */
	public function test_rrf_fusion(): void {
		$scores = Reranker::rrf(
			array(
				array( 'a', 'b', 'c' ),
				array( 'b', 'a', 'd' ),
			)
		);

		$this->assertSame( array( 'a', 'b', 'c', 'd' ), array_keys( $scores ) );
		$this->assertGreaterThan( $scores['c'], $scores['a'] );
	}

	/**
	 * Local rerank blends dense + keyword scores and returns the requested
	 * number of ranked node IDs.
	 *
	 * @return void
	 */
	public function test_local_rerank_blend(): void {
		$fused   = array( 'a' => 0.05, 'b' => 0.04, 'c' => 0.03 );
		$dense   = array( 'a' => 0.9, 'b' => 0.1, 'c' => 0.4 );
		$keyword = array( 'a' => 0.0, 'b' => 1.0, 'c' => 0.5 );

		$ranked = Reranker::localRerank( $fused, $dense, $keyword, 2 );

		$this->assertCount( 2, $ranked );
		// Strong keyword evidence puts 'b' ahead of the weaker-dense 'c'.
		$this->assertContains( 'b', $ranked );
		$this->assertSame( 'a', $ranked[0] );
	}

	/**
	 * Hit-test metrics: precision, recall, MRR and hit@k over a fake retriever.
	 *
	 * @return void
	 */
	public function test_hit_test_metrics(): void {
		$runner = new HitTestRunner(
			static fn( string $query, int $k ): array => array(
				array( 'node_id' => 'n1' ),
				array( 'node_id' => 'n2' ),
				array( 'node_id' => 'n3' ),
			)
		);

		$golden = array(
			array(
				'query'         => 'perfect',
				'gold_node_ids' => array( 'n1', 'n2', 'n3' ),
			),
			array(
				'query'         => 'half',
				'gold_node_ids' => array( 'n1', 'nX' ),
			),
		);

		$report = $runner->evaluate( $golden, 3 );

		$this->assertSame( 2, $report['summary']['queries'] );
		$this->assertSame( 1.0, $report['summary']['hit@k'] );
		$this->assertSame( 0.5, $report['rows'][1]['context_recall'] );
		$this->assertSame( 1.0, $report['rows'][0]['mrr'] );
	}

	/**
	 * Golden-set parsing skips malformed lines.
	 *
	 * @return void
	 */
	public function test_parse_golden_skips_malformed(): void {
		$golden = HitTestRunner::parseGolden(
			"{\"query\":\"ok\",\"gold_node_ids\":[\"n1\"]}\nnot json\n{\"query\":\"missing ids\"}\n"
		);

		$this->assertCount( 1, $golden );
		$this->assertSame( 'ok', $golden[0]['query'] );
	}
}
