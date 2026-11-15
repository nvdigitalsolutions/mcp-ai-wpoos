<?php
/**
 * Offline runner for the OKF reader corpus (Proposal 145, Phase 3).
 *
 * Runs without WordPress and without PHPUnit: it stubs the handful of WP
 * functions the reader touches, materialises each bundle in a temp directory,
 * and compares the reader's output with the recorded expectation.
 *
 *   php tests/manual/run-okf-reader-corpus.php          # verify
 *   php tests/manual/run-okf-reader-corpus.php --write  # regenerate fixtures
 *
 * Regenerating is a deliberate act: it asserts "this new behaviour is correct",
 * not "the test is green again".
 *
 * @package WP_MCP_AI\Tests
 * @since   2.5.0
 * @license GPL-3.0-or-later
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Standalone runner, no WordPress loaded.

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Absolute integer stub.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Trailing-slash stripper stub.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Path normaliser matching WordPress's implementation.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '|(?<=.)/+|', '/', $path );

		if ( ':' === substr( $path, 1, 1 ) ) {
			$path = ucfirst( $path );
		}

		return $path;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Text sanitiser matching the parts of WordPress's version that matter here.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		$value = strip_tags( (string) $value );
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );

		return trim( (string) $value );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stand-in.
	 */
	class WP_Error {

		/**
		 * Error codes mapped to messages.
		 *
		 * @var array<string, string[]>
		 */
		public $errors = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
			}
		}

		/**
		 * First error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			$codes = array_keys( $this->errors );

			return isset( $codes[0] ) ? (string) $codes[0] : '';
		}

		/**
		 * First error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			$code = $this->get_error_code();

			return isset( $this->errors[ $code ][0] ) ? (string) $this->errors[ $code ][0] : '';
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Error check stub.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/okf/class-wp-mcp-ai-okf-parser.php';
require_once dirname( __DIR__, 2 ) . '/includes/okf/class-wp-mcp-ai-okf-reader.php';
require_once __DIR__ . '/../support/okf-reader-corpus.php';

$write = in_array( '--write', $argv, true );
$set   = $write ? wp_mcp_ai_okf_reader_corpus_bundles() : wp_mcp_ai_okf_reader_corpus_fixtures();

if ( array() === $set ) {
	fwrite( STDERR, "No reader corpus fixtures found.\n" );
	exit( 1 );
}

$passed = 0;
$failed = 0;

foreach ( $set as $name => $bundle ) {
	if ( ! isset( $bundle['tree'], $bundle['cases'] ) ) {
		continue;
	}

	$root = wp_mcp_ai_okf_reader_materialise( $bundle['tree'] );

	try {
		$reader = new WP_MCP_AI_OKF_Reader( $root );

		foreach ( $bundle['cases'] as $index => $case ) {
			$actual = wp_mcp_ai_okf_reader_normalise(
				$case['op'],
				wp_mcp_ai_okf_reader_run_case( $reader, $case )
			);

			if ( $write ) {
				$set[ $name ]['cases'][ $index ]['expected'] = $actual;
				++$passed;
				continue;
			}

			$expected = isset( $case['expected'] ) ? $case['expected'] : null;

			if ( $expected === $actual ) {
				++$passed;
				continue;
			}

			++$failed;
			fwrite(
				STDERR,
				sprintf(
					"FAIL %s/%s\n  expected: %s\n  actual:   %s\n",
					$name,
					(string) $case['name'],
					(string) json_encode( $expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					(string) json_encode( $actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				)
			);
		}
	} finally {
		wp_mcp_ai_okf_reader_destroy( $root );
	}

	if ( $write ) {
		wp_mcp_ai_okf_reader_corpus_write( $name, $set[ $name ] );
	}
}

if ( $write ) {
	echo sprintf( "Regenerated %d cases across %d bundles. Review the diff before committing.\n", $passed, count( $set ) );
	exit( 0 );
}

echo sprintf( "%d passed, %d failed (%d cases)\n", $passed, $failed, $passed + $failed );
exit( $failed > 0 ? 1 : 0 );
