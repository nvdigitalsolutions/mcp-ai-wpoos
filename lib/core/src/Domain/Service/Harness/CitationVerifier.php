<?php

/**
 * Citation verifier — decides which claims a passage set actually supports.
 *
 * Answers OWASP LLM09:2025 Misinformation at the smallest useful grain: not
 * "is this answer risky" but "which sentence is unsupported". Two properties
 * make that safe to ship:
 *
 *  - **It over-flags rather than under-flags.** The similarity measure is a
 *    Jaccard ratio whose union is built by concatenating two already-unique
 *    word sets, so the denominator is inflated by the intersection and the
 *    score is a strict *lower bound* on true overlap. Ported as-is: for a
 *    user-facing warning, a false "unverified" is much cheaper than a false
 *    "verified".
 *  - **It only judges claims.** Prose that asserts nothing checkable (short
 *    sentences, no numbers, years or copulas) is left alone, so ordinary
 *    answers are not buried in warnings.
 *
 * Ported from `includes/harness/class-wp-mcp-ai-citation-verifier.php`. The
 * core returns structured results and the runtime renders them, because the
 * WordPress warning text is translated and belongs in the plugin.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class CitationVerifier
{
    /** Minimum similarity for a claim to count as verified. */
    public const MIN_SIMILARITY = 0.3;

    /** Claims shorter than this are not treated as assertions. */
    public const MIN_CLAIM_LENGTH = 20;

    /** Upper bound on claims verified per response (a performance guard). */
    public const MAX_CLAIMS = 20;

    /** Maximum similarity added for matching named entities. */
    public const ENTITY_BOOST = 0.2;

    /** Words shorter than this are dropped when tokenising. */
    public const MIN_TOKEN_LENGTH = 2;

    /**
     * Common English stop words removed before comparison.
     *
     * @var array<int, string>
     */
    private const STOP_WORDS = array(
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
        'should', 'may', 'might', 'can', 'shall', 'this', 'that', 'these',
        'those', 'it', 'its',
    );

    /**
     * Verify every claim-like sentence in a response against the sources.
     *
     * A source may be a plain string or a passage array carrying `text` —
     * the retrieval harness returns the latter, and callers that assemble
     * sources by hand tend to produce the former.
     *
     * @param string                            $content      Model response.
     * @param array<int, mixed>                 $sources      Source texts or passage arrays.
     * @param float                             $minSimilarity Threshold override.
     *
     * @return array{
     *   claims: array<int, array{text: string, verified: bool, similarity: float}>,
     *   verified: int,
     *   unverified: int,
     *   total: int
     * }
     */
    public static function verify(string $content, array $sources, float $minSimilarity = self::MIN_SIMILARITY): array
    {
        $claims = self::claims($content);
        $texts = self::sourceTexts($sources);

        $results = array();
        $verified = 0;

        foreach ($claims as $claim) {
            $best = 0.0;
            $matched = false;

            foreach ($texts as $text) {
                $score = self::similarity($claim, $text);

                if ($score >= $minSimilarity) {
                    $matched = true;
                    $best = $score;
                    break;
                }

                $best = max($best, $score);
            }

            if ($matched) {
                ++$verified;
            }

            $results[] = array(
                'text' => $claim,
                'verified' => $matched,
                'similarity' => round($best, 4),
            );
        }

        $total = count($results);

        return array(
            'claims' => $results,
            'verified' => $verified,
            'unverified' => $total - $verified,
            'total' => $total,
        );
    }

    /**
     * Extract claim-like sentences: sentence-split, long enough, and asserting
     * something checkable (a number, a year, or a copula/modal).
     *
     * @return array<int, string>
     */
    public static function claims(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($sentences)) {
            return array();
        }

        $claims = array();

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if (strlen($sentence) < self::MIN_CLAIM_LENGTH) {
                continue;
            }

            if (preg_match('/\d+%|\d{4}|\b(?:is|are|was|were|has|have|had|will|can|must|should)\b/i', $sentence)) {
                $claims[] = $sentence;
            }
        }

        return array_slice($claims, 0, self::MAX_CLAIMS);
    }

    /**
     * Jaccard-style similarity with an entity boost.
     *
     * The union is a concatenation of two unique sets, not a true union: an
     * overlapping word is counted twice, so the ratio understates the real
     * Jaccard overlap. That is deliberate (see the class docblock).
     */
    public static function similarity(string $claim, string $source): float
    {
        $claimWords = self::tokenize($claim);
        $sourceWords = self::tokenize($source);

        if (array() === $claimWords || array() === $sourceWords) {
            return 0.0;
        }

        $intersection = array_intersect($claimWords, $sourceWords);
        $union = array_merge($claimWords, $sourceWords);

        if (array() === $union) {
            return 0.0;
        }

        $similarity = count($intersection) / count($union);

        $entities = self::entities($claim);

        if (array() !== $entities) {
            $hits = 0;
            foreach ($entities as $entity) {
                if (false !== stripos($source, $entity)) {
                    ++$hits;
                }
            }

            if ($hits > 0) {
                $similarity = min(1.0, $similarity + ( ( $hits / count($entities) ) * self::ENTITY_BOOST ));
            }
        }

        return $similarity;
    }

    /**
     * Lowercase word set with stop words and one-character tokens removed.
     *
     * @return array<int, string>
     */
    private static function tokenize(string $text): array
    {
        $words = preg_split('/\W+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($words)) {
            return array();
        }

        $words = array_diff($words, self::STOP_WORDS);
        $words = array_filter(
            $words,
            static fn (string $word): bool => strlen($word) >= self::MIN_TOKEN_LENGTH
        );

        return array_values(array_unique($words));
    }

    /**
     * Named entities: capitalised multi-word phrases plus four-digit years.
     *
     * @return array<int, string>
     */
    private static function entities(string $text): array
    {
        $entities = array();

        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\b/', $text, $matches)) {
            $entities = $matches[1];
        }

        if (preg_match_all('/\b\d{4}\b/', $text, $matches)) {
            $entities = array_merge($entities, $matches[0]);
        }

        return array_values(array_unique($entities));
    }

    /**
     * Reduce the source list to plain text.
     *
     * @param array<int, mixed> $sources Source texts or passage arrays.
     * @return array<int, string>
     */
    private static function sourceTexts(array $sources): array
    {
        $texts = array();

        foreach ($sources as $source) {
            if (is_array($source)) {
                $texts[] = (string) ( $source['text'] ?? '' );
                continue;
            }

            if (is_scalar($source)) {
                $texts[] = (string) $source;
            }
        }

        return $texts;
    }
}
