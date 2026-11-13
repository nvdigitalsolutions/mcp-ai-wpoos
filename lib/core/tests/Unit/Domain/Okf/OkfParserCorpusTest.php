<?php
/**
 * Replays the shared OKF parser corpus against the core parser.
 *
 * The fixtures are the same files the WordPress-side test uses, so both
 * implementations are held to one contract. This test needs no WordPress
 * bootstrap: it is plain PHP reading JSON.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Okf;

use Nvoos\Core\Domain\Okf\OkfDocument;
use Nvoos\Core\Domain\Okf\OkfParseException;
use Nvoos\Core\Domain\Okf\OkfParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OkfParserCorpusTest extends TestCase {

	/**
	 * Repository-root corpus directory.
	 *
	 * The corpus sits outside the package because the WordPress test replays
	 * it too. In the split `nvoos/core` repository it is absent, which
	 * testTheCorpusIsPresent() reports explicitly rather than passing quietly.
	 */
	private static function corpusDir(): string {
		return dirname( __DIR__, 6 ) . '/tests/fixtures/okf/parser';
	}

	/**
	 * Every committed fixture, keyed by case name.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function corpusCases(): array {
		$files = glob( self::corpusDir() . '/*.json' );

		if ( false === $files ) {
			return array();
		}

		$cases = array();

		foreach ( $files as $file ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true );

			if ( ! is_array( $decoded ) || ! isset( $decoded['input'], $decoded['expected'] ) ) {
				continue;
			}

			$cases[ basename( $file, '.json' ) ] = array( $decoded['input'], $decoded['expected'] );
		}

		ksort( $cases );

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function testTheCorpusIsPresent(): void {
		$this->assertNotSame(
			array(),
			self::corpusCases(),
			'OKF corpus not found at ' . self::corpusDir()
		);
		$this->assertGreaterThanOrEqual( 19, count( self::corpusCases() ) );
	}

	/**
	 * Every committed case parses to its recorded structure.
	 *
	 * @param string               $input    Input document.
	 * @param array<string, mixed> $expected Recorded expectation.
	 */
	#[DataProvider( 'corpusCases' )]
	public function testItMatchesTheRecordedStructure( string $input, array $expected ): void {
		$parser = new OkfParser();

		// No frontmatter block at all: a legitimate body-only concept.
		if ( isset( $expected['null'] ) ) {
			$this->assertNull( $parser->parse( $input ) );
			return;
		}

		// Malformed frontmatter: the code must match the WordPress WP_Error code.
		if ( isset( $expected['error'] ) ) {
			try {
				$parser->parse( $input );
				$this->fail( 'Expected an OkfParseException for a malformed document.' );
			} catch ( OkfParseException $exception ) {
				$this->assertSame( $expected['error'], $exception->errorCode );
			}

			return;
		}

		$document = $parser->parse( $input );

		$this->assertInstanceOf( OkfDocument::class, $document );
		$this->assertSame( $expected, $document->toArray() );
	}
}
