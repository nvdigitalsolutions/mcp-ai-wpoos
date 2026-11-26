<?php

/**
 * Tests for the shared topic-term derivation.
 *
 * These pin the contract both retrieval tools depend on: what counts as a word, what
 * is dropped, the order, and the truncation. The tie-order test matters because a
 * ranking that shuffles between runs makes every consumer's output irreproducible.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Text;

use Nvoos\Core\Domain\Service\Text\TopicTerms;
use PHPUnit\Framework\TestCase;

final class TopicTermsTest extends TestCase
{
    public function testItRanksByFrequency(): void
    {
        $ranked = (new TopicTerms())->rank(
            'Widget fitting. A widget guide. Widget maintenance and widget repair.'
        );

        $this->assertSame(4, $ranked['widget']);
        $this->assertSame(1, $ranked['fitting']);
        $this->assertSame(1, $ranked['guide']);
        $this->assertSame('widget', \array_key_first($ranked));
    }

    public function testItDropsShortWordsAndStopwords(): void
    {
        $ranked = (new TopicTerms())->rank(
            'The widget is with a very good guide to it and that thing'
        );

        // Below the length floor.
        $this->assertArrayNotHasKey('the', $ranked);
        $this->assertArrayNotHasKey('is', $ranked);
        $this->assertArrayNotHasKey('it', $ranked);

        // Long enough, but carries no topic.
        $this->assertArrayNotHasKey('with', $ranked);
        $this->assertArrayNotHasKey('very', $ranked);
        $this->assertArrayNotHasKey('that', $ranked);

        $this->assertArrayHasKey('widget', $ranked);
        $this->assertArrayHasKey('guide', $ranked);
        $this->assertArrayHasKey('thing', $ranked);
    }

    public function testItDropsBareNumbers(): void
    {
        $ranked = (new TopicTerms())->rank('2024 2024 2024 widget');

        $this->assertArrayNotHasKey('2024', $ranked);
        $this->assertSame(array( 'widget' => 1 ), $ranked);
    }

    public function testEqualFrequencyKeepsFirstSeenOrder(): void
    {
        $first  = (new TopicTerms())->top('alpha bravo charlie', 3);
        $second = (new TopicTerms())->top('alpha bravo charlie', 3);

        $this->assertSame(array( 'alpha', 'bravo', 'charlie' ), $first);
        $this->assertSame($first, $second, 'The same text produced a different order.');
    }

    public function testItTruncatesToTheLimit(): void
    {
        $terms = (new TopicTerms())->top('alpha bravo charlie delta', 2);

        $this->assertSame(array( 'alpha', 'bravo' ), $terms);
    }

    public function testTheDefaultLimitIsThree(): void
    {
        $terms = (new TopicTerms())->top('alpha bravo charlie delta');

        $this->assertCount(TopicTerms::DEFAULT_LIMIT, $terms);
    }

    public function testALimitBelowOneStillReturnsOneTerm(): void
    {
        // A caller asking for zero terms wants an answer, not an empty query.
        $terms = (new TopicTerms())->top('alpha bravo', 0);

        $this->assertSame(array( 'alpha' ), $terms);
    }

    public function testTextWithNoCandidateWordsYieldsNoTerms(): void
    {
        $this->assertSame(array(), (new TopicTerms())->top('a b c 1 2 3'));
        $this->assertSame(array(), (new TopicTerms())->top(''));
    }

    public function testItIsCaseInsensitive(): void
    {
        $ranked = (new TopicTerms())->rank('Widget widget WIDGET');

        $this->assertSame(array( 'widget' => 3 ), $ranked);
    }

    public function testPunctuationAndUnicodeAreSeparatorsNotWords(): void
    {
        $ranked = (new TopicTerms())->rank('café — café, naïve; naïve');

        $this->assertSame(2, $ranked['café']);
        $this->assertSame(2, $ranked['naïve']);
    }
}
