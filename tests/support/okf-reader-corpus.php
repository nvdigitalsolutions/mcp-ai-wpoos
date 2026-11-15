<?php
/**
 * OKF reader corpus — bundle trees plus the operations to run against them.
 *
 * Characterisation harness for `WP_MCP_AI_OKF_Reader` (Proposal 145, Phase 3).
 * The trees are authored by hand here; the expectations are generated from the
 * current WordPress implementation and then reviewed, exactly like the parser
 * corpus.
 *
 * A fixture is one bundle plus a list of cases:
 *
 *   { "tree": { "<relative path>": "<file content>" },
 *     "cases": [ { "name": …, "op": …, "args": {…}, "expected": … } ] }
 *
 * `expected` is either a recorded value or `{ "error": "<code>" }`.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.5.0
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Directory holding the committed reader fixtures.
 */
function wp_mcp_ai_okf_reader_corpus_dir(): string {
	return __DIR__ . '/../fixtures/okf/reader';
}

/**
 * Bundle trees and case inputs, keyed by fixture name.
 *
 * Dates used by staleness cases are deliberately extreme (2000 / 2999) so the
 * recorded expectation cannot rot as the clock moves.
 *
 * @return array<string, array{tree: array<string, string>, cases: array<int, array<string, mixed>>}>
 */
