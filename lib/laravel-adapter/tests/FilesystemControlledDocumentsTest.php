<?php

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\ControlledDocumentInterface;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Qms\DocumentLifecycle;
use Nvoos\Laravel\Adapter\FilesystemControlledDocuments;
use Nvoos\Laravel\Adapter\FilesystemDocsCorpus;
use PHPUnit\Framework\TestCase;

/**
 * The controlled-document register (Proposal 164 §164.37).
 *
 * This is the class that carries the regulatory claims, so the tests are about the
 * three things a citation of 21 CFR Part 11 and ISO 9001 §7.5.2/§7.5.3 actually
 * promises:
 *
 *  - **the lifecycle is closed** — a transition the table does not list is
 *    refused, including the tempting `obsolete → released`;
 *  - **the trail is append-only** — entries accumulate and nothing rewrites them;
 *  - **a signature carries a meaning** — §11.50, not just a who and a when.
 *
 * The corpus is the real one, because the register composes it and a fake would
 * stop testing the composition that keeps the path guard single.
 *
 * @package Nvoos\Laravel\Tests
 * @since   2.5.0
 */
final class FilesystemControlledDocumentsTest extends TestCase {
	private string $root;

	private string $audit;

	private FilesystemDocsCorpus $corpus;

	protected function setUp(): void {
		parent::setUp();

		$this->root = \sys_get_temp_dir().'/nvoos-qms-'.\bin2hex( \random_bytes( 6 ) );
		$this->audit = $this->root.'-audit';
		\mkdir( $this->root, 0775, true );
		\mkdir( $this->audit, 0775, true );

		$this->corpus = new FilesystemDocsCorpus( $this->root );
	}

	protected function tearDown(): void {
		$this->remove( $this->root );
		$this->remove( $this->audit );

		parent::tearDown();
	}

	// ── The state machine ────────────────────────────────────────────────

	public function test_the_happy_path_runs_from_draft_to_released(): void {
		$register = $this->register();
		$register->create( 'sop-001.md', '# SOP 001', array( 'title' => 'SOP 001' ) );

		$register->transition( 'sop-001.md', DocumentLifecycle::REVIEW, array( 'user_id' => 1 ) );
		$register->transition( 'sop-001.md', DocumentLifecycle::APPROVED, array( 'user_id' => 2 ) );
		$result = $register->transition( 'sop-001.md', DocumentLifecycle::RELEASED, array( 'user_id' => 2 ) );

		$this->assertSame( DocumentLifecycle::RELEASED, $result['to'] );
		$this->assertTrue( DocumentLifecycle::isCurrent( $register->status( 'sop-001.md' )['status'] ) );
	}

	/**
	 * The transition that matters most: a withdrawn record must not come back.
	 * Reopening it would let a superseded document re-enter use, which is exactly
	 * what §7.5.3 forbids.
	 */
	public function test_an_obsolete_document_cannot_be_released_again(): void {
		$register = $this->released();

		$register->transition( 'sop-001.md', DocumentLifecycle::OBSOLETE );

		try {
			$register->transition( 'sop-001.md', DocumentLifecycle::RELEASED );
			$this->fail( 'obsolete -> released must be refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'cannot move to', $e->getMessage() );
		}

		$this->assertTrue( DocumentLifecycle::isTerminal( DocumentLifecycle::OBSOLETE ) );
	}

	public function test_a_draft_cannot_be_released_directly(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->register()->create( 'a.md', 'x' );
		$this->register()->transition( 'a.md', DocumentLifecycle::RELEASED );
	}

	public function test_an_unknown_state_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->register()->create( 'a.md', 'x' );
		$this->register()->transition( 'a.md', 'published' );
	}

	public function test_a_document_cannot_transition_to_its_own_state(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->register()->create( 'a.md', 'x' );
		$this->register()->transition( 'a.md', DocumentLifecycle::DRAFT );
	}

	public function test_rejection_returns_a_document_to_draft(): void {
		$register = $this->register();
		$register->create( 'a.md', 'x' );
		$register->transition( 'a.md', DocumentLifecycle::REVIEW );

		$result = $register->transition( 'a.md', DocumentLifecycle::DRAFT, array( 'reason' => 'Needs a second approver' ) );

		$this->assertSame( DocumentLifecycle::DRAFT, $result['to'] );

		$trail = $register->audit( 'a.md' );
		$rejection = \end( $trail['entries'] );

		$this->assertSame( 'Needs a second approver', $rejection['reason'] );
	}

