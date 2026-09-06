<?php
/**
 * OKF core port tests (Wave E6, sub-cluster 4).
 *
 * Characterization suite for the ported `NvoosContentGraphAi\Engine\Okf`
 * core: the YAML frontmatter parser (scalars, nested mappings, lists of
 * scalars/objects, inline constructs, serialization round-trips), the
 * bundle reader (concept resolution with containment, browse, traversal,
 * trust tiers, staleness, search, broken-link advisory), and the writer
 * (concept creation/soft-delete, index regeneration with the
 * `okf_version` stamp, v0.2 bundle validation, log.md maintenance,
 * bundle-root initialization). Runs in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\Okf\OkfParser;
use NvoosContentGraphAi\Engine\Okf\OkfReader;
use NvoosContentGraphAi\Engine\Okf\OkfWriter;

/**
 * @group okf
 */
class Test_Okf_Core extends \WP_UnitTestCase {

	/**
	 * Temp bundle root.
	 *
	 * @var string
	 */
	private $bundle_root = '';

	public function setUp(): void {
		parent::setUp();

		$this->bundle_root = \sys_get_temp_dir() . '/nvoos-cg-okf-test-' . \wp_rand( 100000, 999999 );
		if ( ! \is_dir( $this->bundle_root ) ) {
			\mkdir( $this->bundle_root, 0777, true );
		}
	}

	public function tearDown(): void {
		if ( '' !== $this->bundle_root && \is_dir( $this->bundle_root ) ) {
			$this->delete_directory( $this->bundle_root );
		}

		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		$items = \scandir( $dir );
		if ( ! \is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( \is_dir( $path ) ) {
				$this->delete_directory( $path );
			} else {
				\unlink( $path );
			}
		}
		\rmdir( $dir );
	}

	/**
	 * Write a concept file into the temp bundle.
	 *
	 * @param string $concept_id Concept ID.
	 * @param string $yaml       Frontmatter YAML (between the --- delimiters).
	 * @param string $body       Markdown body.
	 * @return string Absolute file path.
	 */
	private function write_concept_file( string $concept_id, string $yaml, string $body = 'Body text.' ): string {
		$file = \trailingslashit( $this->bundle_root ) . $concept_id . '.md';
		$dir  = \dirname( $file );
		if ( ! \is_dir( $dir ) ) {
			\mkdir( $dir, 0777, true );
		}
		\file_put_contents( $file, "---\n{$yaml}\n---\n\n{$body}\n" );
		return $file;
	}

	// ─── Parser ───────────────────────────────────────────────────

	public function test_parser_returns_null_without_frontmatter(): void {
		$parser = new OkfParser();
		$this->assertNull( $parser->parse( '# Just a heading' ) );
	}

	public function test_parser_unclosed_frontmatter_returns_wp_error(): void {
		$parser = new OkfParser();
		$result = $parser->parse( "---\ntype: x\n" );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_unclosed_frontmatter', $result->get_error_code() );
	}

	public function test_parser_casts_scalars(): void {
		$parser = new OkfParser();
		$result = $parser->parse(
			"---\ntitle: \"Quoted Title\"\nrank: 42\nscore: 1.5\nactive: true\ndead: false\nnothing: null\nalso: ~\nplain: hello\n---\nBody\n"
		);

		$this->assertIsArray( $result );
		$fm = $result['frontmatter'];
		$this->assertSame( 'Quoted Title', $fm['title'] );
		$this->assertSame( 42, $fm['rank'] );
		$this->assertSame( 1.5, $fm['score'] );
		$this->assertTrue( $fm['active'] );
		$this->assertFalse( $fm['dead'] );
		$this->assertNull( $fm['nothing'] );
		$this->assertNull( $fm['also'] );
		$this->assertSame( 'hello', $fm['plain'] );
		$this->assertSame( 'Body', $result['body'] );
	}

	public function test_parser_nested_mapping_block(): void {
		$parser = new OkfParser();
		$result = $parser->parse(
			"---\ntype: concept\nmeta:\n  key1: value1\n  key2: value2\n---\nBody\n"
		);

		$this->assertSame(
			array(
				'key1' => 'value1',
				'key2' => 'value2',
			),
			$result['frontmatter']['meta']
		);
	}

	public function test_parser_list_of_scalars(): void {
		$parser = new OkfParser();
		$result = $parser->parse( "---\ntags:\n  - one\n  - two\n  - three\n---\nBody\n" );

		$this->assertSame( array( 'one', 'two', 'three' ), $result['frontmatter']['tags'] );
	}