function wp_mcp_ai_okf_reader_corpus_bundles(): array {
	return array(
		'basic'    => array(
			'tree'  => array(
				'index.md'                 => "# Demo Bundle\n\n* [Tables](tables/index.md) - Table concepts\n* [Guides](guides/getting-started.md) - How to start\n",
				'tables/index.md'          => "* [Orders](orders.md) - Order records\n* [Customers](customers.md) - Customer records\n",
				'tables/orders.md'         => "---\ntype: Table\ntitle: Orders\ndescription: Order records\ntags:\n  - sales\n  - core\n---\n# Orders\n\nOrder rows.\n",
				'tables/customers.md'      => "---\ntype: Table\ntitle: Customers\ndescription: Customer records\ntags: [crm, sales]\n---\nSee [Orders](orders.md).\n",
				'guides/getting-started.md' => "---\ntype: Guide\ntitle: Getting Started\ndescription: First steps\n---\nStart with [Orders](/tables/orders.md).\n",
				'broken.md'                => "---\ntype: Broken\ntitle: Broken\n",
				'orphan.md'                => "Just a body, no frontmatter.\n",
				'log.md'                   => "---\ntype: Log\ntitle: Change Log\n---\nReserved filename.\n",
			),
			'cases' => array(
				array(
					'name' => 'get-concept',
					'op'   => 'get_concept',
					'args' => array( 'concept_id' => 'tables/orders' ),
				),
				array(
					'name' => 'get-concept-strips-md-suffix',
					'op'   => 'get_concept',
					'args' => array( 'concept_id' => 'tables/orders.md' ),
				),
				array(
					'name' => 'get-concept-nested',
					'op'   => 'get_concept',
					'args' => array( 'concept_id' => 'guides/getting-started' ),
				),
				array(
					'name' => 'get-concept-missing',
					'op'   => 'get_concept',
					'args' => array( 'concept_id' => 'does/not/exist' ),
				),
				array(
					'name' => 'get-concept-dotdot-outside-root',
					'op'   => 'get_concept',
					'args' => array( 'concept_id' => '../outside' ),
				),
				array(
					'name' => 'get-concept-unclosed-frontmatter',
					'op'   => 'get_concept',
					'args' => array( 'concept_id' => 'broken' ),
				),
				array(
					'name' => 'get-concept-no-frontmatter',
					'op'   => 'get_concept',
					'args' => array( 'concept_id' => 'orphan' ),
				),
				array(
					'name' => 'browse-root-uses-index',
					'op'   => 'browse',
					'args' => array( 'path' => '' ),
				),
				array(
					'name' => 'browse-subdirectory-uses-index',
					'op'   => 'browse',
					'args' => array( 'path' => 'tables' ),
				),
				array(
					'name' => 'browse-falls-back-to-scan',
					'op'   => 'browse',
					'args' => array( 'path' => 'guides' ),
				),
				array(
					'name' => 'browse-missing-directory',
					'op'   => 'browse',
					'args' => array( 'path' => 'nope' ),
				),
				array(
					'name' => 'traverse-depth-1',
					'op'   => 'traverse',
					'args' => array(
						'concept_id' => 'tables/customers',
						'max_depth'  => 1,
					),
				),
				array(
					'name' => 'traverse-depth-0-clamps-to-1',
					'op'   => 'traverse',
					'args' => array(
						'concept_id' => 'tables/customers',
						'max_depth'  => 0,
					),
				),
				array(
					'name' => 'get-types',
					'op'   => 'get_types',
					'args' => array(),
				),
				array(
					'name' => 'search-by-type',
					'op'   => 'search',
					'args' => array( 'criteria' => array( 'type' => 'Table' ) ),
				),
				array(
					'name' => 'search-tag-is-case-insensitive',
					'op'   => 'search',
					'args' => array( 'criteria' => array( 'tag' => 'CRM' ) ),
				),
				array(
					'name' => 'file-to-concept-id-relative',
					'op'   => 'file_to_concept_id',
					'args' => array( 'path' => 'tables/orders.md' ),
				),
				array(
					'name' => 'file-to-concept-id-root-index',
					'op'   => 'file_to_concept_id',
					'args' => array( 'path' => 'index.md' ),
				),
			),
		),
		'metadata' => array(
			'tree'  => array(
				'a-human.md'      => "---\ntype: Concept\ntitle: Human Reviewed\nstatus: stable\ntags: [alpha]\nverified:\n  - by: 'human:ada'\n    at: 2024-01-01\n---\nHuman checked.\n",
				'b-machine.md'    => "---\ntype: Concept\ntitle: Machine Confirmed\nstatus: draft\nverified:\n  - by: 'agent:gemini'\n---\nMachine checked.\n",
				'c-unverified.md' => "---\ntype: Concept\ntitle: Unverified Old\nstatus: deprecated\nstale_after: 2000-01-01\n---\nNo verification.\n",
				'd-fresh.md'      => "---\ntype: Concept\ntitle: Unverified Fresh\nstale_after: 2999-01-01\ntags: [alpha, beta]\n---\nNo verification, not stale.\n",
			),
			'cases' => array(
				array(
					'name' => 'search-by-type',
					'op'   => 'search',
					'args' => array( 'criteria' => array( 'type' => 'Concept' ) ),
				),
				array(
					'name' => 'search-by-status-draft',
					'op'   => 'search',
					'args' => array( 'criteria' => array( 'status' => 'draft' ) ),
				),
				array(
					'name' => 'search-by-trust-tier-human',
					'op'   => 'search',
					'args' => array( 'criteria' => array( 'trust_tier' => 'human-reviewed' ) ),
				),
				array(
					'name' => 'search-by-trust-tier-unverified',
					'op'   => 'search',
					'args' => array( 'criteria' => array( 'trust_tier' => 'unverified' ) ),
				),
				array(
					'name' => 'search-excludes-stale',
					'op'   => 'search',
					'args' => array( 'criteria' => array( 'include_stale' => false ) ),
				),
				array(
					'name' => 'search-by-tag-alpha',
					'op'   => 'search',
					'args' => array( 'criteria' => array( 'tag' => 'alpha' ) ),
				),
				array(
					'name' => 'get-types',
					'op'   => 'get_types',
					'args' => array(),
				),
				array(
					'name' => 'trust-tier-human',
					'op'   => 'get_trust_tier',
					'args' => array( 'frontmatter' => array( 'verified' => array( array( 'by' => 'human:ada' ) ) ) ),
				),
				array(
					'name' => 'trust-tier-machine',
					'op'   => 'get_trust_tier',
					'args' => array( 'frontmatter' => array( 'verified' => array( array( 'by' => 'agent:gemini' ) ) ) ),
				),
				array(
					'name' => 'trust-tier-absent',
					'op'   => 'get_trust_tier',
					'args' => array( 'frontmatter' => array( 'title' => 'Nothing verified' ) ),
				),
				array(
					'name' => 'is-stale-past',
					'op'   => 'is_stale',
					'args' => array( 'frontmatter' => array( 'stale_after' => '2000-01-01' ) ),
				),
				array(
					'name' => 'is-stale-future',
					'op'   => 'is_stale',
					'args' => array( 'frontmatter' => array( 'stale_after' => '2999-01-01' ) ),
				),
				array(
					'name' => 'is-stale-absent',
					'op'   => 'is_stale',
					'args' => array( 'frontmatter' => array( 'title' => 'No deadline' ) ),
				),
				array(
					'name' => 'is-stale-unparseable-date',
					'op'   => 'is_stale',
					'args' => array( 'frontmatter' => array( 'stale_after' => 'not-a-date' ) ),
				),
			),
		),
		'links'    => array(
			'tree'  => array(
				'hub.md'   => "---\ntype: Index\ntitle: Hub\n---\n[Alpha](alpha.md)\n[Beta](./beta.md)\n[Up](../top.md)\n[Ext](https://example.com/x.md)\n[Anchor](#section)\n[Missing](missing.md)\n",
				'alpha.md' => "---\ntype: Concept\ntitle: Alpha\n---\nBack to [Hub](hub.md).\n",
				'beta.md'  => "---\ntype: Concept\ntitle: Beta\n---\nNo outgoing links.\n",
				'top.md'   => "---\ntype: Concept\ntitle: Top\n---\nTop level.\n",
			),
			'cases' => array(
				array(
					'name' => 'broken-links-report-only-internal-misses',
					'op'   => 'find_broken_links',
					'args' => array( 'concept_id' => 'hub' ),
				),
				array(
					'name' => 'broken-links-empty-when-all-resolve',
					'op'   => 'find_broken_links',
					'args' => array( 'concept_id' => 'alpha' ),
				),
				array(
					'name' => 'broken-links-unknown-concept',
					'op'   => 'find_broken_links',
					'args' => array( 'concept_id' => 'ghost' ),
				),
				array(
					'name' => 'traverse-follows-relative-and-absolute',
					'op'   => 'traverse',
					'args' => array(
						'concept_id' => 'hub',
						'max_depth'  => 2,
					),
				),
			),
		),
	);
}

