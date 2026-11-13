<?php
/**
 * Differential test — the core OKF parser and the WordPress parser agree.
 *
 * Proposal 145 Phase 2 gate (G2). The WordPress class and the framework-free
 * `Nvoos\Core\Domain\Okf\OkfParser` are independent implementations, so this
 * comparison is a genuine oracle: it fails if either side drifts.
 *
 * The moment the WordPress class starts delegating to core, this test stops
 * being meaningful — that delegation belongs to Phase 4, after parity is
 * established here.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.5.0
 * @license GPL-3.0-or-later
 */

use Nvoos\Core\Domain\Okf\OkfDocument;
use Nvoos\Core\Domain\Okf\OkfParseException;
use Nvoos\Core\Domain\Okf\OkfParser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Differential coverage over the shared golden corpus.
 */
class Test_OKF_Parser_Differential extends WP_UnitTestCase {

	/**
	 * Corpus cases, one per fixture on disk.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function corpus_cases(): array {
		require_once __DIR__ . '/support/okf-corpus.php';

		$cases = array();

		foreach ( wp_mcp_ai_okf_corpus_fixtures() as $name => $fixture ) {
			$cases[ $name ] = array( $name, $fixture['input'], $fixture['expected'] );
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function test_the_corpus_is_present() {
		$this->assertGreaterThanOrEqual( 19, count( self::corpus_cases() ) );
	}

	/**
	 * Core and WordPress produce the same structure for every corpus entry.
	 *
	 * @param string               $name     Case name.
	 * @param string               $input    Input document.
	 * @param array<string, mixed> $expected Recorded expectation.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_core_matches_wordpress( $name, $input, $expected ) {
		require_once __DIR__ . '/support/okf-corpus.php';

		$wordpress = wp_mcp_ai_okf_corpus_normalise( ( new WP_MCP_AI_OKF_Parser() )->parse( $input ) );
		$core      = $this->normalise_core( $input );

		$this->assertSame(
			$wordpress,
			$core,
			sprintf( 'Core and WordPress parsers disagree on the "%s" corpus case.', $name )
		);

		$this->assertSame(
			$expected,
			$core,
			sprintf( 'Core parser drifted from the recorded contract on "%s".', $name )
		);
	}

	/**
	 * Run the core parser and flatten its result into the fixture shape.
	 *
	 * @param string $input Input document.
	 * @return array<string, mixed>
	 */
	private function normalise_core( $input ) {
		try {
			$document = ( new OkfParser() )->parse( $input );
		} catch ( OkfParseException $exception ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $errorCode is a lib/core property; core packages use camelCase.
			return array( 'error' => $exception->errorCode );
		}

		if ( ! $document instanceof OkfDocument ) {
			return array( 'null' => true );
		}

		return $document->toArray();
	}
}
