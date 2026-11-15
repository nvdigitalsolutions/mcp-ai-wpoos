<?php
/**
 * OKF parser corpus — the shared characterisation fixtures (Proposal 145, Phase 1).
 *
 * The corpus is the *behaviour contract* for the OKF parser. Phase 1 records what
 * the WordPress implementation does today; Phase 2 must make a framework-free
 * `Nvoos\Core\Domain\Okf\OkfParser` produce byte-identical structures for the same
 * inputs. Both sides read these fixtures and nothing else, so the comparison is
 * not open to interpretation.
 *
 * Each fixture is `{ "input": string, "expected": ... }` where `expected` is one of:
 *
 *   { "frontmatter": {...}, "body": "..." }   a successful parse
 *   { "null": true }                          no frontmatter block
 *   { "error": "<code>" }                     a WP_Error with that code
 *
 * The fixtures are generated from the current implementation, then reviewed by
 * hand — that ordering is deliberate. Regenerating them is a deliberate act: it
 * asserts "this new behaviour is correct", not "the test is now green".
 *
 * @package WP_MCP_AI\Tests
 * @since   2.5.0
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Directory holding the parser fixtures.
 */
function wp_mcp_ai_okf_corpus_dir(): string {
	return __DIR__ . '/../fixtures/okf/parser';
}

/**
 * Inputs for every case. `--write` regenerates the fixtures from these.
 *
 * One case per behaviour the parser implements, plus the edge cases that a
 * re-implementation is most likely to get wrong (a `---` inside a value, a BOM,
 * CRLF, unicode, quoted scalars that look numeric).
 *
 * @return array<string, string>
 */
function wp_mcp_ai_okf_corpus_inputs(): array {
	return array(
		'no-frontmatter'       => "Just a body, no delimiters.\n",

		'unclosed-frontmatter' => "---\ntype: Skill\ntitle: Broken\n",

		'empty-frontmatter'    => "---\n---\nBody only.\n",

		'scalars'              => "---\n"
			. "string: plain text\n"
			. "quoted_single: 'single quoted'\n"
			. "quoted_double: \"double quoted\"\n"
			. "bool_true: true\n"
			. "bool_false: false\n"
			. "null_word: null\n"
			. "null_tilde: ~\n"
			. "integer: 42\n"
			. "negative_integer: -7\n"
			. "float: 3.5\n"
			. "negative_float: -0.25\n"
			. "looks_numeric_but_quoted: '0042'\n"
			. "date_like: 2026-11-13\n"
			. "---\n"
			. "Body.\n",

		'block-list'           => "---\n"
			. "tags:\n"
			. "  - security\n"
			. "  - wordpress\n"
			. "  - 'quoted item'\n"
			. "---\n"
			. "Body.\n",

		'nested-map'           => "---\n"
			. "trust:\n"
			. "  tier: high\n"
			. "  score: 9\n"
			. "---\n"
			. "Body.\n",

		'list-of-objects'      => "---\n"
			. "sources:\n"
			. "  - name: First\n"
			. "    url: https://example.com/a\n"
			. "  - name: Second\n"
			. "    url: https://example.com/b\n"
			. "---\n"
			. "Body.\n",

		'inline-mapping'       => "---\n"
			. "meta: {author: Ada, version: 2}\n"
			. "---\n"
			. "Body.\n",

		'inline-sequence'      => "---\n"
			. "tags: [alpha, beta, gamma]\n"
			. "---\n"
			. "Body.\n",

		'mixed-nesting'        => "---\n"
			. "generated:\n"
			. "  by: agent\n"
			. "  consumers:\n"
			. "    - kind: assistant\n"
			. "      id: 7\n"
			. "    - kind: tool\n"
			. "      id: 9\n"
			. "---\n"
			. "Body.\n",

		'blank-lines'          => "---\n"
			. "type: Skill\n"
			. "\n"
			. "title: Spaced\n"
			. "\n"
			. "---\n"
			. "\n"
			. "Body starts after a blank line.\n",

		'crlf-endings'         => "---\r\ntype: Skill\r\ntitle: Windows\r\n---\r\nBody.\r\n",

		// Regression guard: a BOM is not whitespace to trim(), so before the parser
		// stripped it this document parsed as null and was silently invisible.
		'bom-prefixed'         => "\xEF\xBB\xBF---\ntype: Skill\n---\nBody.\n",

		'unicode'              => "---\n"
			. "title: Café — 日本語 — 🚀\n"
			. "tags:\n"
			. "  - naïve\n"
			. "  - Ωmega\n"
			. "---\n"
			. "Conteúdo.\n",

		'body-preserved'       => "---\ntype: Skill\n---\n"
			. "# Heading\n\n"
			. "Paragraph with **bold** and a [link](https://example.com).\n"
			. "\n"
			. "- a list\n"
			. "- in the body\n",

		'value-with-colon'     => "---\n"
			. "url: https://example.com/path?a=1\n"
			. "time: 12:30\n"
			. "---\n"
			. "Body.\n",

		'v01-skill'            => "---\n"
			. "type: Skill\n"
			. "title: Test Skill\n"
			. "description: A simple test\n"
			. "tags:\n"
			. "  - security\n"
			. "  - wordpress\n"
			. "---\n"
			. "# Heading\n",

		'v02-provenance'       => "---\n"
			. "type: Concept\n"
			. "title: Provenance Example\n"
			. "status: current\n"
			. "stale_after: 2027-01-01\n"
			. "sources:\n"
			. "  - name: Google\n"
			. "    url: https://example.com\n"
			. "    credibility:\n"
			. "      author: Ada\n"
			. "      usage_count: 12\n"
			. "---\n"
			. "Body.\n",

		'long-value'           => "---\ndescription: " . str_repeat( 'word ', 200 ) . "\n---\nBody.\n",
	);
}