	// ── The audit trail ─────────────────────────────────────────────────

	/**
	 * §11.10(e) requires a trail, and that changes do not obscure what was recorded
	 * before. Appending is what proves both.
	 */
	public function test_the_trail_only_grows(): void {
		$register = $this->released();

		$afterRelease = $register->audit( 'sop-001.md' );
		$register->transition( 'sop-001.md', DocumentLifecycle::SUPERSEDED );
		$afterSupersede = $register->audit( 'sop-001.md' );

		$this->assertGreaterThan( $afterRelease['total'], $afterSupersede['total'] );

		// Every earlier entry is byte-identical, in the same order.
		foreach ( $afterRelease['entries'] as $index => $entry ) {
			$this->assertSame( $entry, $afterSupersede['entries'][ $index ] );
		}
	}

	public function test_every_entry_carries_an_iso_timestamp_and_an_actor(): void {
		$register = $this->register();
		$register->create( 'a.md', 'x', array( 'owner' => 9 ) );

		$entry = $register->audit( 'a.md' )['entries'][0];

		$this->assertSame( 'created', $entry['event'] );
		$this->assertSame( 9, $entry['actor'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $entry['at'] );
	}

	public function test_the_trail_records_both_ends_of_a_transition(): void {
		$register = $this->register();
		$register->create( 'a.md', 'x' );
		$register->transition( 'a.md', DocumentLifecycle::REVIEW, array( 'user_id' => 4, 'reason' => 'Ready' ) );

		$entry = \end( $register->audit( 'a.md' )['entries'] );

		$this->assertSame( DocumentLifecycle::DRAFT, $entry['from'] );
		$this->assertSame( DocumentLifecycle::REVIEW, $entry['to'] );
		$this->assertSame( 4, $entry['actor'] );
		$this->assertSame( 'Ready', $entry['reason'] );
	}

	/**
	 * The contract has no update and no delete, and this asserts the store keeps to
	 * that: a second read returns the same bytes rather than a normalised copy.
	 */
	public function test_reading_the_trail_does_not_change_it(): void {
		$register = $this->released();

		$first = $register->audit( 'sop-001.md' );
		$second = $register->audit( 'sop-001.md' );

		$this->assertSame( $first, $second );
	}

	// ── Signatures ──────────────────────────────────────────────────────

	public function test_a_signature_records_its_meaning(): void {
		$register = $this->register();
		$register->create( 'a.md', 'x' );

		$result = $register->sign( 'a.md', array( 'meaning' => 'authored', 'user_id' => 3, 'comment' => 'Drafted' ) );

		$this->assertSame( 'authored', $result['meaning'] );
		$this->assertSame( 3, $result['signed_by'] );
		$this->assertSame( 'A', $result['revision'] );

		$entry = \end( $register->audit( 'a.md' )['entries'] );

		$this->assertSame( 'signed', $entry['event'] );
		$this->assertSame( 'authored', $entry['meaning'] );
		$this->assertSame( 'Drafted', $entry['comment'] );
	}

	public function test_an_unknown_meaning_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->register()->create( 'a.md', 'x' );
		$this->register()->sign( 'a.md', array( 'meaning' => 'rubber-stamped' ) );
	}

	public function test_a_signature_does_not_change_the_state(): void {
		$register = $this->register();
		$register->create( 'a.md', 'x' );
		$register->sign( 'a.md', array( 'meaning' => 'witnessed' ) );

		$this->assertSame( DocumentLifecycle::DRAFT, $register->status( 'a.md' )['status'] );
	}

	public function test_the_signature_count_is_reported(): void {
		$register = $this->register();
		$register->create( 'a.md', 'x' );
		$register->sign( 'a.md', array( 'meaning' => 'authored' ) );
		$register->sign( 'a.md', array( 'meaning' => 'reviewed' ) );

		$this->assertSame( 2, $register->status( 'a.md' )['signatures'] );
	}

	// ── Periodic review ─────────────────────────────────────────────────

