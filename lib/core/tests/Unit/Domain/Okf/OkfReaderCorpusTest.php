<?php
/**
 * Replays the shared OKF reader corpus against the core reader.
 *
 * Same fixtures the WordPress-side test uses, so both implementations are held
 * to one contract. No WordPress bootstrap: a FilesystemBundleRoot over a temp
 * directory is all it needs.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Okf;

use Nvoos\Core\Domain\Okf\OkfReadException;
use Nvoos\Core\Domain\Okf\OkfReader;
use Nvoos\Core\Domain\Okf\OkfReaderInterface;
use Nvoos\Core\Infrastructure\Okf\FilesystemBundleRoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OkfReaderCorpusTest extends TestCase {

	/**
	 * Repository-root corpus directory.
	 *
	 * Shared with the WordPress test; absent in the split package, which
	 * testTheCorpusIsPresent() reports rather than hiding.
	 */
	private static function corpusDir(): string {
		return dirname( __DIR__, 6 ) . '/tests/fixtures/okf/reader';
	}

	/**
	 * Every committed case, keyed by `bundle::case`.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, mixed>}>
	 */
	public static function corpusCases(): array {
		$files = glob( self::corpusDir() . '/*.json' );

		if ( false === $files ) {
			return array();
		}

		$cases = array();

		foreach ( $files as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );

			if ( ! is_array( $decoded ) || ! isset( $decoded['tree'], $decoded['cases'] ) ) {
				continue;
			}

			$bundle = basename( $file, '.json' );

			foreach ( $decoded['cases'] as $case ) {
				$cases[ $bundle . '::' . $case['name'] ] = array( $bundle, $decoded['tree'], $case );
			}
		}

		ksort( $cases );

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function testTheCorpusIsPresent(): void {
		$this->assertGreaterThanOrEqual(
			30,
			count( self::corpusCases() ),
			'OKF reader corpus not found at ' . self::corpusDir()
		);
	}

	/**
	 * Every recorded case still produces its recorded result.
	 *
	 * @param string                $bundle Bundle name.
	 * @param array<string, string> $tree   Bundle tree.
	 * @param array<string, mixed>  $case   Case definition.
	 */
	#[DataProvider( 'corpusCases' )]
	public function testItMatchesTheRecordedResult( string $bundle, array $tree, array $case ): void {
		$root = self::materialise( $tree );

		try {
			$reader = new OkfReader( new FilesystemBundleRoot( $root ) );
			$actual = self::runCase( $reader, $case );
		} finally {
			self::destroy( $root );
		}

		$this->assertSame(
			$case['expected'],
			$actual,
			sprintf( 'Reader drifted on the "%s::%s" corpus case.', $bundle, $case['name'] )
		);
	}

	/**
	 * Dispatch a corpus case through the core reader.
	 *
	 * @param OkfReaderInterface   $reader Reader bound to a materialised bundle.
	 * @param array<string, mixed> $case   Case definition.
	 * @return mixed
	 */
	private static function runCase( OkfReaderInterface $reader, array $case ) {
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
					return self::sortById( $reader->search( isset( $args['criteria'] ) ? $args['criteria'] : array() ) );

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
			return array( 'error' => $exception->errorCode );
		}

		throw new \RuntimeException( 'Unknown reader corpus operation: ' . (string) $case['op'] );
	}

	/**
	 * Sort search results by concept ID so directory iteration order cannot
	 * influence the comparison.
	 *
	 * @param array<int, array<string, mixed>> $results Search results.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sortById( array $results ): array {
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

	/**
	 * Write a fixture tree into a fresh temp directory.
	 *
	 * @param array<string, string> $tree Relative path => content.
	 */
	private static function materialise( array $tree ): string {
		$root = rtrim( sys_get_temp_dir(), '/\\' ) . '/okf-core-reader-' . bin2hex( random_bytes( 8 ) );

		if ( ! mkdir( $root, 0775, true ) && ! is_dir( $root ) ) {
			throw new \RuntimeException( 'Cannot create the corpus bundle root.' );
		}

		foreach ( $tree as $relative => $content ) {
			$path = $root . '/' . $relative;
			$dir  = dirname( $path );

			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
				throw new \RuntimeException( 'Cannot create the corpus bundle directory: ' . $dir );
			}

			file_put_contents( $path, $content );
		}

		return $root;
	}

	/**
	 * Recursively remove a materialised bundle.
	 */
	private static function destroy( string $dir ): void {
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
				self::destroy( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
