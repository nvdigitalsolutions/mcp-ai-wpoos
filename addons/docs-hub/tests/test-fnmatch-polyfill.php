<?php
/**
 * Quick smoke test for the fnmatch polyfill.
 *
 * Run via: php tests/test-fnmatch-polyfill.php
 */

// Replicate the polyfill exactly as in nvoos-docs-hub.php.
if ( ! function_exists( 'fnmatch' ) ) {
	/**
	 * Polyfill for fnmatch() when the PHP extension is not available.
	 *
	 * @param string $pattern Glob pattern.
	 * @param string $string  String to match against.
	 * @param int    $flags   Bitmask of FNM_* flags.
	 * @return bool True if the string matches the pattern.
	 */
	function fnmatch( $pattern, $string, $flags = 0 ) {
		$regex  = '';
		$len    = strlen( $pattern );
		$escape = ! ( $flags & 2 );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $pattern[ $i ];

			if ( $escape && '\\' === $ch && $i + 1 < $len ) {
				++$i;
				$regex .= preg_quote( $pattern[ $i ], '#' );
				continue;
			}

			switch ( $ch ) {
				case '*':
					$regex .= ( $flags & 1 ) ? '[^/]*' : '.*';
					break;

				case '?':
					$regex .= ( $flags & 1 ) ? '[^/]' : '.';
					break;

				case '[':
					$class  = '[';
					$closed = false;
					++$i;
					if ( $i < $len && '!' === $pattern[ $i ] ) {
						$class .= '^';
						++$i;
					} elseif ( $i < $len && '^' === $pattern[ $i ] ) {
						$class .= '\\^';
						++$i;
					}
					for ( ; $i < $len; $i++ ) {
						if ( ']' === $pattern[ $i ] ) {
							$class .= ']';
							$closed = true;
							break;
						}
						$class .= '\\' . $pattern[ $i ];
					}
					$regex .= $closed ? $class : '\\[';
					break;

				default:
					$regex .= preg_quote( $ch, '#' );
					break;
			}
		}

		$regex = '#^' . $regex . '$#';
		$mods  = 'us';
		if ( $flags & 16 ) {
			$mods .= 'i';
		}

		$result = @preg_match( $regex . $mods, $string );
		return false !== $result && $result > 0;
	}
}

// --- Test cases matching patterns used in the docs-hub codebase ---

$tests = array(
	// Basic glob patterns.
	array( '*.md', 'readme.md', true, 'basic: *.md matches readme.md' ),
	array( '*.md', 'readme.txt', false, 'basic: *.md does not match .txt' ),

	// Wildcard matching across directory boundaries.
	array( 'node_modules/*', 'node_modules/package/index.js', true, 'deep: * matches across /' ),
	array( 'vendor/*', 'vendor/autoload.php', true, 'deep: * matches across / in vendor' ),

	// Prefix patterns (LICENSE*).
	array( 'LICENSE*', 'LICENSE', true, 'prefix: LICENSE matches LICENSE*' ),
	array( 'LICENSE*', 'LICENSE.md', true, 'prefix: LICENSE.md matches LICENSE*' ),

	// Dot-prefixed.
	array( '.git/*', '.git/HEAD', true, 'dot: .git/HEAD matches .git/*' ),

	// Exact match.
	array( 'wp-config-local.php', 'wp-config-local.php', true, 'exact match' ),
	array( 'wp-config-local.php', 'wp-config.php', false, 'exact: no false positive' ),

	// Path matching from the docs-hub tree picker.
	array( 'docs/intro.md', 'docs/intro.md', true, 'path exact' ),
	array( 'docs/*.md', 'docs/intro.md', true, 'path with glob' ),
	array( 'docs/*.md', 'docs/sub/intro.md', true, 'path glob matches subdir' ),
	array( 'changelog.md', 'changelog.md', true, 'changelog' ),
	array( '*.phar', 'tool.phar', true, 'phar' ),

	// Question mark - single character.
	array( 'file?.md', 'file1.md', true, '?: matches single char' ),
	array( 'file?.md', 'file12.md', false, '?: does not match two chars' ),

	// Negated character class.
	array( 'file[!0-9].md', 'fileA.md', true, '[!...]: matches non-digit' ),
	array( 'file[!0-9].md', 'file1.md', false, '[!...]: rejects digit' ),

	// Character class.
	array( 'file[0-9].md', 'file1.md', true, '[...]: matches digit' ),
	array( 'file[0-9].md', 'fileA.md', false, '[...]: rejects non-digit' ),

	// FNM_CASEFOLD.
	array( 'README.md', 'readme.md', true, 'FNM_CASEFOLD' ),
);

$passed = 0;
$failed = 0;

foreach ( $tests as $t ) {
	$flags  = isset( $t[4] ) ? $t[4] : ( strpos( $t[3], 'FNM_CASEFOLD' ) !== false ? 16 : 0 );
	$result = fnmatch( $t[0], $t[1], $flags );
	if ( $result === $t[2] ) {
		++$passed;
	} else {
		++$failed;
		$expect = $t[2] ? 'true' : 'false';
		$actual = $result ? 'true' : 'false';
		echo "FAIL: {$t[3]}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test diagnostic output.
		echo "  fnmatch('{$t[0]}', '{$t[1]}') = $actual, expected $expect\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test diagnostic output.
	}
}

$total = $passed + $failed;
echo "\nResults: $passed/$total passed"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test diagnostic output.
if ( $failed > 0 ) {
	echo " ($failed failed)"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test diagnostic output.
}
echo "\n";

exit( $failed > 0 ? 1 : 0 );