	public function test_parser_list_of_objects_with_continuation(): void {
		$parser = new OkfParser();
		$result = $parser->parse(
			"---\nverified:\n  - by: human:reviewer\n    when: 2026-01-01\n  - by: agent:gemini\n    when: 2026-01-02\n---\nBody\n"
		);

		$this->assertSame(
			array(
				array(
					'by'   => 'human:reviewer',
					'when' => '2026-01-01',
				),
				array(
					'by'   => 'agent:gemini',
					'when' => '2026-01-02',
				),
			),
			$result['frontmatter']['verified']
		);
	}

	public function test_parser_inline_mapping_and_sequence(): void {
		$parser = new OkfParser();
		$result = $parser->parse(
			"---\npoint: { x: 1, y: 2, nested: { deep: yes } }\ncoords: [ 1, 2, 3 ]\n---\nBody\n"
		);

		$this->assertSame(
			array(
				'x'      => 1,
				'y'      => 2,
				'nested' => array( 'deep' => 'yes' ),
			),
			$result['frontmatter']['point']
		);
		$this->assertSame( array( 1, 2, 3 ), $result['frontmatter']['coords'] );
	}

	public function test_parser_serialize_round_trip(): void {
		$parser      = new OkfParser();
		$frontmatter = array(
			'type'       => 'concept',
			'title'      => 'Example',
			'tags'       => array( 'a', 'b' ),
			'status'     => 'stable',
			'verified'   => array(
				array(
					'by'   => 'human:reviewer',
					'when' => '2026-01-01',
				),
			),
			'stale_after' => '2026-12-31',
		);

		$yaml   = $parser->serialize( $frontmatter );
		$parsed = $parser->parse( $yaml . "\nBody\n" );

		$this->assertSame( $frontmatter, $parsed['frontmatter'] );
	}

	public function test_parser_serialize_quotes_special_characters(): void {
		$parser = new OkfParser();
		$yaml   = $parser->serialize( array( 'note' => 'contains: a colon' ) );

		$this->assertStringContainsString( 'note: "contains: a colon"', $yaml );
	}

	// ─── Reader ───────────────────────────────────────────────────

	public function test_reader_get_concept(): void {
		$this->write_concept_file( 'tables/orders', "type: table\ntitle: Orders" );

		$reader  = new OkfReader( $this->bundle_root );
		$concept = $reader->get_concept( 'tables/orders' );

		$this->assertSame( 'tables/orders', $concept['concept_id'] );
		$this->assertSame( 'table', $concept['frontmatter']['type'] );
		$this->assertSame( 'Orders', $concept['frontmatter']['title'] );
		$this->assertSame( 'Body text.', $concept['body'] );

		// Cached second read (same shape, no file re-read assertion needed).
		$this->assertSame( $concept, $reader->get_concept( 'tables/orders.md' ) );
	}

