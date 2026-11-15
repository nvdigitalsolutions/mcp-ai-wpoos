<?php
/**
 * PHPUnit twin for the OKF reader corpus (Proposal 145, Phase 3).
 *
 * Same fixtures as the offline runner, executed inside a real WordPress
 * bootstrap so CI proves the harness as well as the reader.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.5.0
 * @license GPL-3.0-or-later
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Replays every recorded reader case against the current implementation.
 */
class WP_MCP_AI_OKF_Reader_Corpus_Test extends WP_UnitTestCase {

	/**
	 * Corpus cases across all bundles.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, mixed>}>
	 */
	public static function corpus_cases(): array {
		require_once __DIR__ . '/support/okf-reader-corpus.php';

		$cases = array();

		foreach ( wp_mcp_ai_okf_reader_corpus_fixtures() as $bundle_name => $fixture ) {
			foreach ( $fixture['cases'] as $case ) {
				$key           = $bundle_name . '::' . $case['name'];
				$cases[ $key ] = array( $bundle_name, $fixture['tree'], $case );
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
	 * Every recorded case still produces its recorded result.
	 *
	 * @param string                $bundle_name Bundle name.
	 * @param array<string, string> $tree        Bundle tree.
	 * @param array<string, mixed>  $case        Case definition.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_reader_matches_the_recorded_result( $bundle_name, $tree, $case ) {
		require_once __DIR__ . '/support/okf-reader-corpus.php';

		$root = wp_mcp_ai_okf_reader_materialise( $tree );

		try {
			$reader = new WP_MCP_AI_OKF_Reader( $root );
			$actual = wp_mcp_ai_okf_reader_normalise(
				$case['op'],
				wp_mcp_ai_okf_reader_run_case( $reader, $case )
			);
		} finally {
			wp_mcp_ai_okf_reader_destroy( $root );
		}

		$this->assertSame(
			$case['expected'],
			$actual,
			sprintf( 'Reader drifted on the "%s::%s" corpus case.', $bundle_name, $case['name'] )
		);
	}

	/**
	 * A sibling directory sharing the bundle-root prefix must be rejected.
	 *
	 * `okf-reader-abc-outside` is not inside `okf-reader-abc`, but a naive
	 * string-prefix check would accept it. The corpus cannot express this
	 * because the bundle root name is generated per run, so it lives here.
	 */
	public function test_it_rejects_a_sibling_directory_sharing_the_root_prefix() {
		require_once __DIR__ . '/support/okf-reader-corpus.php';

		$root    = wp_mcp_ai_okf_reader_materialise( array( 'index.md' => "# Root\n" ) );
		$sibling = $root . '-outside';

		mkdir( $sibling, 0775, true );
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer.
			$sibling . '/escaped.md',
			"---\ntype: Concept\ntitle: Escaped\n---\n"
		);

		try {
			$reader = new WP_MCP_AI_OKF_Reader( $root );
			$result = $reader->get_concept( '../' . basename( $sibling ) . '/escaped' );

			$this->assertWPError( $result );
			$this->assertSame( 'okf_path_traversal', $result->get_error_code() );
		} finally {
			wp_mcp_ai_okf_reader_destroy( $sibling );
			wp_mcp_ai_okf_reader_destroy( $root );
		}
	}
}
