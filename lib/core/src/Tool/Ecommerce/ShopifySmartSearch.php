<?php

/**
 * Progressive query relaxation for Shopify product search.
 *
 * Proposal 164 section 164.113. Ported from `WP_MCP_AI_Shopify_Smart_Search`,
 * which is a sound idea: a store's search is a keyword matcher, so a long
 * natural-language query returns nothing, and the useful answer is to retry with
 * the query decomposed rather than tell the operator their product does not
 * exist.
 *
 * The algorithm is unchanged, because it is the behaviour being preserved:
 *
 * 1. Try the whole query first (the caller does that).
 * 2. On zero results, drop stop words; drop bare numeric tokens; then try
 *    overlapping trigrams and bigrams, capped at four sub-queries.
 * 3. Merge the result sets, scoring a product by how many sub-queries found it,
 *    and keep the original order within a score.
 *
 * Two corrections belong to the port rather than the algorithm:
 *
 * - **No caching.** The WordPress trait was pure, and so is this; the caller
 *   decides whether to spend the extra requests (the `smart_search` argument).
 * - **Merging is keyed on `id` explicitly.** The WordPress helper took a callback;
 *   every projection in this family carries `id`, so a callable parameter was a
 *   degree of freedom with no user.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

final class ShopifySmartSearch
{
    /**
     * A query needs at least this many meaningful tokens to be worth decomposing.
     */
    private const MIN_TOKENS = 3;

    /**
     * The most sub-queries one relaxed search may spend.
     */
    private const MAX_SUB_QUERIES = 4;

    /**
     * Common English words that carry no product meaning.
     */
    private const STOP_WORDS = array(
        'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of',
        'with', 'by', 'from', 'is', 'it', 'its', 'as', 'be', 'was', 'are', 'been',
        'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
        'should', 'may', 'might', 'that', 'this', 'these', 'those', 'i', 'me', 'my',
        'we', 'our', 'you', 'your', 'he', 'she', 'they', 'them', 'their', 'what',
        'which', 'who', 'whom', 'how', 'where', 'when', 'not', 'no', 'nor', 'so',
        'if', 'then', 'than', 'too', 'very', 'can', 'just', 'about', 'also', 'some',
        'any', 'all',
    );

    /**
     * The sub-queries to try after the original returned nothing.
     *
     * Empty when the query has too few meaningful tokens to be worth relaxing.
     *
     * @param string $query The original query.
     *
     * @return list<string> Sub-queries, broadest first.
     */
    public static function subQueries(string $query): array
    {
        $tokens = self::tokens($query);

        if (count($tokens) < self::MIN_TOKENS) {
            return array();
        }

        $subQueries = array();

        // 1. The query with stop words removed, when that differs from the input.
        $cleaned = implode(' ', $tokens);

        if ($cleaned !== mb_strtolower(trim($query))) {
            $subQueries[] = $cleaned;
        }

        // 2. Without bare numbers, which are usually measurements or counts.
        $withoutNumbers = array_values(
            array_filter(
                $tokens,
                static fn (string $token): bool => 1 !== preg_match('/^\d+(?:\.\d+)?$/', $token)
            )
        );

        if (count($withoutNumbers) < count($tokens) && count($withoutNumbers) >= 2) {
            $candidate = implode(' ', $withoutNumbers);

            if (! in_array($candidate, $subQueries, true)) {
                $subQueries[] = $candidate;
            }
        }

        // 3. Overlapping trigrams, then 4. bigrams if there is room.
        foreach (array(3, 2) as $window) {
            if (count($tokens) <= $window) {
                continue;
            }

            for ($start = 0; $start <= count($tokens) - $window; ++$start) {
                if (count($subQueries) >= self::MAX_SUB_QUERIES) {
                    break 2;
                }

                $candidate = implode(' ', array_slice($tokens, $start, $window));

                if (! in_array($candidate, $subQueries, true)) {
                    $subQueries[] = $candidate;
                }
            }
        }

        return array_slice($subQueries, 0, self::MAX_SUB_QUERIES);
    }

    /**
     * Deduplicate and rank products found by several sub-queries.
     *
     * A product found by more sub-queries ranks higher; ties keep the order they
     * were first seen in, so the result is deterministic.
     *
     * @param list<list<array<string, mixed>>> $resultSets Projected products, one list per sub-query.
     * @param int                              $limit      Maximum products to return.
     *
     * @return list<array<string, mixed>>
     */
    public static function rank(array $resultSets, int $limit): array
    {
        $seen   = array();
        $scores = array();
        $rows   = array();

        foreach ($resultSets as $set) {
            foreach ($set as $product) {
                $id = isset($product['id']) && is_scalar($product['id']) ? (string) $product['id'] : '';

                if ('' === $id) {
                    continue;
                }

                if (isset($seen[$id])) {
                    ++$scores[$id];
                    continue;
                }

                $seen[$id]   = count($rows);
                $scores[$id] = 1;
                $rows[]      = $product;
            }
        }

        $ranked = array();

        foreach ($rows as $index => $product) {
            $id       = (string) $product['id'];
            $ranked[] = array(
                'product' => $product,
                'score'   => $scores[$id],
                'order'   => $index,
            );
        }

        usort(
            $ranked,
            static function (array $left, array $right): int {
                if ($left['score'] === $right['score']) {
                    return $left['order'] <=> $right['order'];
                }

                return $right['score'] <=> $left['score'];
            }
        );

        $result = array();

        foreach ($ranked as $entry) {
            $result[] = $entry['product'];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Meaningful lowercase tokens: stop words removed, edges stripped.
     *
     * Numbers are kept, because they are often product attributes — "2 carat",
     * "14k", "350ml".
     *
     * @return list<string>
     */
    private static function tokens(string $query): array
    {
        $normalised = mb_strtolower(trim($query));
        $raw        = preg_split('/[\s,;|\/\-]+/', $normalised, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($raw)) {
            return array();
        }

        $tokens = array();

        foreach ($raw as $token) {
            $token = trim((string) preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/i', '', $token));

            if ('' === $token || in_array($token, self::STOP_WORDS, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }
}
