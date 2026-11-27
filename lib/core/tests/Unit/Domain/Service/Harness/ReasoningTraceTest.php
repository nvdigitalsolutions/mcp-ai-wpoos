<?php

/**
 * Tests for the reasoning trace schema and the self-consistency primitive.
 *
 * The load-bearing properties: junk degrades to a fresh trace rather than
 * throwing, unknown keys never survive, list fields are capped so adversarial
 * input cannot grow a trace without bound, and the vote normalises formatting
 * before counting with ties resolved in favour of the first candidate.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\ReasoningTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReasoningTraceTest extends TestCase
{
    public function test_a_new_trace_has_the_documented_shape(): void
    {
        $trace = ReasoningTrace::newTrace(1700000000);

        $this->assertSame('1.0', $trace['schema_version']);
        $this->assertSame('general', $trace['task_class']);
        $this->assertSame(array(), $trace['assumptions']);
        $this->assertSame(array(), $trace['constraints']);
        $this->assertSame(array(), $trace['plan']);
        $this->assertSame(array(), $trace['intermediate_results']);
        $this->assertSame(array(), $trace['verification']);
        $this->assertSame('', $trace['answer']);
        $this->assertSame(1700000000, $trace['created_at']);
    }

    #[DataProvider('junk')]
    public function test_junk_degrades_to_a_fresh_trace(mixed $raw): void
    {
        $trace = ReasoningTrace::sanitize($raw);

        $this->assertSame(ReasoningTrace::newTrace($trace['created_at']), $trace);
    }

    public static function junk(): array
    {
        return array(
            'null' => array( null ),
            'scalar' => array( 42 ),
            'empty string' => array( '' ),
            'malformed json' => array( '{not json' ),
            'empty array' => array( array() ),
        );
    }

    public function test_a_json_string_is_decoded(): void
    {
        $trace = ReasoningTrace::sanitize(
            '{"task_class":"Math Reasoning","plan":["a","b"],"created_at":1700000000}'
        );

        $this->assertSame('mathreasoning', $trace['task_class']);
        $this->assertSame(array( 'a', 'b' ), $trace['plan']);
        $this->assertSame(1700000000, $trace['created_at']);
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $trace = ReasoningTrace::sanitize(array( 'unknown_key' => 'x', 'created_at' => 1700000000 ));

        $this->assertArrayNotHasKey('unknown_key', $trace);
    }

    public function test_an_empty_task_class_falls_back_to_general(): void
    {
        $this->assertSame('general', ReasoningTrace::sanitize(array( 'task_class' => '!!!' ))['task_class']);
    }

    public function test_a_list_field_accepts_a_bare_string(): void
    {
        $this->assertSame(
            array( 'single answer' ),
            ReasoningTrace::sanitize(array( 'constraints' => 'single answer' ))['constraints']
        );
    }

    public function test_a_structured_item_is_encoded_rather_than_dropped(): void
    {
        $trace = ReasoningTrace::sanitize(array( 'plan' => array( array( 'step' => 1 ) ) ));

        $this->assertSame(array( '{"step":1}' ), $trace['plan']);
    }

    public function test_blank_items_are_dropped(): void
    {
        $trace = ReasoningTrace::sanitize(array( 'assumptions' => array( 'keep', '', '   ', "\n" ) ));

        $this->assertSame(array( 'keep' ), $trace['assumptions']);
    }

    public function test_every_list_is_capped(): void
    {
        $trace = ReasoningTrace::sanitize(array( 'plan' => array_fill(0, 60, 'step') ));

        $this->assertCount(ReasoningTrace::MAX_LIST_ITEMS, $trace['plan']);
    }

    public function test_a_non_positive_timestamp_is_ignored(): void
    {
        $trace = ReasoningTrace::sanitize(array( 'created_at' => 0 ));

        $this->assertGreaterThan(0, $trace['created_at']);
    }

    public function test_the_schema_version_is_stripped_of_markup(): void
    {
        $this->assertSame('2.0', ReasoningTrace::sanitize(array( 'schema_version' => '<b>2.0</b>' ))['schema_version']);
    }

    // ------------------------------------------------------------------- vote

    public function test_a_clear_majority_wins(): void
    {
        $result = ReasoningTrace::selfConsistencyVote(array( 'A', 'B', 'A' ));

        $this->assertSame('A', $result['answer']);
        $this->assertEqualsWithDelta(0.6667, $result['agreement'], 0.0001);
        $this->assertSame(3, $result['total']);
        $this->assertSame(array( 'A' => 2, 'B' => 1 ), $result['votes']);
    }

    public function test_a_tie_goes_to_the_first_candidate_seen(): void
    {
        $this->assertSame('B', ReasoningTrace::selfConsistencyVote(array( 'B', 'A' ))['answer']);
    }

    public function test_formatting_differences_do_not_fragment_the_vote(): void
    {
        $result = ReasoningTrace::selfConsistencyVote(array( 'Yes ', 'yes', ' no ' ));

        $this->assertSame('Yes ', $result['answer'], 'The first spelling is the one displayed.');
        $this->assertSame(2, $result['votes']['Yes '], 'Case and Spaces are normalised before counting.');
        $this->assertSame(3, $result['total']);
    }

    public function test_blank_candidates_are_ignored(): void
    {
        $result = ReasoningTrace::selfConsistencyVote(array( '', '   ', "\n" ));

        $this->assertSame('', $result['answer']);
        $this->assertSame(0.0, $result['agreement']);
        $this->assertSame(0, $result['total']);
    }

    public function test_a_single_candidate_is_unanimous(): void
    {
        $result = ReasoningTrace::selfConsistencyVote(array( 'only' ));

        $this->assertSame('only', $result['answer']);
        $this->assertSame(1.0, $result['agreement']);
    }

    public function test_numbers_are_compared_as_strings(): void
    {
        $result = ReasoningTrace::selfConsistencyVote(array( '42', 42 ));

        $this->assertSame('42', $result['answer']);
        $this->assertSame(2, $result['total']);
    }
}
