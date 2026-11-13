<?php
// phpcs:ignoreFile -- Standalone offline runner for the OKF parser corpus (Proposal 145, Phase 1).
/**
 * Replay (or regenerate) the OKF parser characterisation corpus.
 *
 * The parser is WP-coupled only through `WP_Error` and `__()`, so the corpus can
 * run anywhere. The PHPUnit twin (`tests/test-okf-parser-corpus.php`) runs the
 * same fixtures inside the real WordPress test environment; this runner is what
 * you use locally, where that environment is not installed.
 *
 * Usage:
 *   php tests/manual/run-okf-corpus.php            # verify against the fixtures
 *   php tests/manual/run-okf-corpus.php --write    # regenerate them (review the diff!)
 *
 * --write is deliberately not the default: regenerating asserts "this new
 * behaviour is correct", not "make the test green".
 */

define( 'ABSPATH', true );

require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-parser.php';

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		public $errors = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->errors[ $code ][] = $message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { // phpcs:ignore
		return $thing instanceof WP_Error;
	}
}

require_once __DIR__ . '/../support/okf-corpus.php';

$write = in_array( '--write', $argv, true );
$parser = new WP_MCP_AI_OKF_Parser();

if ( $write ) {
	$written = 0;

	foreach ( wp_mcp_ai_okf_corpus_inputs() as $name => $input ) {
		$expected = wp_mcp_ai_okf_corpus_normalise( $parser->parse( $input ) );
		wp_mcp_ai_okf_corpus_write( $name, $input, $expected );
		echo "wrote {$name}\n";
		++$written;
	}

	echo "\nRegenerated {$written} fixtures. Review the diff before committing.\n";
	exit( 0 );
}

$fixtures = wp_mcp_ai_okf_corpus_fixtures();

if ( array() === $fixtures ) {
	fwrite( STDERR, "No fixtures found. Run with --write first.\n" );
	exit( 1 );
}

$passed = 0;
$failed = 0;

foreach ( $fixtures as $name => $fixture ) {
	$actual   = wp_mcp_ai_okf_corpus_normalise( $parser->parse( $fixture['input'] ) );
	$expected = $fixture['expected'];

	if ( $actual === $expected ) {
		++$passed;
		continue;
	}

	++$failed;
	echo "FAIL {$name}\n";
	echo '  expected: ' . json_encode( $expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	echo '  actual:   ' . json_encode( $actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
}

echo "\n{$passed} passed, {$failed} failed (" . count( $fixtures ) . " cases)\n";

exit( $failed > 0 ? 1 : 0 );
