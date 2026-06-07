<?php
/**
 * Agent Memory — persistent conversation memory for AI assistants.
 *
 * Stores conversation summaries as graph nodes, retrieves relevant
 * past memories for current conversations, and manages memory decay
 * so older memories naturally lose relevance over time.
 *
 * @package NvoosGraphifyAi
 * @since   1.0.0
 */

declare(strict_types=1);

namespace NvoosGraphifyAi\Memory;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Schema;
use NvoosGraphifyAi\Embeddings\RagRetriever;

class AgentMemory {

	/**
	 * Memory node type used in the graph.
	 */
	private const NODE_TYPE = 'agent_memory';

	/**
	 * Default time-to-live in seconds before a memory starts decaying (30 days).
	 */
	private const DEFAULT_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Maximum memories to retrieve per recall.
	 */
	private const MAX_RECALL = 5;

	/**
	 * Decay half-life in seconds (7 days).
	 * After one half-life, a memory's relevance is halved.
	 */
	private const DECAY_HALF_LIFE = 7 * DAY_IN_SECONDS;

	public function __construct(
		private readonly RagRetriever $rag,
		private readonly ?EmbeddingService $embeddings = null,
	) {}

	/**
	 * Register WordPress hooks for memory events.
	 */
	public function register(): void {
		add_action( Schema::ACTION_AFTER_BUILD, array( $this, 'onAfterBuild' ) );
	}

	// ─── Memory Store ────────────────────────────────────────────────

	/**
	 * Store a conversation summary as a memory node.
	 *
	 * @param string $sessionId     Unique session identifier.
	 * @param string $summary       Conversation summary text.
	 * @param array  $metadata      Additional metadata (user_id, assistant_id, etc.).
	 * @param int    $ttlSeconds    Time-to-live before decay begins (0 = default).
	 *
	 * @return string|false  The node_id of the new memory, or false on failure.
	 */
	public function store( string $sessionId, string $summary, array $metadata = array(), int $ttlSeconds = 0 ): string|false {
		$summary = \trim( $summary );
		if ( '' === $summary ) {
			return false;
		}

		if ( $ttlSeconds <= 0 ) {
			$ttlSeconds = self::DEFAULT_TTL;
		}

		$expiresAt = \gmdate( 'Y-m-d H:i:s', \time() + $ttlSeconds );

		$nodeProps = \array_merge( $metadata, array(
			'session_id' => $sessionId,
			'summary'    => $summary,
			'stored_at'  => \current_time( 'mysql', true ),
			'expires_at' => $expiresAt,
			'source'     => 'agent_memory',
		) );

		$nodeId = 'memory_' . \wp_generate_uuid4();
		$label  = \mb_strlen( $summary ) > 120
			? \mb_substr( $summary, 0, 117 ) . '...'
			: $summary;

		global $wpdb;

		$result = $wpdb->insert(
			Db::nodesTable(),
			array(
				'node_id'      => $nodeId,
				'label'        => $label,
				'type'         => self::NODE_TYPE,
				'post_id'      => 0,
				'url'          => '',
				'properties'   => \json_encode( $nodeProps ),
				'community_id' => 'memories',
				'confidence'   => 1.0,
				'expires_at'   => $expiresAt,
				'created_at'   => \current_time( 'mysql', true ),
				'updated_at'   => \current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ),
		);

		if ( false === $result ) {
			return false;
		}

		// Fire the memory-stored event so the core bridge can link it.
		\do_action( Schema::ACTION_MEMORY_STORED, array(
			'node_id'    => $nodeId,
			'session_id' => $sessionId,
			'summary'    => $summary,
			'ttl'        => $ttlSeconds,
		) );

		return $nodeId;
	}

	// ─── Memory Recall ───────────────────────────────────────────────

	/**
	 * Recall memories relevant to the current conversation.
	 *
	 * Uses semantic search (RAG) to find past memories that are
	 * similar to the given query text. Results are ranked by a
	 * combination of semantic similarity and recency.
	 *
	 * @param string $query     The current user message or conversation context.
	 * @param int    $limit     Max memories to return (default: 5).
	 * @param string $provider  Embedding provider for semantic search.
	 *
	 * @return array<int, array{node_id: string, summary: string, similarity: float, age_days: int}>
	 */
	public function recall( string $query, int $limit = 0, string $provider = '' ): array {
		if ( $limit <= 0 ) {
			$limit = self::MAX_RECALL;
		}

		$query = \trim( $query );
		if ( '' === $query ) {
			return array();
		}

		// Search for relevant memory nodes.
		$results = $this->rag->search( $query, $limit * 2, $provider );

		if ( ! is_array( $results ) || array() === $results ) {
			return array();
		}

		// Filter to only agent_memory type nodes and enrich with content.
		$memories = array();
		foreach ( $results as $r ) {
			if ( ( $r['type'] ?? '' ) !== self::NODE_TYPE ) {
				continue;
			}

			$node = Db::get_node( $r['node_id'] );
			if ( null === $node ) {
				continue;
			}

			$props     = \json_decode( $node->properties ?? '{}', true ) ?: array();
			$summary   = $props['summary'] ?? $node->label;
			$storedAt  = $props['stored_at'] ?? $node->created_at;
			$ageDays   = $this->ageInDays( $storedAt );

			// Apply decay to the similarity score.
			$decayedSimilarity = $this->applyDecay( $r['similarity'], $ageDays );

			$memories[] = array(
				'node_id'    => $r['node_id'],
				'summary'    => $summary,
				'similarity' => \round( $decayedSimilarity, 4 ),
				'raw_sim'    => $r['similarity'],
				'age_days'   => $ageDays,
				'session_id' => $props['session_id'] ?? '',
			);
		}

		// Sort by decay-adjusted similarity.
		\usort( $memories, static fn( array $a, array $b ) => $b['similarity'] <=> $a['similarity'] );

		return \array_slice( $memories, 0, $limit );
	}

