<?php

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\DocsCorpusInterface;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Laravel\Adapter\FilesystemDocsCorpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The docs corpus and its path guard (Proposal 164 §164.34).
 *
 * The tools are thin; this class is where the risk is. Every method that takes a
 * path can be handed one that points outside the corpus, and a single missed
 * check turns `docs_hub_read_doc` into an arbitrary-file reader. The guards are
 * ported from `WP_MCP_AI_Docs_Hub_Helper`, and these tests exist to make sure the
 * port kept both layers:
 *
 *  - **Segment validation** rejects `..`, absolute prefixes and drive letters
 *    before anything touches the filesystem.
 *  - **Realpath containment** catches what survives validation, which in practice
 *    means a symlink inside the corpus that points outside it.
 *
 * A refusal is `InvalidArgumentException`; a valid path with nothing behind it is
 * {@see NotFoundException}. The two are asserted separately because collapsing
 * them would let a caller stop distinguishing "bad request" from "gone".
 *
 * @package Nvoos\Laravel\Tests
 * @since   2.5.0
 */
final class FilesystemDocsCorpusTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();

		$this->root = \sys_get_temp_dir().'/nvoos-docs-'.\bin2hex( \random_bytes( 6 ) );
		\mkdir( $this->root, 0775, true );
	}

	protected function tearDown(): void {
		$this->remove( $this->root );

		parent::tearDown();
	}

	// ── Activation ──────────────────────────────────────────────────────

	public function test_an_unconfigured_root_is_inactive(): void {
		$corpus = new FilesystemDocsCorpus( '' );

		$this->assertFalse( $corpus->isActive() );
		$this->expectException( \RuntimeException::class );
		$corpus->list();
	}

	public function test_a_configured_root_is_active(): void {
		$this->assertTrue( $this->corpus()->isActive() );
	}

	public function test_it_reports_its_limits(): void {
		$corpus = new FilesystemDocsCorpus( $this->root, 1024, array( 'md' ) );

		$this->assertSame( 1024, $corpus->maxBytes() );
		$this->assertSame( array( 'md' ), $corpus->allowedExtensions() );
	}

	// ── Traversal: segment validation ───────────────────────────────────

	#[DataProvider('escapingPaths')]
	public function test_a_path_that_escapes_is_refused( string $path ): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->corpus()->read( $path );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function escapingPaths(): array {
		return array(
			'parent segment' => array( '../secret.md' ),
			'nested parent' => array( 'guides/../../secret.md' ),
			'windows separator' => array( '..\\secret.md' ),
			'windows nested' => array( 'guides\\..\\..\\secret.md' ),
			'absolute unix' => array( '/etc/passwd' ),
			'absolute windows drive' => array( 'C:/Windows/win.ini' ),
			'parent-first' => array( './notes.md' ),
			'dot segment' => array( 'guides/./notes.md' ),
			'dotfile' => array( '.env' ),
			'empty segment' => array( 'guides//notes.md' ),
			'served extension missing' => array( 'guides/notes.php' ),
			'no extension' => array( 'guides/notes' ),
			'overlong' => array( \str_repeat( 'a', 250 ).'.md' ),
		);
	}

	public function test_a_write_cannot_escape_either(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->corpus()->write( '../planted.md', 'body' );
	}

	public function test_a_delete_cannot_escape_either(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->corpus()->delete( '../victim.md' );
	}

	public function test_a_list_prefix_cannot_escape(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->corpus()->list( '../' );
	}

	// ── Traversal: realpath containment ────────────────────────────────

	/**
	 * Segment validation cannot see a symlink, because the link's own name is
	 * legal. This is the second layer: the resolved target must stay inside the
	 * root, and a link out of it does not resolve.
	 *
	 * It is reported as not-found rather than refused, so the corpus cannot be
	 * used to test whether an arbitrary file exists outside it.
	 */
	public function test_a_symlink_out_of_the_root_does_not_resolve(): void {
		$outside = \sys_get_temp_dir().'/nvoos-outside-'.\bin2hex( \random_bytes( 6 ) ).'.md';

		if ( ! @\symlink( $outside, $this->root.'/escape.md' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable on this filesystem.' );
		}

		\file_put_contents( $outside, 'secret' );

		try {
			$this->expectException( NotFoundException::class );
			$this->corpus()->read( 'escape.md' );
		} finally {
			@\unlink( $this->root.'/escape.md' );
			@\unlink( $outside );
		}
	}

	public function test_a_symlinked_directory_is_not_walked(): void {
		$outside = \sys_get_temp_dir().'/nvoos-outdir-'.\bin2hex( \random_bytes( 6 ) );

		\mkdir( $outside, 0775, true );
		\file_put_contents( $outside.'/hidden.md', '# Hidden' );

		if ( ! @\symlink( $outside, $this->root.'/linked' ) ) {
			$this->remove( $outside );
			$this->markTestSkipped( 'Symlinks are unavailable on this filesystem.' );
		}

		try {
			$listed = $this->corpus()->list();

			$this->assertSame( 0, $listed['total'], 'A symlinked directory must not contribute documents.' );
		} finally {
			@\unlink( $this->root.'/linked' );
			$this->remove( $outside );
		}
	}

	// ── Writing and reading ────────────────────────────────────────────

	public function test_it_creates_nested_directories_and_reads_back(): void {
		$corpus = $this->corpus();

		$written = $corpus->write( 'guides/install/setup.md', "Body text.\n" );

		$this->assertTrue( $written['created'] );
		$this->assertSame( 'guides/install/setup.md', $written['path'] );

		$read = $corpus->read( 'guides/install/setup.md' );

		$this->assertSame( "Body text.\n", $read['content'] );
		$this->assertSame( 'setup', $read['title'] );
	}

	public function test_a_second_write_reports_that_it_replaced(): void {
		$corpus = $this->corpus();

		$corpus->write( 'notes.md', 'one' );
		$second = $corpus->write( 'notes.md', 'two' );

		$this->assertFalse( $second['created'] );
		$this->assertSame( 'two', $corpus->read( 'notes.md' )['content'] );
	}

	public function test_frontmatter_is_prepended_when_meta_is_given(): void {
		$corpus = $this->corpus();

		$corpus->write( 'notes.md', "Body.\n", array( 'title' => 'Getting Started', 'slug' => 'start', 'order' => 3 ) );

		$content = $corpus->read( 'notes.md' )['content'];

		$this->assertStringStartsWith( "---\n", $content );
		$this->assertStringContainsString( 'title: Getting Started', $content );
		$this->assertStringContainsString( 'slug: start', $content );
		$this->assertStringContainsString( 'order: 3', $content );
		$this->assertStringContainsString( 'Body.', $content );
	}

	public function test_no_frontmatter_is_written_without_meta(): void {
		$corpus = $this->corpus();

		$corpus->write( 'notes.md', '# Heading'."\n" );

		$this->assertSame( "# Heading\n", $corpus->read( 'notes.md' )['content'] );
	}

	public function test_the_frontmatter_title_wins_over_a_heading(): void {
		$corpus = $this->corpus();

		$corpus->write( 'notes.md', "# Heading\n", array( 'title' => 'Frontmatter Wins' ) );

		$this->assertSame( 'Frontmatter Wins', $corpus->read( 'notes.md' )['title'] );
	}

	public function test_a_heading_is_the_title_when_there_is_no_frontmatter(): void {
		$corpus = $this->corpus();

		$corpus->write( 'notes.md', "# From Heading\n" );

		$this->assertSame( 'From Heading', $corpus->read( 'notes.md' )['title'] );
	}

	public function test_a_body_over_the_cap_is_refused_before_it_is_written(): void {
		$corpus = new FilesystemDocsCorpus( $this->root, 64 );

		try {
			$corpus->write( 'big.md', \str_repeat( 'x', 128 ) );
			$this->fail( 'A body over the cap must be refused.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( '64-byte', $e->getMessage() );
		}

		$this->assertFileDoesNotExist( $this->root.'/big.md' );
	}

	public function test_reading_an_oversized_document_is_refused(): void {
		\file_put_contents( $this->root.'/huge.md', \str_repeat( 'x', 2048 ) );

		$corpus = new FilesystemDocsCorpus( $this->root, 512 );

		$this->expectException( \InvalidArgumentException::class );
		$corpus->read( 'huge.md' );
	}

	public function test_a_missing_document_is_not_found_rather_than_a_refusal(): void {
		$this->expectException( NotFoundException::class );

		$this->corpus()->read( 'guides/absent.md' );
	}

	// ── Listing ────────────────────────────────────────────────────────

	public function test_it_lists_recursively_in_path_order(): void {
		$corpus = $this->corpus();

		$corpus->write( 'b.md', 'b' );
		$corpus->write( 'a/z.md', 'z' );
		$corpus->write( 'a/a.md', 'a' );

		$listed = $corpus->list();

		$this->assertSame( 3, $listed['total'] );
		$this->assertSame(
			array( 'a/a.md', 'a/z.md', 'b.md' ),
			\array_column( $listed['documents'], 'path' ),
		);
	}

	public function test_a_limit_truncates_the_page_but_not_the_total(): void {
		$corpus = $this->corpus();

		foreach ( range( 1, 5 ) as $n ) {
			$corpus->write( "doc-{$n}.md", "body {$n}" );
		}

		$listed = $corpus->list( '', 2 );

		$this->assertCount( 2, $listed['documents'] );
		$this->assertSame( 5, $listed['total'] );
	}

	public function test_a_subdirectory_prefix_narrows_the_listing(): void {
		$corpus = $this->corpus();

		$corpus->write( 'guides/one.md', 'x' );
		$corpus->write( 'reference/two.md', 'y' );

		$listed = $corpus->list( 'guides' );

		$this->assertSame( 1, $listed['total'] );
		$this->assertSame( 'guides/one.md', $listed['documents'][0]['path'] );
	}

	public function test_only_served_extensions_are_listed(): void {
		$corpus = $this->corpus();

		$corpus->write( 'keep.md', 'x' );
		\file_put_contents( $this->root.'/ignore.php', '<?php' );
		\file_put_contents( $this->root.'/ignore.json', '{}' );

		$this->assertSame( 1, $corpus->list()['total'] );
	}

	public function test_a_dot_directory_is_not_listed(): void {
		$corpus = $this->corpus();

		$corpus->write( 'keep.md', 'x' );
		\mkdir( $this->root.'/.git', 0775, true );
		\file_put_contents( $this->root.'/.git/config.md', 'nope' );

		$this->assertSame( 1, $corpus->list()['total'] );
	}

	// ── Deleting ───────────────────────────────────────────────────────

	public function test_it_deletes_a_document(): void {
		$corpus = $this->corpus();

		$corpus->write( 'gone.md', 'x' );

		$this->assertTrue( $corpus->delete( 'gone.md' ) );
		$this->assertFileDoesNotExist( $this->root.'/gone.md' );
	}

	public function test_deleting_a_missing_document_is_not_found(): void {
		$this->expectException( NotFoundException::class );

		$this->corpus()->delete( 'absent.md' );
	}

	// ── Scanning ───────────────────────────────────────────────────────

	public function test_a_scan_counts_the_documents(): void {
		$corpus = $this->corpus();

		$corpus->write( 'one.md', 'x' );
		$corpus->write( 'two.md', 'y' );

		$scan = $corpus->rebuild();

		$this->assertSame( 2, $scan['indexed'] );
		$this->assertSame( array(), $scan['duplicates'] );
		$this->assertSame( array(), $scan['unreadable'] );
	}

	public function test_a_scan_reports_colliding_titles(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x', array( 'title' => 'Same Title' ) );
		$corpus->write( 'b.md', 'y', array( 'title' => 'Same Title' ) );

		$scan = $corpus->rebuild();

		$this->assertSame( array( 'same title' ), $scan['duplicates'] );
	}

	public function test_the_contract_is_satisfied(): void {
		$this->assertInstanceOf( DocsCorpusInterface::class, $this->corpus() );
	}

	// ── Archiving ───────────────────────────────────────────────────────

	/**
	 * ISO 9001 §7.5.3: obsolete information is marked and prevented from
	 * unintended use, but retained. The body must survive untouched.
	 */
	public function test_archiving_marks_the_document_and_keeps_the_body(): void {
		$corpus = $this->corpus();

		$corpus->write( 'guides/old.md', "# Old Guide\n\nBody text.\n" );

		$result = $corpus->archive( 'guides/old.md', array( 'reason' => 'Superseded by v2', 'user_id' => 7 ) );

		$this->assertFalse( $result['already_archived'] );
		$this->assertSame( 'archived', $result['status'] );
		$this->assertSame( 7, $result['archived_by'] );

		$content = $corpus->read( 'guides/old.md' )['content'];

		$this->assertStringContainsString( 'status: archived', $content );
		$this->assertStringContainsString( 'archived_by: 7', $content );
		$this->assertStringContainsString( 'archive_reason: Superseded by v2', $content );
		$this->assertStringContainsString( "Body text.\n", $content, 'The body must survive an archive.' );
	}

	public function test_archiving_records_an_iso_timestamp(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x' );
		$result = $corpus->archive( 'a.md' );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $result['archived_at'] );
	}

	/**
	 * Re-stamping would destroy the only thing the record exists to prove.
	 */
	public function test_archiving_twice_changes_nothing(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'body' );
		$first = $corpus->archive( 'a.md', array( 'reason' => 'first', 'user_id' => 3 ) );

		\usleep( 1100000 ); // Ensure a naive re-stamp would produce a different second.

		$second = $corpus->archive( 'a.md', array( 'reason' => 'second', 'user_id' => 9 ) );

		$this->assertTrue( $second['already_archived'] );
		$this->assertSame( $first['archived_at'], $second['archived_at'], 'The withdrawal time must not move.' );
		$this->assertSame( 3, $second['archived_by'], 'The original signer must not be replaced.' );
		$this->assertSame( 'first', $second['archive_reason'] );
	}

	public function test_archiving_preserves_existing_frontmatter(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'body', array( 'title' => 'Keep Me', 'slug' => 'keep', 'order' => 5 ) );
		$corpus->archive( 'a.md' );

		$content = $corpus->read( 'a.md' )['content'];

		$this->assertStringContainsString( 'title: Keep Me', $content );
		$this->assertStringContainsString( 'slug: keep', $content );
		$this->assertStringContainsString( 'order: 5', $content );
		$this->assertStringContainsString( 'status: archived', $content );
	}

	public function test_archiving_a_missing_document_is_not_found(): void {
		$this->expectException( NotFoundException::class );

		$this->corpus()->archive( 'absent.md' );
	}

	public function test_archiving_an_escaping_path_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->corpus()->archive( '../victim.md' );
	}

	public function test_archiving_a_document_without_frontmatter_adds_one(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', "# Heading\n\nBody.\n" );
		$corpus->archive( 'a.md' );

		$read = $corpus->read( 'a.md' );

		$this->assertStringStartsWith( "---\n", $read['content'] );
		$this->assertSame( 'Heading', $read['title'], 'The heading must still supply the title.' );
		$this->assertStringContainsString( 'Body.', $read['content'] );
	}

	public function test_an_archived_document_is_still_listed_and_readable(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x' );
		$corpus->archive( 'a.md' );

		$this->assertSame( 1, $corpus->list()['total'], 'Retention means it stays in the corpus.' );
		$this->assertStringContainsString( 'x', $corpus->read( 'a.md' )['content'], 'The body is still readable after withdrawal.' );
		$this->assertStringContainsString( 'status: archived', $corpus->read( 'a.md' )['content'] );
	}

	// ── Retention ───────────────────────────────────────────────────────

	/**
	 * ISO 15489 counts a period from a trigger event, so a stated expiry and a
	 * derived one are both understood — and the result says which, because an
	 * auditor has to be able to reconstruct a derived date.
	 */
	public function test_a_stated_expiry_is_reported_as_stated(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x', array( 'title' => 'A' ) );
		$corpus->setFields( 'a.md', array( 'expires_at' => '2027-01-01' ) );

		$result = $corpus->expiring( '2027-06-01' );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'stated', $result['documents'][0]['source'] );
		$this->assertSame( '2027-01-01', $result['documents'][0]['expires_at'] );
		$this->assertSame( 'review', $result['documents'][0]['disposition'], 'A schedule that says nothing must not imply destruction.' );
	}

	public function test_a_document_still_in_date_is_not_reported(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x' );
		$corpus->setFields( 'a.md', array( 'expires_at' => '2030-01-01' ) );

		$this->assertSame( 0, $corpus->expiring( '2027-06-01' )['total'] );
	}

	/**
	 * The ISO 15489 model: a period measured from the event that stopped the record
	 * being current, not from the day it was written.
	 */
	public function test_a_period_counts_from_the_lifecycle_trigger(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x' );
		$corpus->setFields( 'a.md', array(
			'qms_withdrawn_at' => '2026-01-15',
			'retention_months' => '12',
		) );

		$result = $corpus->expiring( '2027-06-01' );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'derived', $result['documents'][0]['source'] );
		$this->assertSame( 'withdrawn', $result['documents'][0]['trigger'] );
		$this->assertSame( '2027-01-15', $result['documents'][0]['expires_at'] );
	}

	public function test_a_period_that_has_not_run_out_is_not_reported(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x' );
		$corpus->setFields( 'a.md', array(
			'qms_withdrawn_at' => '2026-01-15',
			'retention_months' => '36',
		) );

		$this->assertSame( 0, $corpus->expiring( '2027-06-01' )['total'] );
	}

	/**
	 * The latest stamp wins: retention runs from the most recent event.
	 */
	public function test_the_latest_lifecycle_stamp_is_the_trigger(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x' );
		$corpus->setFields( 'a.md', array(
			'qms_released_at' => '2025-01-01',
			'qms_withdrawn_at' => '2026-03-01',
			'retention_months' => '6',
		) );

		$result = $corpus->expiring( '2027-01-01' );

		$this->assertSame( 'withdrawn', $result['documents'][0]['trigger'] );
		$this->assertSame( '2026-09-01', $result['documents'][0]['expires_at'] );
	}

	public function test_a_schedule_disposition_is_carried_through(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x' );
		$corpus->setFields( 'a.md', array(
			'expires_at' => '2020-01-01',
			'retention_disposition' => 'destroy',
		) );

		$this->assertSame( 'destroy', $corpus->expiring( '2027-01-01' )['documents'][0]['disposition'] );
	}

	/**
	 * "We have no rule for this" and "this is still in date" are different answers.
	 */
	public function test_a_document_with_no_retention_rule_is_excluded(): void {
		$corpus = $this->corpus();

		$corpus->write( 'a.md', 'x' );

		$this->assertSame( 0, $corpus->expiring( '2099-01-01' )['total'] );
	}

	public function test_an_unparseable_as_of_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->corpus()->expiring( 'next year' );
	}

	public function test_results_are_ordered_by_expiry(): void {
		$corpus = $this->corpus();

		$corpus->write( 'late.md', 'x' );
		$corpus->setFields( 'late.md', array( 'expires_at' => '2024-01-01' ) );

		$corpus->write( 'early.md', 'x' );
		$corpus->setFields( 'early.md', array( 'expires_at' => '2020-01-01' ) );

		$this->assertSame(
			array( 'early.md', 'late.md' ),
			\array_column( $corpus->expiring( '2027-01-01' )['documents'], 'path' ),
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────

	private function corpus(): FilesystemDocsCorpus {
		return new FilesystemDocsCorpus( $this->root );
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
