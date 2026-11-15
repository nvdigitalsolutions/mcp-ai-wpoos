<?php
/**
 * Differential test — the core OKF reader and the WordPress reader agree.
 *
 * Proposal 145 Phase 3 gate (G3). The two readers are independent
 * implementations over the same materialised bundle, so this comparison is a
 * genuine oracle: it fails if either side drifts.
 *
 * The moment the WordPress class delegates to core, this test stops being
 * meaningful — that delegation belongs to Phase 4.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.5.0
 * @license GPL-3.0-or-later
 */

use Nvoos\Core\Domain\Okf\OkfReadException;
use Nvoos\Core\Domain\Okf\OkfReader;
use Nvoos\Core\Domain\Okf\OkfReaderInterface;
use Nvoos\Core\Infrastructure\Okf\FilesystemBundleRoot;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Differential coverage over the shared reader corpus.
 */
class Test_OKF_Reader_Differential extends WP_UnitTestCase {

	/**
	 * Corpus cases, one per fixture case.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, mixed>}>
	 */
	public static function corpus_cases(): array {
		require_once __DIR__ . '/support/okf-reader-corpus.php';

		$cases = array();

		foreach ( wp_mcp_ai_okf_reader_corpus_fixtures() as $bundle_name => $fixture ) {
			foreach ( $fixture['cases'] as $case ) {
				$cases[ $bundle_name . '::' . $case['name'] ] = array( $bundle_name, $fixture['tree'], $case );
			}
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function test_the_corpus_is_present() {
		$this->assertGreaterThanOrEqual( 30, count( self::corpus_cases() ) );
	}

	/**
	 * Core and WordPress return the same structure for every reader case.
	 *
	 * @param string                $bundle_name Bundle name.
	 * @param array<string, string> $tree        Bundle tree.
	 * @param array<string, mixed>  $case        Case definition.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_core_matches_wordpress( $bundle_name, $tree, $case ) {
		require_once __DIR__ . '/support/okf-reader-corpus.php';

		$root = wp_mcp_ai_okf_reader_materialise( $tree );

		try {
			$wordpress = wp_mcp_ai_okf_reader_normalise(
				$case['op'],
				wp_mcp_ai_okf_reader_run_case( new WP_MCP_AI_OKF_Reader( $root ), $case )
			);

			$core = $this->run_core( new OkfReader( new FilesystemBundleRoot( $root ) ), $case );
		} finally {
			wp_mcp_ai_okf_reader_destroy( $root );
		}

		$this->assertSame(
			$wordpress,
			$core,
			sprintf( 'Core and WordPress readers disagree on "%s::%s".', $bundle_name, $case['name'] )
		);

		$this->assertSame(
			$case['expected'],
			$core,
			sprintf( 'Core reader drifted from the recorded contract on "%s::%s".', $bundle_name, $case['name'] )
		);
	}

	/**
	 * Run a case through the core reader, flattening failures.
	 *
	 * @param OkfReaderInterface   $reader Core reader.
	 * @param array<string, mixed> $case   Case definition.
	 * @return mixed
	 * @throws RuntimeException When the operation is unknown.
	 */
	private function run_core( OkfReaderInterface $reader, array $case ) {
		$args = isset( $case['args'] ) ? $case['args'] : array();

		try {
			switch ( $case['op'] ) {
				case 'get_concept':
					return $reader->getConcept( isset( $args['concept_id'] ) ? $args['concept_id'] : '' );

				case 'browse':
					return $reader->browse( isset( $args['path'] ) ? $args['path'] : '' );

				case 'traverse':
					return $reader->traverse(
						isset( $args['concept_id'] ) ? $args['concept_id'] : '',
						isset( $args['max_depth'] ) ? $args['max_depth'] : 2
					);

				case 'search':
					return $this->sort_by_id(
						$reader->search( isset( $args['criteria'] ) ? $args['criteria'] : array() )
					);

				case 'get_types':
					return $reader->getTypes();

				case 'find_broken_links':
					return $reader->findBrokenLinks( isset( $args['concept_id'] ) ? $args['concept_id'] : '' );

				case 'get_trust_tier':
					return $reader->getTrustTier( isset( $args['frontmatter'] ) ? $args['frontmatter'] : array() );

				case 'is_stale':
					return $reader->isStale( isset( $args['frontmatter'] ) ? $args['frontmatter'] : array() );

				case 'file_to_concept_id':
					return $reader->fileToConceptId(
						$reader->bundleRoot()->root() . '/' . ( isset( $args['path'] ) ? $args['path'] : '' )
					);
			}
		} catch ( OkfReadException $exception ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $errorCode is a lib/core property; core packages use camelCase.
			return array( 'error' => $exception->errorCode );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message in a test harness; never rendered.
		throw new RuntimeException( 'Unknown reader corpus operation: ' . (string) $case['op'] );
	}

	/**
	 * Sort search results by concept ID.
	 *
	 * @param array<int, array<string, mixed>> $results Search results.
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_by_id( array $results ) {
		usort(
			$results,
			static function ( $left, $right ) {
				return strcmp(
					(string) ( $left['concept_id'] ?? '' ),
					(string) ( $right['concept_id'] ?? '' )
				);
			}
		);

		return $results;
	}
}