	/**
	 * Build a system message with relevant memories for the AI.
	 *
	 * @param string $query     Current user query.
	 * @param int    $limit     Max memories.
	 * @param string $provider  Embedding provider.
	 *
	 * @return string  System prompt with memories, or empty string.
	 */
	public function buildMemoryContext( string $query, int $limit = 5, string $provider = '' ): string {
		$memories = $this->recall( $query, $limit, $provider );

		if ( array() === $memories ) {
			return '';
		}

		$context = "You have memories from previous conversations that may be relevant:\n\n";

		foreach ( $memories as $i => $m ) {
			$num = $i + 1;
			$age = $m['age_days'] > 0
				? " ({$m['age_days']} days ago)"
				: ' (recent)';
			$context .= "{$num}.{$age} {$m['summary']}\n";
		}

		$context .= "\nReference these memories when they are relevant to the user's current question.";

		return $context;
	}

	// ─── Memory Decay ────────────────────────────────────────────────

	/**
	 * Apply exponential decay to a similarity score based on age.
	 *
	 * Uses a half-life model: score halves every DECAY_HALF_LIFE seconds.
	 *
	 * @param float $similarity  Raw similarity score.
	 * @param int   $ageDays     Age of the memory in days.
	 *
	 * @return float  Decay-adjusted similarity.
	 */
	private function applyDecay( float $similarity, int $ageDays ): float {
		if ( $ageDays <= 0 ) {
			return $similarity;
		}

		$ageSeconds    = $ageDays * DAY_IN_SECONDS;
		$halfLives     = $ageSeconds / self::DECAY_HALF_LIFE;
		$decayFactor   = \pow( 0.5, $halfLives );

		return $similarity * \max( 0.1, $decayFactor ); // Floor at 10% of original.
	}

	/**
	 * Purge memories past their expiry TTL.
	 *
	 * Should be called periodically (e.g., daily cron).
	 *
	 * @return int  Number of purged memories.
	 */
	public function purgeExpired(): int {
		global $wpdb;

		$table = Db::nodesTable();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE type = %s
				AND expires_at IS NOT NULL
				AND expires_at < %s",
				self::NODE_TYPE,
				\current_time( 'mysql', true ),
			),
		);
		// phpcs:enable

		return (int) $count;
	}

	// ─── Memory Mining ───────────────────────────────────────────────

	/**
	 * Mine a conversation transcript for key takeaways.
	 *
	 * Extracts topics, decisions, and action items from a full
	 * conversation transcript and returns structured memory candidates.
	 *
	 * Note: Full mining requires an LLM call. This method prepares
	 * the data for mining — the actual mining prompt is injected
	 * during the next chat turn.
	 *
	 * @param array  $messages  Full conversation messages.
	 * @param string $sessionId Session identifier.
	 *
	 * @return string  Mining prompt to be injected into the next chat turn.
	 */
	public function buildMiningPrompt( array $messages, string $sessionId ): string {
		$transcript = $this->formatTranscript( $messages );

		if ( '' === $transcript ) {
			return '';
		}

		return "After responding to the user, also produce a brief summary of this conversation "
			. "for future memory. Include: (1) key topics discussed, (2) any decisions made, "
			. "(3) user preferences or facts learned. Keep it under 3 sentences. "
			. "Session ID: {$sessionId}";
	}

	// ─── After-build handler ──────────────────────────────────────────

	/**
	 * Hook into after-build to purge expired memories.
	 */
	public function onAfterBuild(): void {
		$this->purgeExpired();
	}

	// ─── Helpers ──────────────────────────────────────────────────────

	/**
	 * Format a message array as a readable transcript.
	 *
	 * @param array $messages  OpenAI-format messages.
	 *
	 * @return string
	 */
	private function formatTranscript( array $messages ): string {
		$lines = array();

		foreach ( $messages as $msg ) {
			$role    = $msg['role'] ?? 'unknown';
			$content = $msg['content'] ?? '';

			if ( is_array( $content ) ) {
				// Multi-modal — extract text parts.
				$texts = array();
				foreach ( $content as $part ) {
					if ( isset( $part['text'] ) ) {
						$texts[] = $part['text'];
					}
				}
				$content = \implode( ' ', $texts );
			}

			if ( '' !== \trim( (string) $content ) ) {
				$lines[] = \ucfirst( $role ) . ': ' . $content;
			}
		}

		return \implode( "\n", $lines );
	}

	/**
	 * Calculate age in days from a stored timestamp.
	 */
	private function ageInDays( string $storedAt ): int {
		$stored = \strtotime( $storedAt );
		if ( false === $stored ) {
			return 0;
		}

		return (int) \floor( ( \time() - $stored ) / DAY_IN_SECONDS );
	}
}