/**
 * Normalise a `parse()` result into the fixture shape.
 *
 * @param mixed $result Whatever `WP_MCP_AI_OKF_Parser::parse()` returned.
 *
 * @return array<string, mixed>
 */
function wp_mcp_ai_okf_corpus_normalise( $result ): array {
	if ( null === $result ) {
		return array( 'null' => true );
	}

	if ( is_wp_error( $result ) ) {
		$codes = array_keys( $result->errors );

		return array( 'error' => (string) ( $codes[0] ?? 'unknown' ) );
	}

	return array(
		'frontmatter' => $result['frontmatter'],
		'body'        => $result['body'],
	);
}

/**
 * Load every committed fixture, keyed by case name.
 *
 * @return array<string, array{input: string, expected: array<string, mixed>}>
 */
function wp_mcp_ai_okf_corpus_fixtures(): array {
	$dir  = wp_mcp_ai_okf_corpus_dir();
	$out  = array();
	$files = glob( $dir . '/*.json' );

	foreach ( (array) $files as $file ) {
		$raw     = file_get_contents( $file );
		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['input'], $decoded['expected'] ) ) {
			continue;
		}

		$out[ basename( $file, '.json' ) ] = $decoded;
	}

	ksort( $out );

	return $out;
}

/**
 * Write one fixture, pretty-printed so a behaviour change is a readable diff.
 *
 * @param string               $name     Case name.
 * @param string               $input    Input document.
 * @param array<string, mixed> $expected Normalised expectation.
 *
 * @throws RuntimeException When the fixture directory cannot be created.
 */
function wp_mcp_ai_okf_corpus_write( string $name, string $input, array $expected ): void {
	$dir = wp_mcp_ai_okf_corpus_dir();

	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
		throw new RuntimeException( 'Cannot create the corpus directory.' );
	}

	$json = json_encode(
		array(
			'input'    => $input,
			'expected' => $expected,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);

	file_put_contents( $dir . '/' . $name . '.json', $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer, runs offline.
}