	public function test_a_review_date_is_recorded_without_changing_state(): void {
		$register = $this->released();

		$result = $register->scheduleReview( 'sop-001.md', '2027-03-31' );

		$this->assertSame( '2027-03-31', $result['review_due'] );
		$this->assertSame( DocumentLifecycle::RELEASED, $result['status'] );
		$this->assertSame( '2027-03-31', $register->status( 'sop-001.md' )['review_due'] );
	}

	/**
	 * Release clears the due date, because the review just happened as part of
	 * approval and a stale date would misreport the next obligation.
	 */
	public function test_release_clears_a_stale_review_date(): void {
		$register = $this->register();
		$register->create( 'a.md', 'x', array( 'review_due' => '2026-01-01' ) );

		$this->assertSame( '2026-01-01', $register->status( 'a.md' )['review_due'] );

		$register->transition( 'a.md', DocumentLifecycle::REVIEW );
		$register->transition( 'a.md', DocumentLifecycle::APPROVED );
		$register->transition( 'a.md', DocumentLifecycle::RELEASED );

		$this->assertSame( '', $register->status( 'a.md' )['review_due'] );
	}

	public function test_an_unparseable_review_date_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->register()->create( 'a.md', 'x' );
		$this->register()->scheduleReview( 'a.md', 'next quarter' );
	}

	// ── Listing ─────────────────────────────────────────────────────────

	public function test_listing_filters_by_state(): void {
		$register = $this->register();
		$register->create( 'draft.md', 'x' );
		$register->create( 'live.md', 'x' );
		$register->transition( 'live.md', DocumentLifecycle::REVIEW );
		$register->transition( 'live.md', DocumentLifecycle::APPROVED );
		$register->transition( 'live.md', DocumentLifecycle::RELEASED );

		$this->assertSame( 1, $register->list( DocumentLifecycle::RELEASED )['total'] );
		$this->assertSame( 2, $register->list()['total'] );
	}

	/**
	 * A corpus file that was never created through the register is not a
	 * controlled record, and listing it would claim a review history that does not
	 * exist.
	 */
	public function test_an_uncontrolled_corpus_file_is_not_listed(): void {
		$this->corpus->write( 'notes.md', '# Just notes' );

		$this->assertSame( 0, $this->register()->list()['total'] );
	}

	// ── Refusals ────────────────────────────────────────────────────────

	public function test_an_unconfigured_register_is_inactive(): void {
		$register = new FilesystemControlledDocuments( $this->corpus, '' );

		$this->assertFalse( $register->isActive() );
		$this->expectException( \RuntimeException::class );
		$register->list();
	}

	public function test_a_missing_document_is_not_found(): void {
		$this->expectException( NotFoundException::class );

		$this->register()->status( 'absent.md' );
	}

	public function test_an_escaping_path_is_refused_by_the_corpus(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->register()->status( '../outside.md' );
	}

	public function test_the_contract_is_satisfied(): void {
		$this->assertInstanceOf( ControlledDocumentInterface::class, $this->register() );
	}

	public function test_the_body_survives_every_transition(): void {
		$register = $this->register();
		$register->create( 'a.md', "# Title\n\nProcedure text.\n" );
		$register->transition( 'a.md', DocumentLifecycle::REVIEW );
		$register->transition( 'a.md', DocumentLifecycle::APPROVED );

		$this->assertStringContainsString( 'Procedure text.', $this->corpus->read( 'a.md' )['content'] );
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	private function register(): FilesystemControlledDocuments {
		return new FilesystemControlledDocuments( $this->corpus, $this->audit );
	}

	/**
	 * A register holding one document at `released`.
	 */
	private function released(): FilesystemControlledDocuments {
		$register = $this->register();
		$register->create( 'sop-001.md', '# SOP 001', array( 'title' => 'SOP 001' ) );
		$register->transition( 'sop-001.md', DocumentLifecycle::REVIEW );
		$register->transition( 'sop-001.md', DocumentLifecycle::APPROVED );
		$register->transition( 'sop-001.md', DocumentLifecycle::RELEASED );

		return $register;
	}

	private function remove( string $path ): void {
		if ( ! \is_dir( $path ) ) {
			@\unlink( $path );
			return;
		}

		foreach ( \scandir( $path ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$child = $path.'/'.$entry;

			if ( \is_link( $child ) ) {
				@\unlink( $child );
				continue;
			}

			$this->remove( $child );
		}

		@\rmdir( $path );
	}
}