	public function test_reader_get_concept_missing_returns_wp_error(): void {
		$reader = new OkfReader( $this->bundle_root );
		$result = $reader->get_concept( 'missing' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_not_found', $result->get_error_code() );
	}

	public function test_reader_get_concept_without_frontmatter_returns_wp_error(): void {
		$file = \trailingslashit( $this->bundle_root ) . 'plain.md';
		\file_put_contents( $file, '# No frontmatter' );

		$reader = new OkfReader( $this->bundle_root );
		$result = $reader->get_concept( 'plain' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_no_frontmatter', $result->get_error_code() );
	}

	public function test_reader_path_traversal_is_rejected(): void {
		// Nest the bundle root inside a parent so a reachable file exists
		// outside it — the traversal branch fires only when the target
		// resolves (via realpath) to an existing file outside the root.
		$outer = \sys_get_temp_dir() . '/nvoos-cg-okf-outer-' . \wp_rand( 100000, 999999 );
		$inner = \trailingslashit( $outer ) . 'bundle';
		\mkdir( $inner, 0777, true );
		\file_put_contents( \trailingslashit( $outer ) . 'outside.md', "---\ntype: concept\n---\nBody\n" );

		$reader = new OkfReader( $inner );
		$result = $reader->get_concept( '../outside' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_path_traversal', $result->get_error_code() );

		$this->delete_directory( $outer );
	}

	public function test_reader_browse_uses_index_md(): void {
		\file_put_contents(
			\trailingslashit( $this->bundle_root ) . 'index.md',
			"# Root\n\n## Concepts\n* [Orders](tables/orders.md) - The orders table\n"
		);

		$reader  = new OkfReader( $this->bundle_root );
		$entries = $reader->browse();

		$this->assertSame(
			array(
				array(
					'title'       => 'Orders',
					'path'        => 'tables/orders.md',
					'description' => 'The orders table',
				),
			),
			$entries
		);
	}

	public function test_reader_browse_falls_back_to_directory_scan(): void {
		$this->write_concept_file( 'alpha', "type: concept\ntitle: Alpha Title" );
		\mkdir( \trailingslashit( $this->bundle_root ) . 'subdir', 0777, true );

		$reader  = new OkfReader( $this->bundle_root );
		$entries = $reader->browse();

		$titles = \wp_list_pluck( $entries, 'title' );
		\sort( $titles );
		$this->assertSame( array( 'Alpha Title', 'subdir' ), $titles );
	}

	public function test_reader_browse_missing_directory_returns_wp_error(): void {
		$reader = new OkfReader( $this->bundle_root );
		$result = $reader->browse( 'nope' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_not_found', $result->get_error_code() );
	}

	public function test_reader_traverse_follows_links_with_cycle_guard(): void {
		$this->write_concept_file( 'a', "type: concept\ntitle: A", 'See [B](b.md) and [A](a.md).' );
		$this->write_concept_file( 'b', "type: concept\ntitle: B", 'See [C](c.md).' );
		$this->write_concept_file( 'c', "type: concept\ntitle: C" );

		$reader   = new OkfReader( $this->bundle_root );
		$subgraph = $reader->traverse( 'a', 3 );

		$this->assertSame( 'a', $subgraph['concept_id'] );
		$this->assertCount( 1, $subgraph['links'] ); // Cycle back to 'a' is skipped.
		$this->assertSame( 'b', $subgraph['links'][0]['concept_id'] );
		$this->assertSame( 'c', $subgraph['links'][0]['links'][0]['concept_id'] );
	}

	public function test_reader_trust_tiers(): void {
		$reader = new OkfReader( $this->bundle_root );

		$this->assertSame( 'unverified', $reader->get_trust_tier( array() ) );
		$this->assertSame(
			'machine-confirmed',
			$reader->get_trust_tier( array( 'verified' => array( array( 'by' => 'agent:gemini' ) ) ) )
		);
		$this->assertSame(
			'human-reviewed',
			$reader->get_trust_tier(
				array(
					'verified' => array(
						array( 'by' => 'agent:gemini' ),
						array( 'by' => 'human:reviewer' ),
					),
				)
			)
		);
	}

	public function test_reader_is_stale(): void {
		$reader = new OkfReader( $this->bundle_root );

		$this->assertFalse( $reader->is_stale( array() ) );
		$this->assertFalse( $reader->is_stale( array( 'stale_after' => '2099-01-01' ) ) );
		$this->assertTrue( $reader->is_stale( array( 'stale_after' => '2020-01-01' ) ) );
	}

	public function test_reader_search_filters(): void {
		$this->write_concept_file( 'one', "type: article\ntitle: One\ntags:\n  - seo\nstatus: stable" );
		$this->write_concept_file( 'two', "type: video\ntitle: Two\ntags:\n  - ads\nstatus: deprecated" );
		$this->write_concept_file( 'three', "type: article\ntitle: Three\ntags:\n  - seo\nstatus: stable\nverified:\n  - by: human:reviewer\nstale_after: 2020-01-01" );

		$reader = new OkfReader( $this->bundle_root );

		$this->assertCount( 3, $reader->search() );
		$this->assertCount( 2, $reader->search( array( 'type' => 'article' ) ) );
		$this->assertCount( 2, $reader->search( array( 'tag' => 'seo' ) ) );
		$this->assertCount( 1, $reader->search( array( 'status' => 'deprecated' ) ) );
		$this->assertCount( 1, $reader->search( array( 'trust_tier' => 'human-reviewed' ) ) );
		$this->assertCount( 2, $reader->search( array( 'include_stale' => false ) ) );

		$summary = $reader->search( array( 'type' => 'article' ) )[0];
		$this->assertArrayHasKey( 'concept_id', $summary );
		$this->assertArrayHasKey( 'status', $summary );
		$this->assertArrayHasKey( 'trust_tier', $summary );
		$this->assertArrayHasKey( 'stale', $summary );
	}

	public function test_reader_get_types(): void {
		$this->write_concept_file( 'one', "type: article\ntitle: One" );
		$this->write_concept_file( 'two', "type: video\ntitle: Two" );

		$reader = new OkfReader( $this->bundle_root );
		$this->assertSame( array( 'article', 'video' ), $reader->get_types() );
	}

	public function test_reader_find_broken_links_advisory(): void {
		$this->write_concept_file( 'b', "type: concept\ntitle: B" );
		$this->write_concept_file( 'a', "type: concept\ntitle: A", 'See [B](b.md), [X](missing.md) and [ext](https://example.com/x.md).' );

		$reader = new OkfReader( $this->bundle_root );
		$broken = $reader->find_broken_links( 'a' );

		$this->assertCount( 1, $broken );
		$this->assertSame( 'a', $broken[0]['concept_id'] );
		$this->assertSame( 'missing.md', $broken[0]['target'] );
		$this->assertSame( 'missing', $broken[0]['resolved'] );
	}

	public function test_reader_clear_cache(): void {
		$this->write_concept_file( 'x', "type: concept\ntitle: X" );
		$reader = new OkfReader( $this->bundle_root );
		$reader->get_concept( 'x' );

		\file_put_contents( \trailingslashit( $this->bundle_root ) . 'x.md', "---\ntype: concept\ntitle: Changed\n---\nBody\n" );
		$this->assertSame( 'X', $reader->get_concept( 'x' )['frontmatter']['title'] );

		$reader->clear_cache();
		$this->assertSame( 'Changed', $reader->get_concept( 'x' )['frontmatter']['title'] );
	}

	// ─── Writer ───────────────────────────────────────────────────

	public function test_writer_write_concept_and_fires_action(): void {
		$events = array();
		\add_action(
			'wp_mcp_ai_okf_concept_saved',
			function ( $concept_id, $file_path ) use ( &$events ): void {
				$events[] = array( $concept_id, $file_path );
			},
			10,
			2
		);

		$writer = new OkfWriter( $this->bundle_root );
		$result = $writer->write_concept(
			'nested/deep',
			array(
				'type'  => 'concept',
				'title' => 'Deep',
			),
			'Body'
		);

		$this->assertTrue( $result );
		$this->assertFileExists( \trailingslashit( $this->bundle_root ) . 'nested/deep.md' );
		$this->assertCount( 1, $events );
		$this->assertSame( 'nested/deep', $events[0][0] );
	}

	public function test_writer_write_concept_missing_type_returns_wp_error(): void {
		$writer = new OkfWriter( $this->bundle_root );
		$result = $writer->write_concept( 'x', array( 'title' => 'No type' ), 'Body' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_missing_type', $result->get_error_code() );
	}

	public function test_writer_write_concept_traversal_is_a_preserved_base_quirk(): void {
		// The base writer's containment check is a bare prefix test against a
		// path it constructs itself, so a `../` concept ID passes the check
		// and the OS resolves the write into the PARENT directory (the base
		// behaves identically — the reader refuses such paths, the tools
		// sanitize them, and the writer is the lower layer). Preserved
		// byte-identically; the reader still blocks reading the result back.
		$outer = \sys_get_temp_dir() . '/nvoos-cg-okf-write-outer-' . \wp_rand( 100000, 999999 );
		$inner = \trailingslashit( $outer ) . 'bundle';
		\mkdir( $inner, 0777, true );

		$writer = new OkfWriter( $inner );
		$result = $writer->write_concept( '../escape', array( 'type' => 'concept' ), 'Body' );

		$this->assertTrue( $result );
		$this->assertFileExists( \trailingslashit( $outer ) . 'escape.md' );

		// The reader refuses to resolve the same path back.
		$reader = new OkfReader( $inner );
		$blocked = $reader->get_concept( '../escape' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'okf_path_traversal', $blocked->get_error_code() );

		$this->delete_directory( $outer );
	}

	public function test_writer_delete_concept_soft_deletes_and_fires_action(): void {
		$events = array();
		\add_action(
			'wp_mcp_ai_okf_concept_deleted',
			function ( $concept_id, $deleted_path ) use ( &$events ): void {
				$events[] = array( $concept_id, $deleted_path );
			},
			10,
			2
		);

		$this->write_concept_file( 'gone', 'type: concept\ntitle: Gone' );
		$writer = new OkfWriter( $this->bundle_root );

		$this->assertTrue( $writer->delete_concept( 'gone' ) );
		$this->assertFileDoesNotExist( \trailingslashit( $this->bundle_root ) . 'gone.md' );
		$this->assertCount( 1, $events );
		$this->assertSame( 'gone', $events[0][0] );
		$this->assertStringContainsString( '.deleted.', $events[0][1] );
	}

	public function test_writer_delete_concept_missing_returns_wp_error(): void {
		$writer = new OkfWriter( $this->bundle_root );
		$result = $writer->delete_concept( 'ghost' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_not_found', $result->get_error_code() );
	}

	public function test_writer_regenerate_index_stamps_okf_version_at_root(): void {
		$this->write_concept_file( 'a', "type: concept\ntitle: A Title" );
		$writer = new OkfWriter( $this->bundle_root );

		$this->assertTrue( $writer->regenerate_index() );

		$index = \file_get_contents( \trailingslashit( $this->bundle_root ) . 'index.md' );
		$this->assertStringContainsString( 'okf_version: "0.2"', $index );
		$this->assertStringContainsString( '* [A Title](a.md)', $index );
	}

	public function test_writer_regenerate_index_missing_dir_returns_wp_error(): void {
		$writer = new OkfWriter( $this->bundle_root );
		$result = $writer->regenerate_index( 'nope' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_not_found', $result->get_error_code() );
	}

	public function test_writer_validate_bundle_conformant(): void {
		$this->write_concept_file( 'a', "type: concept\ntitle: A\nstatus: stable\nstale_after: 2099-01-01" );

		$writer     = new OkfWriter( $this->bundle_root );
		$validation = $writer->validate_bundle();

		$this->assertTrue( $validation['conformant'] );
		$this->assertSame( array(), $validation['issues'] );
		$this->assertSame( 1, $validation['concept_count'] );
		$this->assertSame( 0, $validation['stale_count'] );
		$this->assertSame( 0, $validation['deprecated_count'] );
	}

	public function test_writer_validate_bundle_reports_issues(): void {
		$this->write_concept_file( 'no-type', 'title: Missing type' );
		$this->write_concept_file( 'bad-status', "type: concept\ntitle: Bad\nstatus: weird" );
		$this->write_concept_file( 'bad-date', "type: concept\ntitle: Bad date\nstale_after: not-a-date" );
		$this->write_concept_file( 'old', "type: concept\ntitle: Old\nstale_after: 2020-01-01" );
		$this->write_concept_file( 'deprecated', "type: concept\ntitle: Dep\nstatus: deprecated" );

		$writer     = new OkfWriter( $this->bundle_root );
		$validation = $writer->validate_bundle();

		$this->assertFalse( $validation['conformant'] );
		$this->assertSame( 5, $validation['concept_count'] );
		$this->assertSame( 1, $validation['stale_count'] );
		$this->assertSame( 1, $validation['deprecated_count'] );
		$this->assertCount( 3, $validation['issues'] );
	}

	public function test_writer_append_log_creates_and_updates(): void {
		$writer = new OkfWriter( $this->bundle_root );

		$this->assertTrue( $writer->append_log( '', 'First entry.', 'Creation' ) );
		$log = \file_get_contents( \trailingslashit( $this->bundle_root ) . 'log.md' );
		$this->assertStringContainsString( '# Directory Update Log', $log );
		$this->assertStringContainsString( '**Creation**: First entry.', $log );

		$this->assertTrue( $writer->append_log( '', 'Second entry.', 'Update' ) );
		$log = \file_get_contents( \trailingslashit( $this->bundle_root ) . 'log.md' );
		$this->assertStringContainsString( '**Update**: Second entry.', $log );
		$this->assertSame( 1, \substr_count( $log, \gmdate( 'Y-m-d' ) ) );
	}

	public function test_writer_append_log_missing_dir_returns_wp_error(): void {
		$writer = new OkfWriter( $this->bundle_root );
		$result = $writer->append_log( 'nope', 'Entry.' );
		$this->assertWPError( $result );
		$this->assertSame( 'okf_not_found', $result->get_error_code() );
	}

	public function test_writer_ensure_bundle_root_creates_and_fires_action(): void {
		$events = array();
		\add_action(
			'wp_mcp_ai_okf_bundle_initialized',
			function ( $bundle_path, $concept_count ) use ( &$events ): void {
				$events[] = array( $bundle_path, $concept_count );
			},
			10,
			2
		);

		$missing = \trailingslashit( $this->bundle_root ) . 'new-bundle';
		$writer  = new OkfWriter( $missing );

		$this->assertTrue( $writer->ensure_bundle_root() );
		$this->assertDirectoryExists( $missing );
		$this->assertCount( 1, $events );
		$this->assertSame( $missing, $events[0][0] );
		$this->assertSame( 0, $events[0][1] );

		// Existing root: no second event.
		$this->assertTrue( $writer->ensure_bundle_root() );
		$this->assertCount( 1, $events );
	}
}