/**
 * Write every fixture tree to a fresh temporary directory.
 *
 * @param array<string, string> $tree Relative path => file content.
 * @return string Absolute path to the materialised bundle root.
 * @throws RuntimeException When a directory cannot be created.
 */
function wp_mcp_ai_okf_reader_materialise( array $tree ): string {
	$root = rtrim( sys_get_temp_dir(), '/\\' ) . '/okf-reader-' . bin2hex( random_bytes( 8 ) );

	if ( ! mkdir( $root, 0775, true ) && ! is_dir( $root ) ) {
		throw new RuntimeException( 'Cannot create the corpus bundle root.' );
	}

	foreach ( $tree as $relative => $content ) {
		$path = $root . '/' . $relative;
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message in a test harness; never rendered.
			throw new RuntimeException( 'Cannot create the corpus bundle directory: ' . $dir );
		}

		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer, runs offline.
	}

	return $root;
}

/**
 * Recursively remove a materialised bundle.
 *
 * @param string $dir Absolute directory path.
 * @return void
 */
function wp_mcp_ai_okf_reader_destroy( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = scandir( $dir );

	if ( false === $items ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = $dir . '/' . $item;

		if ( is_dir( $path ) ) {
			wp_mcp_ai_okf_reader_destroy( $path );
		} else {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		}
	}

	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
}

