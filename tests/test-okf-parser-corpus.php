<?php
/**
 * OKF parser characterisation corpus (Proposal 145, Phase 1).
 *
 * Replays the shared fixtures in `tests/fixtures/okf/parser/` against the
 * current WordPress implementation. Phase 2 must make a framework-free
 * `Nvoos\Core\Domain\Okf\OkfParser` produce identical structures for the same
 * inputs; this suite is the gate that says whether it does.
 *
 * The fixtures are the contract — this file only replays them. Regenerating the
 * corpus is a deliberate act (`tests/manual/run-okf-corpus.php --write`) that
 * asserts a behaviour change is correct.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
class WP_MCP_AI_OKF_Parser_Corpus_Test extends WP_UnitTestCase {

	/**
	 * Load the shared corpus helpers.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once __DIR__ . '/support/okf-corpus.php';
	}

	/**
	 * The corpus must cover every behaviour the parser implements.
	 *
	 * A missing fixture is worse than a failing one: the re-implementation would
	 * be "green" while quietly diverging on an untested path.
	 */
	public function test_the_corpus_covers_the_documented_behaviours() {
		$fixtures = wp_mcp_ai_okf_corpus_fixtures();

		$this->assertGreaterThanOrEqual( 15, count( $fixtures ), 'The corpus is suspiciously small.' );

		$required = array(
			'no-frontmatter',
			'unclosed-frontmatter',
			'empty-frontmatter',
			'scalars',
			'block-list',
			'nested-map',
			'list-of-objects',
			'inline-mapping',
			'inline-sequence',
			'mixed-nesting',
			'blank-lines',
			'crlf-endings',
			'bom-prefixed',
			'unicode',
			'body-preserved',
			'value-with-colon',
			'v01-skill',
			'v02-provenance',
		);

		foreach ( $required as $name ) {
			$this->assertArrayHasKey( $name, $fixtures, "Corpus is missing the '{$name}' case." );
		}
	}

	/**
	 * Every committed case parses to its recorded structure.
	 *
	 * @param string               $name     Case name.
	 * @param string               $input    Input document.
	 * @param array<string, mixed> $expected Recorded expectation.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'provide_corpus_cases' )]
	public function test_case_matches_the_recorded_structure( $name, $input, $expected ) {
		$parser = new WP_MCP_AI_OKF_Parser();
		$actual = wp_mcp_ai_okf_corpus_normalise( $parser->parse( $input ) );

		$this->assertSame(
			$expected,
			$actual,
			sprintf( 'OKF parser output drifted for the "%s" corpus case.', $name )
		);
	}

	/**
	 * Corpus cases, one per fixture on disk.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function provide_corpus_cases() {
		require_once __DIR__ . '/support/okf-corpus.php';

		$cases = array();

		foreach ( wp_mcp_ai_okf_corpus_fixtures() as $name => $fixture ) {
			$cases[ $name ] = array( $name, $fixture['input'], $fixture['expected'] );
		}

		return $cases;
	}
}
