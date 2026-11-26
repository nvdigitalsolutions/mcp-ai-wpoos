<?php

/**
 * Topic-term extraction — pure domain service.
 *
 * ## Why this exists
 *
 * Retrieval needs search terms, and both tools that retrieve (`suggest_internal_links`
 * and `content_recommendation_engine`) derive them the same way. Two copies of a
 * ranking rule drift: one gains a stopword, the other does not, and the same page
 * yields different candidates depending on which tool asked. This is the single copy.
 *
 * ## What it is, and what it is not
 *
 * This is a **search hint**, not a keyword extractor with a quality claim. It takes
 * words of four characters or more, drops the ones that carry no topic, and ranks the
 * rest by how often they occur. That is cheap, local, deterministic, and — for picking
 * a query out of a paragraph — as good as the alternatives that cost a model call.
 *
 * A caller who knows the topic can always override the derivation; both tools accept
 * that override. This is the default, not the last word.
 *
 * ## Determinism
 *
 * Same text in, same terms out. `arsort()` is stable from PHP 8.0, so words with equal
 * frequency keep the order they first appeared in rather than shuffling between runs.
 * The tie order and the truncation are both pinned by tests.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Text;

final class TopicTerms
{
    /**
     * Below this, a word is more likely a joining word than a subject.
     */
    public const MIN_WORD_LENGTH = 4;

    /**
     * Three terms is a search, not a query plan. More terms mean more requests to
     * the store and a narrower intersection, not a better answer.
     */
    public const DEFAULT_LIMIT = 3;

    /**
     * Deliberately short. This is a hint: a long list would be a worse guess, not a
     * better one, and every entry here is a word that would otherwise waste a query.
     */
    private const STOPWORDS = array(
        'about', 'after', 'again', 'against', 'also', 'among', 'because', 'been',
        'before', 'being', 'between', 'both', 'could', 'does', 'doing', 'down',
        'during', 'each', 'from', 'further', 'have', 'having', 'here', 'hers',
        'into', 'itself', 'just', 'more', 'most', 'much', 'must', 'only', 'other',
        'over', 'same', 'should', 'some', 'such', 'than', 'that', 'their', 'them',
        'then', 'there', 'these', 'they', 'this', 'those', 'through', 'under',
        'until', 'very', 'was', 'were', 'what', 'when', 'where', 'which', 'while',
        'will', 'with', 'would', 'your', 'yours',
    );

    /**
     * Rank the words of the text by frequency, dropping the ones that carry no topic.
     *
     * Numeric words are dropped: a year or a price is a poor query on its own, and
     * `search` on the content store does not weight it differently from a subject.
     *
     * @return array<string, int> Word → occurrences, in descending frequency.
     */
    public function rank(string $text): array
    {
        $words = \preg_split('/[^\p{L}\p{N}]+/u', \mb_strtolower($text)) ?: array();

        $counts = array();

        foreach ($words as $word) {
            if (
                \mb_strlen($word) < self::MIN_WORD_LENGTH
                || \ctype_digit($word)
                || \in_array($word, self::STOPWORDS, true)
            ) {
                continue;
            }

            $counts[ $word ] = ( $counts[ $word ] ?? 0 ) + 1;
        }

        \arsort($counts);

        return $counts;
    }

    /**
     * The highest-frequency words, at most `$limit` of them.
     *
     * @return list<string>
     */
    public function top(string $text, int $limit = self::DEFAULT_LIMIT): array
    {
        return \array_slice(\array_keys($this->rank($text)), 0, \max(1, $limit));
    }
}