/**
 * Run one case against a reader.
 *
 * @param WP_MCP_AI_OKF_Reader $reader Reader bound to a materialised bundle.
 * @param array<string, mixed> $case   Case definition.
 * @return mixed Raw result, possibly a WP_Error.
 * @throws RuntimeException When the operation is unknown.
 */
function wp_mcp_ai_okf_reader_run_case( $reader, array $case ) {
	$args = isset( $case['args'] ) ? $case['args'] : array();

	switch ( $case['op'] ) {
		case 'get_concept':
			return $reader->get_concept( isset( $args['concept_id'] ) ? $args['concept_id'] : '' );

		case 'browse':
			return $reader->browse( isset( $args['path'] ) ? $args['path'] : '' );

		case 'traverse':
			return $reader->traverse(
				isset( $args['concept_id'] ) ? $args['concept_id'] : '',
				isset( $args['max_depth'] ) ? $args['max_depth'] : 2
			);

		case 'search':
			return $reader->search( isset( $args['criteria'] ) ? $args['criteria'] : array() );

		case 'get_types':
			return $reader->get_types();

		case 'find_broken_links':
			return $reader->find_broken_links( isset( $args['concept_id'] ) ? $args['concept_id'] : '' );

		case 'get_trust_tier':
			return $reader->get_trust_tier( isset( $args['frontmatter'] ) ? $args['frontmatter'] : array() );

		case 'is_stale':
			return $reader->is_stale( isset( $args['frontmatter'] ) ? $args['frontmatter'] : array() );

		case 'file_to_concept_id':
			return $reader->file_to_concept_id(
				$reader->get_bundle_root() . '/' . ( isset( $args['path'] ) ? $args['path'] : '' )
			);
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message in a test harness; never rendered.
	throw new RuntimeException( 'Unknown reader corpus operation: ' . (string) $case['op'] );
}

/**
 * Flatten a reader result into a stable, comparable shape.
 *
 * `search()` walks a RecursiveDirectoryIterator, so its result order is
 * filesystem-dependent. Sorting by concept ID here makes the fixture a
 * contract about content rather than about inode order.
 *
 * @param string $op     Operation name.
 * @param mixed  $result Raw result.
 * @return mixed Normalised result.
 */
function wp_mcp_ai_okf_reader_normalise( string $op, $result ) {
	if ( is_wp_error( $result ) ) {
		return array( 'error' => $result->get_error_code() );
	}

	if ( 'search' === $op && is_array( $result ) ) {
		usort(
			$result,
			static function ( $left, $right ) {
				return strcmp(
					(string) ( $left['concept_id'] ?? '' ),
					(string) ( $right['concept_id'] ?? '' )
				);
			}
		);
	}

	return $result;
}

/**
 * Load every committed fixture, keyed by bundle name.
 *
 * @return array<string, array<string, mixed>>
 */
function wp_mcp_ai_okf_reader_corpus_fixtures(): array {
	$files = glob( wp_mcp_ai_okf_reader_corpus_dir() . '/*.json' );

	if ( false === $files ) {
		return array();
	}

	$fixtures = array();

	foreach ( $files as $file ) {
		$decoded = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['tree'], $decoded['cases'] ) ) {
			continue;
		}

		$fixtures[ basename( $file, '.json' ) ] = $decoded;
	}

	ksort( $fixtures );

	return $fixtures;
}

/**
 * Write one fixture, pretty-printed so a behaviour change is a readable diff.
 *
 * @param string               $name   Bundle name.
 * @param array<string, mixed> $bundle Materialised fixture with expectations.
 * @return void
 * @throws RuntimeException When the fixture directory cannot be created.
 */
function wp_mcp_ai_okf_reader_corpus_write( string $name, array $bundle ): void {
	$dir = wp_mcp_ai_okf_reader_corpus_dir();

	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
		throw new RuntimeException( 'Cannot create the reader corpus directory.' );
	}

	$json = json_encode(
		$bundle,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);

	file_put_contents( $dir . '/' . $name . '.json', $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer, runs offline.
}
