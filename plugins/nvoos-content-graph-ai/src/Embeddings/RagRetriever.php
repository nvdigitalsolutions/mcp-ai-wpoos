<?php
/**
 * RAG Retriever — semantic search against the knowledge graph.
 *
 * Generates an embedding for a query, computes cosine similarity
 * against all stored embeddings, and returns the top-K most similar
 * graph nodes. Used to inject relevant context into AI chat prompts.
 *
 * @package NvoosContentGraphAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Embeddings;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use NvoosContentGraph\Graph\Db;

class RagRetriever {

	private const DEFAULT_TOP_K  = 5;
	private const MIN_SIMILARITY = 0.3;

	public function __construct(
		private readonly EmbeddingService $embeddings,
		private readonly ErrorFactoryInterface $errors,
	) {}

	/**
	 * Search for graph nodes semantically similar to a query.
	 *
	 * @param string $query    Search query text.
	 * @param int    $topK     Number of results (default: 5).
	 * @param string $provider Provider for embedding generation.
	 *
	 * @return array<int, array{node_id: string, label: string, similarity: float}>|mixed
	 */
	public function search( string $query, int $topK = 0, string $provider = '' ): mixed {
		$query = \trim( $query );
		if ( '' === $query ) {
			return $this->errors->create( 'empty_query', 'Search query cannot be empty.' );
		}

		$topK = $topK > 0 ? $topK : self::DEFAULT_TOP_K;

		// Generate query embedding.
		$result = $this->embeddings->embed( $query, $provider );
		if ( $this->errors->isError( $result ) ) {
			return $result;
		}

		$queryVector = $result['vector'];
		$model       = $result['model'];

		// Load all stored embeddings for this model.
		$allEmbeddings = Db::getAllEmbeddings( $model );

		if ( array() === $allEmbeddings ) {
			return array();
		}

		// Compute cosine similarity for each candidate.
		$scored = array();
		foreach ( $allEmbeddings as $row ) {
			$vector = $this->decodeVector( $row->embedding_vector );
			if ( null === $vector || array() === $vector ) {
				continue;
			}

			$similarity = $this->cosineSimilarity( $queryVector, $vector );
			if ( $similarity < self::MIN_SIMILARITY ) {
				continue;
			}

			$node = Db::getNode( $row->node_id );
			if ( null === $node ) {
				continue;
			}

			$scored[] = array(
				'node_id'    => $row->node_id,
				'label'      => $node->label,
				'type'       => $node->type,
				'similarity' => \round( $similarity, 4 ),
			);
		}

		// Sort by similarity descending.
		\usort( $scored, static fn( array $a, array $b ) => $b['similarity'] <=> $a['similarity'] );

		return \array_slice( $scored, 0, $topK );
	}

	/**
	 * Hybrid search (Proposal 091, Track C): dense + keyword retrieval fused
	 * with RRF and locally reranked. Falls back to whichever index exists.
	 *
	 * @param string $query    Search query text.
	 * @param int    $topK     Number of results (default: 5).
	 * @param string $provider Provider for embedding generation.
	 *
	 * @return array<int, array{node_id: string, label: string, type: string, similarity: float, matched: string}>|mixed
	 */
	public function searchHybrid( string $query, int $topK = 0, string $provider = '' ): mixed {
		$query = \trim( $query );
		if ( '' === $query ) {
			return $this->errors->create( 'empty_query', 'Search query cannot be empty.' );
		}

		$topK = $topK > 0 ? $topK : self::DEFAULT_TOP_K;

		$dense = $this->search( $query, 50, $provider );
		if ( $this->errors->isError( $dense ) ) {
			$dense = array();
		}

		$denseIds    = array();
		$denseScores = array();
		foreach ( $dense as $row ) {
			$denseIds[]                        = (string) $row['node_id'];
			$denseScores[ (string) $row['node_id'] ] = (float) $row['similarity'];
		}

		$keywordRows = $this->keywordCandidates( $query, 50 );
		$keywordIds    = array();
		$keywordScores = array();
		foreach ( $keywordRows as $row ) {
			$keywordIds[]                          = (string) $row['node_id'];
			$keywordScores[ (string) $row['node_id'] ] = (float) $row['score'];
		}

		if ( array() === $denseIds && array() === $keywordIds ) {
			return array();
		}

		$fused  = Reranker::rrf( array( $denseIds, $keywordIds ) );
		$ranked = Reranker::localRerank( $fused, $denseScores, $keywordScores, $topK );

		return $this->fetchNodes( $ranked, $denseScores, $keywordScores );
	}

	/**
	 * Build result rows for ranked node IDs.
	 *
	 * @param string[]            $nodeIds Ranked node IDs.
	 * @param array<string, float> $dense   Dense scores.
	 * @param array<string, float> $keyword Keyword scores.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchNodes( array $nodeIds, array $dense, array $keyword ): array {
		$rows = array();
		foreach ( $nodeIds as $nodeId ) {
			$node    = Db::getNode( $nodeId );
			$matched = array();
			if ( isset( $dense[ $nodeId ] ) ) {
				$matched[] = 'dense';
			}
			if ( isset( $keyword[ $nodeId ] ) ) {
				$matched[] = 'keyword';
			}

			$rows[] = array(
				'node_id'    => $nodeId,
				'label'      => null !== $node ? $node->label : '',
				'type'       => null !== $node ? $node->type : '',
				'similarity' => isset( $dense[ $nodeId ] ) ? \round( (float) $dense[ $nodeId ], 4 ) : 0.0,
				'matched'    => \implode( '|', $matched ),
			);
		}

		return $rows;
	}

	/**
	 * Keyword candidates over node labels (full phrase first, then terms).
	 *
	 * @param string $query Query text.
	 * @param int    $limit Candidate cap.
	 * @return array<int, array{node_id: string, score: float}>
	 */
	private function keywordCandidates( string $query, int $limit ): array {
		if ( ! \class_exists( 'NvoosContentGraph\Graph\Db' ) ) {
			return array();
		}

		$seen = array();
		$rows = array();

		foreach ( \NvoosContentGraph\Graph\Db::searchNodes( $query, '', $limit ) as $row ) {
			if ( ! isset( $row->node_id ) ) {
				continue;
			}
			$seen[ $row->node_id ] = true;
			$rows[]                = array(
				'node_id' => (string) $row->node_id,
				'score'   => 1.0,
			);
		}

		if ( \count( $rows ) < $limit ) {
			foreach ( $this->extractKeywords( $query ) as $keyword ) {
				foreach ( \NvoosContentGraph\Graph\Db::searchNodes( $keyword, '', 4 ) as $row ) {
					if ( \count( $rows ) >= $limit ) {
						break 2;
					}
					if ( ! isset( $row->node_id ) || isset( $seen[ $row->node_id ] ) ) {
						continue;
					}
					$seen[ $row->node_id ] = true;
					$rows[]                = array(
						'node_id' => (string) $row->node_id,
						'score'   => 0.6,
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * Split a query into significant lowercase keywords (stopword-filtered).
	 *
	 * @param string $query Raw query text.
	 * @return string[]
	 */
	private function extractKeywords( string $query ): array {
		$lower = \function_exists( 'mb_strtolower' ) ? \mb_strtolower( $query ) : \strtolower( $query );
		$words = \preg_split( '/[\s,;:.!?()\[\]{}"\']+/', $lower, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words ) {
			return array();
		}

		$stop = \array_fill_keys( array( 'the', 'and', 'for', 'with', 'what', 'when', 'where', 'which', 'how', 'does', 'about', 'from' ), true );
		$keywords = array();
		foreach ( $words as $word ) {
			$length = \function_exists( 'mb_strlen' ) ? \mb_strlen( $word ) : \strlen( $word );
			if ( $length < 3 || isset( $stop[ $word ] ) ) {
				continue;
			}
			$keywords[] = $word;
		}

		return \array_values( \array_unique( $keywords ) );
	}

	/**
	 * Build a RAG context prompt from search results.
	 *
	 * Returns a system message string that can be prepended to the
	 * conversation to give the AI relevant knowledge graph context.
	 *
	 * @param string $query    Original user query.
	 * @param int    $topK     Number of results.
	 * @param string $provider Provider for embedding generation.
	 *
	 * @return string  System prompt with relevant context, or empty string.
	 */
	public function buildContextPrompt( string $query, int $topK = 5, string $provider = '' ): string {
		$results = $this->search( $query, $topK, $provider );

		if ( $this->errors->isError( $results ) || array() === $results ) {
			return '';
		}

		$context = "The following content from the website's knowledge graph may be relevant to the user's query:\n\n";

		foreach ( $results as $i => $r ) {
			$num      = $i + 1;
			$context .= "{$num}. [{$r['type']}] {$r['label']} (relevance: {$r['similarity']})\n";
		}

		$context .= "\nUse this context to inform your response when it is relevant to the user's question.";

		return $context;
	}

	/**
	 * Augment chat messages with RAG context for a given user query.
	 *
	 * Inserts a system message with relevant graph node context before
	 * the conversation, enabling the AI to reference specific content.
	 *
	 * @param array  $messages  Existing conversation messages.
	 * @param string $query     User's latest query text.
	 * @param int    $topK      Number of results.
	 * @param string $provider  Provider for embedding generation.
	 *
	 * @return array  Messages with RAG context prepended.
	 */
	public function augmentMessages( array $messages, string $query, int $topK = 5, string $provider = '' ): array {
		$context = $this->buildContextPrompt( $query, $topK, $provider );

		if ( '' === $context ) {
			return $messages;
		}

		// Prepend context as a system message.
		\array_unshift(
			$messages,
			array(
				'role'    => 'system',
				'content' => $context,
			)
		);

		return $messages;
	}

	// ─── Math helpers ──────────────────────────────────────────────────

	/**
	 * Compute cosine similarity between two vectors.
	 *
	 * @param float[] $a First vector.
	 * @param float[] $b Second vector.
	 *
	 * @return float  Similarity in range [-1, 1], or 0 if vectors differ in length.
	 */
	private function cosineSimilarity( array $a, array $b ): float {
		$lenA = \count( $a );
		$lenB = \count( $b );

		if ( 0 === $lenA || 0 === $lenB || $lenA !== $lenB ) {
			return 0.0;
		}

		$dot   = 0.0;
		$normA = 0.0;
		$normB = 0.0;

		for ( $i = 0; $i < $lenA; $i++ ) {
			$dot   += $a[ $i ] * $b[ $i ];
			$normA += $a[ $i ] * $a[ $i ];
			$normB += $b[ $i ] * $b[ $i ];
		}

		$denom = \sqrt( $normA ) * \sqrt( $normB );

		if ( 0.0 === $denom ) {
			return 0.0;
		}

		return $dot / $denom;
	}

	/**
	 * Decode a stored vector from its serialized form.
	 *
	 * @param string $raw  Raw vector data from the DB (JSON or binary).
	 *
	 * @return float[]|null  Decoded vector, or null if unreadable.
	 */
	private function decodeVector( string $raw ): ?array {
		// Try JSON first.
		$decoded = \json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Try unpacking binary floats.
		$unpacked = \unpack( 'f*', $raw );
		if ( false !== $unpacked ) {
			return \array_values( $unpacked );
		}

		return null;
	}
}
