<?php

/**
 * Tests for the retrieval harness.
 *
 * The load-bearing properties: provenance always travels with the text, a
 * duplicate keeps its best-scoring copy, recency moves the ranking without
 * dominating it, k is clamped, and a failing source is skipped rather than
 * fatal.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Contract\ToolInvokerInterface;
use Nvoos\Core\Domain\Service\Harness\RetrievalHarness;
use PHPUnit\Framework\TestCase;

final class RetrievalHarnessTest extends TestCase
{
    private const NOW = 1763200000; // 2026-11-15T08:26:40Z

    /**
     * Build a harness over a slug => payload map. A slug mapped to null emulates
     * a missing/failed tool.
     *
     * @param array<string, mixed> $payloads Slug => payload.
     * @param array<int, string>   $calls    Receives the slugs in call order.
     */
    private function harness(array $payloads, array &$calls = array()): RetrievalHarness
    {
        $invoker = new class ($payloads, $calls) implements ToolInvokerInterface {
            /** @var array<string, mixed> */
            private array $payloads;

            /** @var array<int, string> */
            private array $calls;

            public function __construct(array $payloads, array &$calls)
            {
                $this->payloads = $payloads;
                $this->calls = &$calls;
            }

            public function invoke(string $slug, array $arguments, array $context): mixed
            {
                $this->calls[] = $slug;

                return $this->payloads[ $slug ] ?? null;
            }
        };

        return new RetrievalHarness($invoker, self::NOW);
    }

    public function test_a_passage_carries_provenance(): void
    {
        $result = $this->harness(
            array( 'recall_memory' => array( array( 'content' => 'Quarterly revenue rose 12%.', 'id' => 'mem-1', 'score' => 0.9 ) ) )
        )->retrieve('revenue');

        $this->assertCount(1, $result['passages']);
        $this->assertSame('Quarterly revenue rose 12%.', $result['passages'][0]['text']);
        $this->assertSame('recall_memory', $result['passages'][0]['source']);

        $citation = $result['passages'][0]['citation'];
        $this->assertSame('recall_memory', $citation['source']);
        $this->assertSame('mem-1', $citation['source_id']);
        $this->assertNotEmpty($citation['content_hash']);
    }

    public function test_every_source_is_tried_in_order(): void
    {
        $calls = array();
        $this->harness(array(), $calls)->retrieve('anything');

        $this->assertSame(
            array( 'recall_memory', 'semantic_context_search', 'retrieve_agent_memory' ),
            $calls
        );
    }

    public function test_a_failing_source_is_skipped(): void
    {
        $result = $this->harness(
            array(
                'recall_memory' => null,
                'retrieve_agent_memory' => array( array( 'content' => 'A surviving passage about refunds.' ) ),
            )
        )->retrieve('refunds');

        $this->assertCount(1, $result['passages']);
        $this->assertSame('retrieve_agent_memory', $result['passages'][0]['source']);
    }

    public function test_a_duplicate_content_hash_keeps_the_highest_score(): void
    {
        $result = $this->harness(
            array(
                'recall_memory' => array( array( 'content' => 'Same text.', 'score' => 0.2, 'content_hash' => 'h1' ) ),
                'semantic_context_search' => array( array( 'content' => 'Same text.', 'score' => 0.8, 'content_hash' => 'h1' ) ),
            )
        )->retrieve('same');

        $this->assertCount(1, $result['passages']);
        $this->assertSame(0.8, $result['passages'][0]['score']);
    }

    public function test_identical_text_without_a_hash_is_not_collapsed_across_sources(): void
    {
        // Faithful to the WordPress implementation: the fallback hash mixes in the
        // source slug, so two sources returning the same words stay two passages.
        // Cross-source deduplication only happens when the payload carries its own
        // `content_hash`. Pinned so a future "fix" is a deliberate decision.
        $result = $this->harness(
            array(
                'recall_memory' => array( array( 'content' => 'Same text.', 'score' => 0.2 ) ),
                'semantic_context_search' => array( array( 'content' => 'Same text.', 'score' => 0.8 ) ),
            )
        )->retrieve('same');

        $this->assertCount(2, $result['passages']);
    }

    public function test_the_ranking_is_a_seventy_thirty_blend(): void
    {
        // Freshness spans the whole range, so a 0.3 weight can outweigh a 0.05
        // score gap. That is the documented formula, not a bug.
        $result = $this->harness(
            array(
                'recall_memory' => array(
                    array( 'content' => 'Stale but strong.', 'score' => 0.90, 'timestamp' => self::NOW - ( 400 * 86400 ) ),
                    array( 'content' => 'Fresh and slightly weaker.', 'score' => 0.85, 'timestamp' => self::NOW ),
                ),
            )
        )->retrieve('x');

        $this->assertSame('Fresh and slightly weaker.', $result['passages'][0]['text']);
        $this->assertSame(0.895, round($result['passages'][0]['ranked_score'], 3));
        $this->assertSame(0.63, round($result['passages'][1]['ranked_score'], 3));
    }

    public function test_a_fresh_passage_beats_an_equal_scoring_stale_one(): void
    {
        $result = $this->harness(
            array(
                'recall_memory' => array(
                    array( 'content' => 'Equally scored but stale.', 'score' => 0.5, 'timestamp' => self::NOW - ( 300 * 86400 ) ),
                    array( 'content' => 'Equally scored and fresh.', 'score' => 0.5, 'timestamp' => self::NOW ),
                ),
            )
        )->retrieve('x');

        $this->assertSame('Equally scored and fresh.', $result['passages'][0]['text']);
    }

    public function test_k_is_clamped_and_capped(): void
    {
        $passages = array();
        for ($i = 0; $i < 80; ++$i) {
            $passages[] = array( 'content' => 'Passage number ' . $i . ' about topic.', 'score' => 0.5 );
        }

        $harness = $this->harness(array( 'recall_memory' => $passages ));

        $this->assertCount(1, $harness->retrieve('x', array(), 0)['passages'], 'k floors at 1.');
        $this->assertCount(RetrievalHarness::HARD_K_CAP, $harness->retrieve('x', array(), 500)['passages']);
    }

    public function test_a_percent_scored_payload_is_normalised(): void
    {
        $result = $this->harness(
            array( 'recall_memory' => array( array( 'content' => 'Scored out of a hundred.', 'score' => 75 ) ) )
        )->retrieve('x');

        $this->assertSame(0.75, $result['passages'][0]['score']);
    }

    public function test_wrapped_payload_shapes_are_accepted(): void
    {
        foreach (array( 'data', 'results', 'memories' ) as $envelope) {
            $result = $this->harness(
                array( 'recall_memory' => array( $envelope => array( array( 'text' => 'Wrapped passage for ' . $envelope . '.' ) ) ) )
            )->retrieve('x');

            $this->assertCount(1, $result['passages'], $envelope . ' envelope should be read.');
        }
    }

    public function test_an_unusable_payload_yields_no_passages(): void
    {
        foreach (array( 'just a string', 42, array( 'data' => 'nope' ), array() ) as $payload) {
            $result = $this->harness(array( 'recall_memory' => $payload ))->retrieve('x');

            $this->assertSame(array(), $result['passages']);
            $this->assertSame(0.0, $result['recall_confidence']);
        }
    }

    public function test_the_aggregates_describe_the_returned_set(): void
    {
        $result = $this->harness(
            array(
                'recall_memory' => array(
                    array( 'content' => 'First passage here.', 'score' => 0.6, 'timestamp' => self::NOW ),
                    array( 'content' => 'Second passage here.', 'score' => 0.4, 'timestamp' => self::NOW ),
                ),
            )
        )->retrieve('x');

        $this->assertCount(2, $result['citations']);
        $this->assertSame(1.0, $result['freshness'], 'Both passages are fresh.');
        $this->assertSame(0.5, $result['recall_confidence'], 'Mean of 0.6 and 0.4.');
    }

    public function test_the_passage_filter_can_rewrite_the_top_k(): void
    {
        $result = $this->harness(
            array( 'recall_memory' => array( array( 'content' => 'Drop me.', 'score' => 0.9 ) ) )
        )->retrieve('x', array(), 5, array(), static fn (array $top): array => array());

        $this->assertSame(array(), $result['passages']);
        $this->assertSame(0.0, $result['recall_confidence'], 'Aggregates follow the filtered set.');
    }

    // ---------------------------------------------------------------- citations

    public function test_a_supported_sentence_is_covered(): void
    {
        $passages = array( array( 'text' => 'Revenue rose twelve percent in the third quarter of the year.' ) );

        $result = $this->harness(array())->verifyCitations(
            'Revenue rose twelve percent in the third quarter of the year.',
            $passages
        );

        $this->assertTrue($result['covered']);
        $this->assertSame(1.0, $result['coverage_ratio']);
        $this->assertSame(array(), $result['unsupported']);
    }

    public function test_an_unsupported_sentence_is_reported(): void
    {
        $passages = array( array( 'text' => 'Revenue rose twelve percent in the third quarter of the year.' ) );

        $result = $this->harness(array())->verifyCitations(
            'Margins collapsed because a supplier went bankrupt without notice.',
            $passages
        );

        $this->assertFalse($result['covered']);
        $this->assertSame(0.0, $result['coverage_ratio']);
        $this->assertCount(1, $result['unsupported']);
    }

    public function test_short_sentences_are_not_treated_as_claims(): void
    {
        $result = $this->harness(array())->verifyCitations('Yes. No.', array());

        $this->assertTrue($result['covered']);
        $this->assertSame(1.0, $result['coverage_ratio']);
    }

    public function test_an_empty_answer_is_trivially_covered(): void
    {
        $result = $this->harness(array())->verifyCitations('', array());

        $this->assertTrue($result['covered']);
        $this->assertSame(array(), $result['unsupported']);
    }

    public function test_the_support_predicate_can_be_overridden(): void
    {
        $result = $this->harness(array())->verifyCitations(
            'This sentence has no overlap whatsoever with anything.',
            array( array( 'text' => 'Unrelated passage text entirely.' ) ),
            static fn (): bool => true
        );

        $this->assertTrue($result['covered'], 'The override wins over the n-gram floor.');
    }
}
