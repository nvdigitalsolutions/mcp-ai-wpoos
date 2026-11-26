<?php

/**
 * TF-IDF and BM25 relevance scoring over tool records.
 *
 * Ported from `WP_MCP_AI_CRM_Relevance_Search`, the Pro trait the three
 * `crm_email_search_*` tools share. The trait reads fields from WordPress posts
 * by id; this class takes a **text provider** instead, so the same maths runs
 * over content items, arrays, or anything else.
 *
 * The formulas are kept exactly:
 *
 * - **TF-IDF**: `idf_proxy = 1 + log(max(1, strlen(token)))`;
 *   `score = min(100, round(Σ tf × idf_proxy × weight × 100/30, 1))`.
 *   The IDF is a token-length proxy, not a corpus statistic — that is the
 *   trait's documented simplification for under ~5K records.
 * - **BM25**: `idf = log((N − df + 0.5) / (df + 0.5) + 1)`,
 *   `score = Σ idf × tf(k1+1) / (tf + k1(1 − b + b·dl/avgdl)) × weight`,
 *   with `k1 = 1.5` and `b = 0.75`.
 *
 * Ties keep the incoming order (`_original_index`), so a ranked result is
 * stable across runs.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

final class RelevanceRanker
{
    /** Field weights the CRM tools use. */
    public const DEFAULT_WEIGHTS = array(
        'name'    => 3.0,
        'company' => 2.0,
        'email'   => 1.5,
    );

    /** BM25 term-frequency saturation. */
    public const BM25_K1 = 1.5;

    /** BM25 document-length normalisation. */
    public const BM25_B = 0.75;

    /** English stop words, as the trait lists them. */
    private const STOP_WORDS = array(
        'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'is', 'it', 'its', 'as', 'be', 'was',
        'are', 'been', 'has', 'have', 'had', 'do', 'does', 'did', 'will',
        'would', 'could', 'should', 'may', 'might', 'that', 'this', 'these',
        'those', 'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she',
        'they', 'them', 'their', 'what', 'which', 'who', 'whom', 'how',
        'where', 'when', 'not', 'no', 'nor', 'so', 'if', 'then', 'than', 'too',
        'very', 'can', 'just', 'about', 'also', 'some', 'any', 'all',
    );

    /**
     * Tokenize a query: lowercase, split, trim edges, drop stop words.
     *
     * @return list<string>
     */
    public function tokenize(string $query): array
    {
        $raw = preg_split('/[\s,;|\/\-]+/', strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($raw)) {
            return array();
        }

        $tokens = array();

        foreach ($raw as $token) {
            $clean = preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/i', '', $token);

            if (! is_string($clean) || '' === $clean) {
                continue;
            }

            if (in_array($clean, self::STOP_WORDS, true)) {
                continue;
            }

            $tokens[] = $clean;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Rank records by relevance.
     *
     * @param array<int, array<string, mixed>> $records
     * @param callable(array<string, mixed>): array<string, string>|null $provider
     *        Returns field => text. Defaults to the CRM fields on the record.
     * @param array<string, float> $fieldWeights
     * @return array<int, array<string, mixed>>
     */
    public function rank(
        array $records,
        string $query,
        ?callable $provider = null,
        array $fieldWeights = array(),
        string $algorithm = 'tfidf',
    ): array {
        if ('' === $query || array() === $records) {
            return $records;
        }

        $tokens = $this->tokenize($query);

        if (array() === $tokens) {
            return $records;
        }

        $weights  = array() !== $fieldWeights ? $fieldWeights : self::DEFAULT_WEIGHTS;
        $provider = $provider ?? $this->defaultProvider(...);

        if ('bm25' === $algorithm) {
            return $this->rankWithBm25($records, $tokens, $provider, $weights);
        }

        $scored = array();

        foreach ($records as $index => $record) {
            $record['relevance_score'] = $this->tfidfScore($tokens, $provider($record), $weights);
            $record['_original_index'] = $index;
            $scored[]                  = $record;
        }

        return $this->sortAndClean($scored);
    }

    /**
     * The default provider: the CRM name, company and email fields.
     *
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    public function defaultProvider(array $record): array
    {
        return array(
            'name'    => strtolower((string) ( $record['name'] ?? '' )),
            'company' => strtolower((string) ( $record['company'] ?? '' )),
            'email'   => strtolower((string) ( $record['email'] ?? '' )),
        );
    }

    /**
     * TF-IDF score for one document.
     *
     * @param list<string> $tokens
     * @param array<string, string> $fields
     * @param array<string, float> $weights
     */
    public function tfidfScore(array $tokens, array $fields, array $weights): float
    {
        $total = 0.0;

        foreach ($tokens as $token) {
            $idfProxy = 1.0 + log(max(1, strlen($token)));

            foreach ($fields as $field => $text) {
                if ('' === $text) {
                    continue;
                }

                $tf = substr_count($text, $token);

                if (0 === $tf) {
                    continue;
                }

                $total += $tf * $idfProxy * ( $weights[ $field ] ?? 1.0 );
            }
        }

        return min(100.0, round($total * ( 100.0 / 30.0 ), 1));
    }

    /**
     * BM25 ranking with corpus statistics.
     *
     * @param array<int, array<string, mixed>> $records
     * @param list<string> $tokens
     * @param callable(array<string, mixed>): array<string, string> $provider
     * @param array<string, float> $weights
     * @return array<int, array<string, mixed>>
     */
    private function rankWithBm25(array $records, array $tokens, callable $provider, array $weights): array
    {
        $docCount   = count($records);
        $docLengths = array();
        $totalLength = 0;
        $docFreq    = array_fill_keys($tokens, 0);

        foreach ($records as $index => $record) {
            $length                = $this->documentLength($provider($record));
            $docLengths[ $index ]  = $length;
            $totalLength          += $length;

            $allText = implode(' ', $provider($record));

            foreach ($tokens as $token) {
                if (false !== strpos($allText, $token)) {
                    ++$docFreq[ $token ];
                }
            }
        }

        $idf = array();

        foreach ($tokens as $token) {
            $df = $docFreq[ $token ];
            $idf[ $token ] = 0 === $df
                ? 0.0
                : log(( $docCount - $df + 0.5 ) / ( $df + 0.5 ) + 1.0);
        }

        $average = $docCount > 0 ? $totalLength / $docCount : 1.0;
        $scored  = array();

        foreach ($records as $index => $record) {
            $fields = $provider($record);
            $length = $docLengths[ $index ];
            $score  = 0.0;

            foreach ($tokens as $token) {
                if (( $idf[ $token ] ?? 0.0 ) <= 0.0) {
                    continue;
                }

                foreach ($fields as $field => $text) {
                    if ('' === $text) {
                        continue;
                    }

                    $tf = substr_count($text, $token);

                    if (0 === $tf) {
                        continue;
                    }

                    $numerator   = $tf * ( self::BM25_K1 + 1.0 );
                    $denominator = $tf + self::BM25_K1 * ( 1.0 - self::BM25_B + self::BM25_B * ( $length / max(1.0, $average) ) );

                    $score += $idf[ $token ] * ( $numerator / max(0.001, $denominator) ) * ( $weights[ $field ] ?? 1.0 );
                }
            }

            $record['relevance_score'] = round($score, 2);
            $record['_original_index'] = $index;
            $scored[]                  = $record;
        }

        return $this->sortAndClean($scored);
    }

    /**
     * Word count across a document's fields, at least 1.
     *
     * @param array<string, string> $fields
     */
    private function documentLength(array $fields): int
    {
        $total = 0;

        foreach ($fields as $text) {
            if ('' === $text) {
                continue;
            }

            $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
            $total += is_array($words) ? count($words) : 0;
        }

        return max(1, $total);
    }

    /**
     * Sort by score descending, ties in arrival order, and drop the index.
     *
     * @param array<int, array<string, mixed>> $scored
     * @return array<int, array<string, mixed>>
     */
    private function sortAndClean(array $scored): array
    {
        usort(
            $scored,
            static function (array $a, array $b): int {
                $diff = $b['relevance_score'] - $a['relevance_score'];

                if (abs($diff) < 0.001) {
                    return $a['_original_index'] <=> $b['_original_index'];
                }

                return $diff > 0 ? 1 : -1;
            }
        );

        foreach ($scored as &$record) {
            unset($record['_original_index']);
        }

        unset($record);

        return $scored;
    }
}
