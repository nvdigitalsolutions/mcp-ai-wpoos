<?php

/**
 * Retrieval harness — merged recall with provenance.
 *
 * Fans a query out to the runtime's retrieval primitives, normalises their
 * heterogeneous payloads into one passage shape, deduplicates by content hash,
 * blends recency into the ranking, and returns the top k with the citation
 * metadata attached.
 *
 * Three properties matter and are each covered by a test:
 *
 *  - **Provenance always travels with the text** (OWASP LLM01): a passage is
 *    returned as `text` + `citation`, so a downstream consumer can tell
 *    document content from instructions.
 *  - **Deduplication keeps the best copy** — the same chunk can come from two
 *    sources, and the higher score wins rather than the first seen.
 *  - **A failing source is skipped, never fatal.** Retrieval runs before every
 *    turn it is enabled for, so one broken primitive must not take the turn
 *    down with it.
 *
 * Ported from `includes/harness/class-wp-mcp-ai-retrieval-harness.php`. The
 * core keeps the pipeline and the scoring; the runtime supplies the tool seam
 * and (via optional callbacks) its own extension hooks, so WordPress keeps its
 * `apply_filters` behaviour without dragging WordPress into this package.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

use Nvoos\Core\Domain\Contract\ToolInvokerInterface;

final class RetrievalHarness
{
	/** Maximum number of passages returned regardless of the caller's k. */
	public const HARD_K_CAP = 50;

	/** Weight of the raw score in the final ranking (the rest is freshness). */
	public const SCORE_WEIGHT = 0.7;

	/** Weight of freshness in the final ranking. */
	public const FRESHNESS_WEIGHT = 0.3;

	/** Freshness is 1.0 inside this window. */
	public const FRESHNESS_FRESH_SECONDS = 86400;

	/** Freshness decays to 0.0 at this age. */
	public const FRESHNESS_MAX_AGE_SECONDS = 31536000;

	/** Used when a payload carries no usable timestamp. */
	public const FRESHNESS_UNKNOWN = 0.5;

	/** Default score when a payload carries no score field. */
	public const DEFAULT_SCORE = 0.5;

	/** Token count per n-gram for the citation-support predicate. */
	public const NGRAM_SIZE = 5;

	/** Sentences shorter than this are not treated as claims. */
	public const MIN_SENTENCE_LENGTH = 12;

	/** Claim-like sentences shorter than this are ignored by the verifier. */
	public const MIN_CLAIM_LENGTH = 20;

	/** Upper bound on claims verified per response (a performance guard). */
	public const MAX_CLAIMS = 20;

	/** Default claim/source similarity threshold. */
	public const MIN_SIMILARITY = 0.3;

	/** Maximum similarity added for matching named entities. */
	public const ENTITY_BOOST = 0.2;

	/**
	 * Source tools, in order of preference.
	 *
	 * @var array<int, string>
	 */
	private const SOURCE_TOOLS = array(
		'recall_memory',
		'semantic_context_search',
		'retrieve_agent_memory',
	);

	/**
	 * @param ToolInvokerInterface $tools Runtime tool seam.
	 * @param int|null             $now   Clock override for freshness tests.
	 */
	public function __construct(
		private readonly ToolInvokerInterface $tools,
		private readonly ?int $now = null,
	) {
	}

	/**
	 * Retrieve passages with provenance.
	 *
	 * @param string               $query    User query.
	 * @param array<string, mixed> $scope    Optional scoping (assistant_id, wing, room, task_class).
	 * @param int                  $k        Desired passage count, clamped to HARD_K_CAP.
	 * @param array<string, mixed> $context  Tool execution context, forwarded unchanged.
	 * @param callable|null        $filterPassages Applied to the ranked top-k before aggregation.
	 *
	 * @return array{
	 *   passages: array<int, array<string, mixed>>,
	 *   citations: array<int, array<string, mixed>>,
	 *   freshness: float,
	 *   recall_confidence: float,
	 *   sources_tried: array<int, string>
	 * }
	 */
	public function retrieve(
		string $query,
		array $scope = array(),
		int $k = 5,
		array $context = array(),
		?callable $filterPassages = null,
	): array {
		$k = max(1, min(self::HARD_K_CAP, $k));

		$results = array();
		$tried = array();

		foreach (self::SOURCE_TOOLS as $slug) {
			$tried[] = $slug;

			$raw = $this->tools->invoke($slug, $this->buildArgsFor($slug, $query, $scope, $k), $context);

			if (null === $raw) {
				continue;
			}

			foreach ($this->normalizeResults($raw, $slug) as $entry) {
				$results[] = $entry;
			}
		}

		// Deduplicate by content hash, keeping the highest-scoring copy.
		$byHash = array();
		foreach ($results as $entry) {
			$hash = $entry['citation']['content_hash'];

			if (! isset($byHash[ $hash ]) || $byHash[ $hash ]['score'] < $entry['score']) {
				$byHash[ $hash ] = $entry;
			}
		}

		$ranked = array_values($byHash);

		foreach ($ranked as $index => $entry) {
			$ranked[ $index ]['ranked_score'] = (self::SCORE_WEIGHT * $entry['score'])
				+ (self::FRESHNESS_WEIGHT * $entry['freshness']);
		}

		usort(
			$ranked,
			static function (array $a, array $b): int {
				if ($a['ranked_score'] === $b['ranked_score']) {
					return 0;
				}

				return $a['ranked_score'] < $b['ranked_score'] ? 1 : -1;
			}
		);

		$top = array_slice($ranked, 0, $k);

		if (null !== $filterPassages) {
			$top = (array) $filterPassages($top);
		}

		$citations = array();
		$freshnessTotal = 0.0;
		$recallConfidence = 0.0;

		foreach ($top as $entry) {
			$citations[] = $entry['citation'];
			$freshnessTotal += (float) $entry['freshness'];
			$recallConfidence += (float) $entry['score'];
		}

		$count = max(1, count($top));

		return array(
			'passages' => $top,
			'citations' => $citations,
			'freshness' => round($freshnessTotal / $count, 4),
			'recall_confidence' => round($recallConfidence / $count, 4),
			'sources_tried' => $tried,
		);
	}

	/**
	 * Check whether each claim-like sentence in an answer is supported by the
	 * supplied passages.
	 *
	 * The predicate is deliberately conservative: a sentence needs one shared
	 * five-word sequence with a passage. That is a *floor* for support, not a
	 * claim of entailment — it under-reports support, which is the safe
	 * direction when the output is user-facing.
	 *
	 * @param string                            $answer   Answer text.
	 * @param array<int, array<string, mixed>>  $passages Passage list as returned by retrieve().
	 * @param callable|null                     $isSupported Override the predicate per sentence.
	 *
	 * @return array{covered: bool, coverage_ratio: float, unsupported: array<int, string>}
	 */
	public function verifyCitations(string $answer, array $passages, ?callable $isSupported = null): array
	{
		$answer = trim($answer);

		if ('' === $answer) {
			return array( 'covered' => true, 'coverage_ratio' => 1.0, 'unsupported' => array() );
		}

		$sentences = preg_split('/(?<=[\.!\?])\s+/', $answer);
		if (! is_array($sentences)) {
			$sentences = array( $answer );
		}

		$passageGrams = array();
		foreach ($passages as $entry) {
			$passageGrams[] = self::ngrams((string) ( $entry['text'] ?? '' ), self::NGRAM_SIZE);
		}

		$total = 0;
		$supported = 0;
		$unsupported = array();

		foreach ($sentences as $sentence) {
			$sentence = trim($sentence);

			if ('' === $sentence || strlen($sentence) < self::MIN_SENTENCE_LENGTH) {
				continue;
			}

			++$total;

			$grams = self::ngrams($sentence, self::NGRAM_SIZE);
			$supportedHere = false;

			foreach ($passageGrams as $candidate) {
				if (! empty(array_intersect_key($grams, $candidate))) {
					$supportedHere = true;
					break;
				}
			}

			if (null !== $isSupported) {
				$supportedHere = (bool) $isSupported($supportedHere, $sentence, $passages);
			}

			if ($supportedHere) {
				++$supported;
			} else {
				$unsupported[] = $sentence;
			}
		}

		if (0 === $total) {
			return array( 'covered' => true, 'coverage_ratio' => 1.0, 'unsupported' => array() );
		}

		return array(
			'covered' => empty($unsupported),
			'coverage_ratio' => round($supported / $total, 4),
			'unsupported' => $unsupported,
		);
	}

	/**
	 * Build the argument array for one underlying tool.
	 *
	 * @param array<string, mixed> $scope Scope hash.
	 * @return array<string, mixed>|null Null when the slug has no argument map.
	 */
	private function buildArgsFor(string $slug, string $query, array $scope, int $k): ?array
	{
		switch ($slug) {
			case 'recall_memory':
				return array(
					'query' => $query,
					'wing' => isset($scope['wing']) ? (string) $scope['wing'] : '',
					'room' => isset($scope['room']) ? (string) $scope['room'] : '',
					'limit' => $k,
				);

			case 'semantic_context_search':
				return array(
					'query' => $query,
					'limit' => $k,
				);

			case 'retrieve_agent_memory':
				return array(
					'query' => $query,
					'limit' => $k,
					'assistant_id' => isset($scope['assistant_id']) ? (int) $scope['assistant_id'] : 0,
				);

			default:
				return null;
		}
	}

	/**
	 * Normalise a tool payload into the passage shape.
	 *
	 * Shape handling is defensive on purpose: the three primitives return
	 * different envelopes, and a payload that does not fit yields an empty list
	 * rather than an exception.
	 *
	 * @param mixed $raw Raw tool payload.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeResults(mixed $raw, string $slug): array
	{
		if (! is_array($raw)) {
			return array();
		}

		if (isset($raw['data']) && is_array($raw['data'])) {
			$candidates = $raw['data'];
		} elseif (isset($raw['results']) && is_array($raw['results'])) {
			$candidates = $raw['results'];
		} elseif (isset($raw['memories']) && is_array($raw['memories'])) {
			$candidates = $raw['memories'];
		} elseif (self::looksLikeList($raw)) {
			$candidates = $raw;
		} else {
			$candidates = array();
		}

		$out = array();

		foreach ($candidates as $entry) {
			if (! is_array($entry)) {
				continue;
			}

			$text = '';
			foreach (array( 'content', 'text', 'passage', 'body', 'snippet' ) as $key) {
				if (isset($entry[ $key ]) && is_string($entry[ $key ])) {
					$text = $entry[ $key ];
					break;
				}
			}

			if ('' === trim($text)) {
				continue;
			}

			$score = self::DEFAULT_SCORE;
			foreach (array( 'score', 'similarity', 'relevance', 'confidence' ) as $key) {
				if (isset($entry[ $key ]) && is_numeric($entry[ $key ])) {
					$score = (float) $entry[ $key ];
					break;
				}
			}

			// Some tools report 0-100.
			if ($score > 1.0) {
				$score /= 100.0;
			}

			$timestamp = 0;
			foreach (array( 'timestamp', 'created_at', 'modified_at', 'date' ) as $key) {
				if (! isset($entry[ $key ])) {
					continue;
				}

				$ts = is_numeric($entry[ $key ]) ? (int) $entry[ $key ] : strtotime((string) $entry[ $key ]);

				if ($ts > 0) {
					$timestamp = $ts;
					break;
				}
			}

			$sourceId = '';
			foreach (array( 'id', 'post_id', 'memory_id', 'session_key', 'content_hash' ) as $key) {
				if (isset($entry[ $key ])) {
					$sourceId = (string) $entry[ $key ];
					break;
				}
			}

			$contentHash = isset($entry['content_hash']) && is_string($entry['content_hash'])
				? $entry['content_hash']
				: md5($slug . '|' . $text);

			$out[] = array(
				'text' => $text,
				'source' => $slug,
				'score' => max(0.0, min(1.0, $score)),
				'freshness' => $this->freshnessScore($timestamp),
				'citation' => array(
					'source' => $slug,
					'source_id' => $sourceId,
					'timestamp' => $timestamp,
					'content_hash' => $contentHash,
				),
			);
		}

		return $out;
	}

	/**
	 * Does the array look like a list (sequential integer keys)?
	 */
	private static function looksLikeList(array $arr): bool
	{
		if (empty($arr)) {
			return false;
		}

		return array_keys($arr) === range(0, count($arr) - 1);
	}

	/**
	 * Build an n-gram set for a string: lowercased, whitespace-collapsed.
	 *
	 * @return array<string, bool>
	 */
	private static function ngrams(string $text, int $n): array
	{
		$text = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));

		if ('' === $text) {
			return array();
		}

		$tokens = preg_split('/\s+/', $text);
		if (! is_array($tokens)) {
			return array();
		}

		$count = count($tokens);

		if ($count < $n) {
			return array( implode(' ', $tokens) => true );
		}

		$grams = array();
		for ($i = 0; $i <= $count - $n; ++$i) {
			$grams[ implode(' ', array_slice($tokens, $i, $n)) ] = true;
		}

		return $grams;
	}

	/**
	 * Freshness on [0,1]: 1.0 inside a day, 0.0 past a year, linear between.
	 *
	 * An unknown timestamp is neutral (0.5) so a source without a date is
	 * neither promoted nor buried.
	 */
	private function freshnessScore(int $timestamp): float
	{
		if ($timestamp <= 0) {
			return self::FRESHNESS_UNKNOWN;
		}

		$age = max(0, ( $this->now ?? time() ) - $timestamp);

		if ($age <= self::FRESHNESS_FRESH_SECONDS) {
			return 1.0;
		}

		if ($age >= self::FRESHNESS_MAX_AGE_SECONDS) {
			return 0.0;
		}

		return round(
			1.0 - ( ( $age - self::FRESHNESS_FRESH_SECONDS )
				/ ( self::FRESHNESS_MAX_AGE_SECONDS - self::FRESHNESS_FRESH_SECONDS ) ),
			4
		);
	}
}
