<?php
/**
 * Reranking helpers — Proposal 091, Track C.
 *
 * Pure implementations of the two default reranking stages:
 *
 *   1. Reciprocal Rank Fusion (RRF, k=60) over parallel ranked lists —
 *      the 2026 consensus for hybrid keyword + dense retrieval fusion.
 *   2. A local score blend (normalized cosine + keyword score) standing in
 *      for a cross-encoder — the documented local approximation (decision
 *      D2: no external API by default).
 *
 * @package NvoosContentGraphAi
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Embeddings;

/**
 * Reranking helpers.
 *
 * @since 1.1.0
 */
final class Reranker {

	public const RRF_K        = 60;
	public const DENSE_WEIGHT = 0.6;

	/**
	 * Fuse parallel ranked lists with Reciprocal Rank Fusion.
	 *
	 * @param array<int, string[]> $lists Ranked node-ID lists (best first).
	 * @param int                  $k     RRF constant.
	 * @return array<string, float> Node ID → fused RRF score (descending).
	 */
	public static function rrf( array $lists, int $k = self::RRF_K ): array {
		$scores = array();
		foreach ( $lists as $list ) {
			foreach ( \array_values( $list ) as $rank => $nodeId ) {
				$nodeId            = (string) $nodeId;
				$scores[ $nodeId ] = ( $scores[ $nodeId ] ?? 0.0 ) + ( 1.0 / ( $k + $rank + 1 ) );
			}
		}
		\arsort( $scores, SORT_NUMERIC );

		return $scores;
	}

	/**
	 * Blend fused candidates with dense + keyword scores (local rerank).
	 *
	 * The documented local approximation of a cross-encoder: normalized
	 * cosine (weight 0.6) + normalized keyword-match score (weight 0.4).
	 *
	 * @param array<string, float> $fused        Node ID → RRF score.
	 * @param array<string, float> $dense        Node ID → cosine similarity.
	 * @param array<string, float> $keyword      Node ID → keyword score.
	 * @param int                  $topK         Number of results to return.
	 * @return string[] Ranked node IDs (best first).
	 */
	public static function localRerank( array $fused, array $dense, array $keyword, int $topK ): array {
		$maxDense   = 1.0;
		$maxKeyword = 1.0;
		foreach ( $dense as $score ) {
			$maxDense = \max( $maxDense, (float) $score );
		}
		foreach ( $keyword as $score ) {
			$maxKeyword = \max( $maxKeyword, (float) $score );
		}

		$blended = array();
		foreach ( \array_keys( $fused ) as $nodeId ) {
			$denseScore   = isset( $dense[ $nodeId ] ) ? (float) $dense[ $nodeId ] : 0.0;
			$keywordScore = isset( $keyword[ $nodeId ] ) ? (float) $keyword[ $nodeId ] : 0.0;

			$blended[ $nodeId ] = ( self::DENSE_WEIGHT * ( $denseScore / $maxDense ) )
				+ ( ( 1.0 - self::DENSE_WEIGHT ) * ( $keywordScore / $maxKeyword ) );
		}

		\arsort( $blended, SORT_NUMERIC );

		return \array_slice( \array_keys( $blended ), 0, \max( 1, $topK ) );
	}
}
