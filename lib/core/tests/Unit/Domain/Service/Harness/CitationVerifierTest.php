<?php

/**
 * Tests for the citation verifier.
 *
 * The load-bearing properties: only checkable sentences are judged, the
 * similarity measure is the documented lower bound (identical text scores 0.5,
 * not 1.0, because the union double-counts the intersection), entities lift a
 * claim, the threshold is honoured, and at most 20 claims are verified.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\CitationVerifier;
use PHPUnit\Framework\TestCase;

final class CitationVerifierTest extends TestCase
{
	private const CLAIM = 'Revenue rose twelve percent in the third quarter of 2025.';

	public function test_a_supported_claim_is_verified(): void
	{
		$result = CitationVerifier::verify(self::CLAIM, array( self::CLAIM ));

		$this->assertSame(1, $result['total']);
		$this->assertSame(1, $result['verified']);
		$this->assertSame(0, $result['unverified']);
		$this->assertTrue($result['claims'][0]['verified']);
	}

	public function test_an_unsupported_claim_is_unverified(): void
	{
		$result = CitationVerifier::verify(
			'Margins collapsed 18% because the supplier went bankrupt without notice.',
			array( self::CLAIM )
		);

		$this->assertSame(0, $result['verified']);
		$this->assertSame(1, $result['unverified']);
		$this->assertFalse($result['claims'][0]['verified']);
	}

	public function test_only_checkable_sentences_are_judged(): void
	{
		$result = CitationVerifier::verify(
			'Great. Yes. Thanks for the help. Sorry about that.',
			array( self::CLAIM )
		);

		$this->assertSame(0, $result['total'], 'Short sentences and assertions-free prose are skipped.');
	}

	public function test_a_sentence_without_a_number_or_copula_is_skipped(): void
	{
		// Long enough to be a claim by length, but it asserts nothing checkable.
		$result = CitationVerifier::verify(
			'Anyway moving right along here with the rest of the agenda now.',
			array( self::CLAIM )
		);

		$this->assertSame(0, $result['total']);
	}

	public function test_claims_are_capped(): void
	{
		$content = implode(' ', array_fill(0, 25, 'Revenue was 120 units in 2025.'));

		$claims = CitationVerifier::claims($content);

		$this->assertCount(CitationVerifier::MAX_CLAIMS, $claims);
	}

	public function test_short_sentences_are_not_claims(): void
	{
		$this->assertSame(array(), CitationVerifier::claims('It is 5%.'));
	}

	public function test_identical_text_scores_a_half_not_one(): void
	{
		// The union concatenates two unique sets, so the shared words are
		// counted twice and the ratio is a strict lower bound on the true
		// Jaccard overlap. Pinned so nobody "fixes" it into over-trusting.
		$score = CitationVerifier::similarity('Revenue rose 12', 'Revenue rose 12');

		$this->assertSame(0.5, $score);
	}

	public function test_an_entity_match_lifts_the_score(): void
	{
		// 2 shared tokens out of a 6-token concatenated union = 0.3333, plus
		// the single entity "Acme Corp" (1 of 1) × 0.2.
		$score = CitationVerifier::similarity('Acme Corp shipped goods', 'Acme Corp');

		$this->assertEqualsWithDelta(0.5333, $score, 0.001);
	}

	public function test_stop_words_do_not_contribute(): void
	{
		$this->assertSame(
			0.0,
			CitationVerifier::similarity('the and of to', 'the and of to'),
			'Only stop words remain after tokenising, so there is nothing to compare.'
		);
	}

	public function test_the_threshold_is_honoured(): void
	{
		$claim = 'Revenue rose twelve percent in the third quarter of 2025.';
		$source = 'Revenue rose twelve percent in the third quarter of 2025, per the filing.';

		$this->assertSame(
			1,
			CitationVerifier::verify($claim, array( $source ), 0.9)['unverified'],
			'A demanding threshold rejects a merely similar source.'
		);
	}

	public function test_passage_shaped_sources_are_accepted(): void
	{
		// The retrieval harness returns passages, not strings. The WordPress
		// implementation assumes strings and would raise on an array; the core
		// reads `text` so the two clusters compose.
		$result = CitationVerifier::verify(self::CLAIM, array( array( 'text' => self::CLAIM ) ));

		$this->assertSame(1, $result['verified']);
	}

	public function test_non_text_sources_are_ignored(): void
	{
		$result = CitationVerifier::verify(
			self::CLAIM,
			array( 42, null, array( 'not_text' => 'x' ), new \stdClass() )
		);

		$this->assertSame(0, $result['verified']);
		$this->assertSame(1, $result['unverified']);
	}

	public function test_an_answer_with_no_claims_returns_zero_counts(): void
	{
		$result = CitationVerifier::verify('', array( self::CLAIM ));

		$this->assertSame(0, $result['total']);
		$this->assertSame(0, $result['verified']);
		$this->assertSame(0, $result['unverified']);
	}
}
