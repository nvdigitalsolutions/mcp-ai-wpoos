<?php

/**
 * Hybrid knowledge router — which store should answer this question first?
 *
 * The runtime keeps curated knowledge in three places that answer different kinds
 * of question: OKF bundles hold authored, citable concepts; Paper Store holds
 * structured records; the vector store answers "what is like this". An agent with
 * all three available has no way to choose, so it usually picks the one it used
 * last — which is why this router exists.
 *
 * ## Deterministic on purpose
 *
 * The classifier is a keyword table, not a model call. Routing is cheap and
 * happens on every knowledge turn, so spending a completion to decide where to
 * spend a completion is the wrong trade. A caller that wants a smarter decision
 * can implement its own router; this one is the floor, and it says what it
 * matched so the decision is auditable.
 *
 * ## The fallback order is not arbitrary
 *
 * When nothing matches, the order is OKF, then vector, then Paper. Curated
 * knowledge answers most questions correctly and cheaply; semantic search answers
 * the rest of the prose questions; structured records answer the specific ones.
 * That is the order that spends the least before it finds an answer.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Okf;

use Nvoos\Core\Domain\Okf\OkfReaderInterface;

final class HybridKnowledgeRouter
{
    public const SOURCE_OKF = 'okf';

    public const SOURCE_VECTOR = 'vector';

    public const SOURCE_PAPER = 'paper';

    /** Curated, authoritative knowledge: policies, procedures, how-tos. */
    private const OKF_PATTERNS = array(
        'policy',
        'procedure',
        'how do',
        'how to',
        'how-to',
        'compute',
        'definition',
        'what is',
        'what are',
        'refund',
        'guide',
        'steps',
        'playbook',
        'reference',
        'standard',
        'rule',
        'protocol',
        'formula',
    );

    /** Structured records and event history. */
    private const PAPER_PATTERNS = array(
        'incident',
        'ticket',
        'log',
        'history',
        'record',
        'past ',
        'list all',
        'find all',
        'entry',
        'events',
        'inventory',
        'transaction',
        'entries',
        'report of',
    );

    /** Semantic similarity and unstructured discovery. */
    private const VECTOR_PATTERNS = array(
        'similar to',
        'semantic',
        'recommend',
        'related to',
        'something like',
        'unstructured',
        'resembles',
    );

    private const REASONS = array(
        self::SOURCE_OKF => 'Curated, authoritative knowledge match (policies, procedures, definitions).',
        self::SOURCE_PAPER => 'Structured records or event history match (incidents, logs, entries).',
        self::SOURCE_VECTOR => 'Semantic similarity needed; ranked embedding search.',
    );

    /**
     * Classify a query into an ordered routing plan.
     *
     * @return array{
     *     sources: list<array{source: string, reason: string}>,
     *     primary: string,
     *     signals: list<string>
     * }
     */
    public function classify(string $query): array
    {
        $needle = strtolower(trim($query));
        $matched = array();

        foreach (self::OKF_PATTERNS as $pattern) {
            if ('' !== $needle && str_contains($needle, $pattern)) {
                $matched[self::SOURCE_OKF] = true;
            }
        }

        foreach (self::PAPER_PATTERNS as $pattern) {
            if ('' !== $needle && str_contains($needle, $pattern)) {
                $matched[self::SOURCE_PAPER] = true;
            }
        }

        foreach (self::VECTOR_PATTERNS as $pattern) {
            if ('' !== $needle && str_contains($needle, $pattern)) {
                $matched[self::SOURCE_VECTOR] = true;
            }
        }

        $ordered = array();

        foreach (array(self::SOURCE_OKF, self::SOURCE_PAPER, self::SOURCE_VECTOR) as $source) {
            if (isset($matched[$source])) {
                $ordered[] = $source;
            }
        }

        foreach (array(self::SOURCE_OKF, self::SOURCE_VECTOR, self::SOURCE_PAPER) as $source) {
            if (! in_array($source, $ordered, true)) {
                $ordered[] = $source;
            }
        }

        $sources = array();

        foreach ($ordered as $source) {
            $sources[] = array('source' => $source, 'reason' => self::REASONS[$source]);
        }

        return array(
            'sources' => $sources,
            'primary' => $sources[0]['source'],
            'signals' => array_keys($matched),
        );
    }

    /**
     * Search one bundle by token overlap — deterministic, no model call.
     *
     * The score is the summed length of the query tokens found in a concept's
     * title and description, which rewards matching a long, specific word over
     * matching several short ones. That is a heuristic, and it is stated as one:
     * this is a routing aid, not a ranked retrieval engine.
     *
     * @return list<array{concept_id: string, title: string, description: string, trust_tier: string, stale: bool, score: int}>
     */
    public function search(OkfReaderInterface $reader, string $query, int $top = 5): array
    {
        $tokens = $this->tokens($query);

        if (array() === $tokens) {
            return array();
        }

        $scored = array();

        foreach ($reader->search(array()) as $concept) {
            $haystack = strtolower(
                (string) ($concept['title'] ?? '') . ' ' . (string) ($concept['description'] ?? ''),
            );

            $score = 0;

            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score += strlen($token);
                }
            }

            if ($score <= 0) {
                continue;
            }

            $scored[] = array(
                'concept_id' => (string) ($concept['concept_id'] ?? ''),
                'title' => (string) ($concept['title'] ?? ''),
                'description' => (string) ($concept['description'] ?? ''),
                'trust_tier' => (string) ($concept['trust_tier'] ?? ''),
                'stale' => (bool) ($concept['stale'] ?? false),
                'score' => $score,
            );
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, min(10, $top)));
    }

    /**
     * Lowercase words of at least three characters, de-duplicated.
     *
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $words = preg_split('/[^a-z0-9]+/i', strtolower(trim($query)));

        if (! is_array($words)) {
            return array();
        }

        $kept = array();

        foreach (array_unique($words) as $word) {
            if (strlen((string) $word) >= 3) {
                $kept[] = (string) $word;
            }
        }

        return $kept;
    }
}
