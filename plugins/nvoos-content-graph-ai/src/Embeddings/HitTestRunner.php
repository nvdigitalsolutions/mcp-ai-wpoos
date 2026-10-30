<?php
/**
 * RAG hit-test runner — Proposal 091, Track C.
 *
 * Runs the retriever alone (no LLM) over a golden question → chunk set and
 * reports RAGAS-style retrieval metrics: context_precision, context_recall,
 * MRR and hit@k. Retrieval is evaluated separately from generation — this
 * is the retrieval half.
 *
 * Golden-set format (JSONL, one object per line):
 *   { "query": "…", "gold_node_ids": ["node-1", "node-2"] }
 *
 * @package NvoosContentGraphAi
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Embeddings;

/**
 * Hit-test runner.
 *
 * @since 1.1.0
 */
final class HitTestRunner {

	/**
	 * Retriever callable: fn( string $query, int $topK ) → rows with node_id.
	 *
	 * @var callable
	 */
	private $retriever;

	/**
	 * Constructor.
	 *
	 * @param callable $retriever Retriever callable.
	 */
	public function __construct( callable $retriever ) {
		$this->retriever = $retriever;
	}

	/**
	 * Parse a golden-set JSONL payload.
	 *
	 * @param string $jsonl Raw JSONL text.
	 * @return array<int, array{query: string, gold_node_ids: string[]}>
	 */
	public static function parseGolden( string $jsonl ): array {
		$golden = array();
		foreach ( \preg_split( '/\R+/', \trim( $jsonl ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $line ) {
			$entry = \json_decode( \trim( $line ), true );
			if ( ! \is_array( $entry ) || empty( $entry['query'] ) || empty( $entry['gold_node_ids'] ) || ! \is_array( $entry['gold_node_ids'] ) ) {
				continue;
			}
			$golden[] = array(
				'query'         => (string) $entry['query'],
				'gold_node_ids' => \array_map( 'strval', $entry['gold_node_ids'] ),
			);
		}

		return $golden;
	}

	/**
	 * Evaluate the retriever against a golden set.
	 *
	 * @param array<int, array{query: string, gold_node_ids: string[]}> $golden Golden set.
	 * @param int                                                       $topK   Results per query.
	 * @return array{rows: array<int, array<string, mixed>>, summary: array<string, float>}
	 */
	public function evaluate( array $golden, int $topK = 5 ): array {
		$rows          = array();
		$precisionSum  = 0.0;
		$recallSum     = 0.0;
		$mrrSum        = 0.0;
		$hitCount      = 0;
		$queries       = 0;

		foreach ( $golden as $entry ) {
			$rows[]      = $this->evaluateOne( $entry, $topK, $precisionSum, $recallSum, $mrrSum, $hitCount );
			$queries++;
		}

		$denominator = \max( 1, $queries );

		return array(
			'rows'    => $rows,
			'summary' => array(
				'context_precision' => \round( $precisionSum / $denominator, 4 ),
				'context_recall'    => \round( $recallSum / $denominator, 4 ),
				'mrr'               => \round( $mrrSum / $denominator, 4 ),
				'hit@k'             => \round( $hitCount / $denominator, 4 ),
				'queries'           => $queries,
			),
		);
	}

	/**
	 * Evaluate a single golden query.
	 *
	 * @param array{query: string, gold_node_ids: string[]} $entry       Golden entry.
	 * @param int                                           $topK        Results per query.
	 * @param float                                         $precisionSum Running precision sum (by ref).
	 * @param float                                         $recallSum    Running recall sum (by ref).
	 * @param float                                         $mrrSum       Running MRR sum (by ref).
	 * @param int                                           $hitCount     Running hit counter (by ref).
	 * @return array<string, mixed> Per-query metrics.
	 */
	private function evaluateOne( array $entry, int $topK, float &$precisionSum, float &$recallSum, float &$mrrSum, int &$hitCount ): array {
		$gold = \array_fill_keys( $entry['gold_node_ids'], true );

		$rows = \call_user_func( $this->retriever, $entry['query'], $topK );
		if ( ! \is_array( $rows ) ) {
			$rows = array();
		}

		$retrieved = array();
		foreach ( $rows as $row ) {
			if ( \is_array( $row ) && isset( $row['node_id'] ) ) {
				$retrieved[] = (string) $row['node_id'];
			}
		}

		$retrievedCount = \count( $retrieved );
		$goldCount      = \count( $entry['gold_node_ids'] );
		$hits           = 0;
		$firstRank      = 0;
		foreach ( $retrieved as $rank => $nodeId ) {
			if ( isset( $gold[ $nodeId ] ) ) {
				$hits++;
				if ( 0 === $firstRank ) {
					$firstRank = $rank + 1;
				}
			}
		}

		$precision = $retrievedCount > 0 ? $hits / $retrievedCount : 0.0;
		$recall    = $goldCount > 0 ? $hits / $goldCount : 0.0;
		$mrr       = $firstRank > 0 ? 1.0 / $firstRank : 0.0;
		$hit       = $hits > 0 ? 1 : 0;

		$precisionSum += $precision;
		$recallSum    += $recall;
		$mrrSum       += $mrr;
		$hitCount     += $hit;

		return array(
			'query'             => $entry['query'],
			'retrieved'         => $retrieved,
			'context_precision' => \round( $precision, 4 ),
			'context_recall'    => \round( $recall, 4 ),
			'mrr'               => \round( $mrr, 4 ),
			'hit'               => $hit,
		);
	}
}
