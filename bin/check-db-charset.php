<?php
/**
 * Database charset gate.
 *
 * Fails when a first-party `CREATE TABLE` statement omits a character set.
 *
 * Why this exists: `character_set_server` is the MariaDB default (`latin1` on
 * 10.11 and earlier; `utf8mb4` only from 11.8), so any table created without an
 * explicit charset inherits it. On a `latin1` server that silently mangles
 * multi-byte text; on an `ascii` server it raises "Incorrect string value" on
 * the first non-ASCII insert. WordPress core and this plugin are safe because
 * they always pass `$wpdb->get_charset_collate()`; this gate stops a future
 * table from quietly depending on a server default we do not control.
 *
 * Detection is token-based, not line-based. The statement is reconstructed from
 * its string literal (double-quoted, single-quoted or heredoc), including
 * interpolated variables, so `"CREATE TABLE {$t} ( … ) {$charset_collate};"`
 * is recognised as having a charset even though the value arrives at runtime.
 * Comments are not string literals, so prose cannot trip the gate.
 *
 * A statement that builds its charset separately is accepted when a charset
 * marker appears before the end of the statement:
 *
 *     $sql = "CREATE TABLE {$t} ( … )";
 *     $sql .= $wpdb->get_charset_collate();
 *
 * Statements whose target is not WordPress MySQL (SQLite, Cloudflare D1, a
 * remote engine) opt out on the line with:
 *
 *     // charset-exempt: Cloudflare D1 (SQLite) schema
 *
 * Usage:
 *   php bin/check-db-charset.php                 Scan `includes/` (base scope).
 *   php bin/check-db-charset.php --scope=all     Scan `includes/`, `addons/`, `plugins/`.
 *   php bin/check-db-charset.php --selftest      Assert the detector still fires, then scan.
 *   php bin/check-db-charset.php --report        Never fail; print findings only.
 *   php bin/check-db-charset.php --only=addons   Restrict to paths containing the filter.
 *   php bin/check-db-charset.php --help          Show this help.
 *
 * Exit codes: 0 clean, 1 findings, 2 usage error.
 *
 * @package WP_MCP_AI
 * @since   1.2.25
 */

declare( strict_types=1 );

/**
 * Print usage and exit.
 *
 * @param int $code Exit code.
 * @return never
 */
function wp_mcp_ai_charset_usage( int $code ): never {
	$help = <<<'TXT'
Database charset gate — fails on CREATE TABLE without a character set.

Usage:
  php bin/check-db-charset.php                 Scan includes/ (base scope).
  php bin/check-db-charset.php --scope=all     Scan includes/, addons/, plugins/.
  php bin/check-db-charset.php --selftest      Assert the detector still fires, then scan.
  php bin/check-db-charset.php --report        Never fail; print findings only.
  php bin/check-db-charset.php --only=addons   Restrict to paths containing the filter.
  php bin/check-db-charset.php --help          Show this help.

Opt out for a non-MySQL target with a comment on the statement's line:
  // charset-exempt: <reason>

TXT;

	echo $code === 0 ? $help : 'Error: ' . $help;
	exit( $code );
}

/**
 * Whether a path is excluded from the scan.
 *
 * Vendored code, tests, examples and non-WordPress runtimes are out of scope:
 * their DDL follows their own driver's rules and is not ours to police.
 *
 * @param string $relative_path Path relative to the repository root.
 * @return bool
 */
function wp_mcp_ai_charset_is_excluded( string $relative_path ): bool {
	$normalised = str_replace( '\\', '/', $relative_path );

	$excluded_patterns = array(
		'#/vendor/#',
		'#/node_modules/#',
		'#/tests?/#',
		'#^tests?/#',
		'#/examples?/#',
		'#/assets/examples/#',
		'#^docker/#',
		'#/docker/#',
		'#^lib/laravel-adapter/#',
		'#^bin/#',
		'#/fixtures/#',
	);

	foreach ( $excluded_patterns as $pattern ) {
		if ( preg_match( $pattern, $normalised ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Collect PHP files beneath a root.
 *
 * @param string $root Absolute or relative directory.
 * @return array<int, string> Absolute-ish paths.
 */
function wp_mcp_ai_charset_collect( string $root ): array {
	if ( ! is_dir( $root ) ) {
		return array();
	}

	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$files[] = str_replace( '\\', '/', $file->getPathname() );
	}

	sort( $files );

	return $files;
}

/**
 * Resolve the line number of a single-character token.
 *
 * Punctuation tokens carry no line metadata, so the nearest preceding array
 * token supplies it.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $index  Index of the punctuation token.
 * @return int 1-based line number.
 */
function wp_mcp_ai_charset_line_of( array $tokens, int $index ): int {
	for ( $cursor = $index; $cursor >= 0; $cursor-- ) {
		if ( is_array( $tokens[ $cursor ] ) ) {
			return $tokens[ $cursor ][2] + substr_count( $tokens[ $cursor ][1], "\n" );
		}
	}

	return 1;
}

/**
 * Whether a reconstructed literal is DDL rather than prose or a regex.
 *
 * A real statement reaches its column list quickly, so an opening parenthesis
 * within ~120 characters is required. That excludes `'/^CREATE TABLE /'`
 * (a `preg_replace` pattern) and `'CREATE TABLE IF NOT EXISTS '` (its
 * replacement), neither of which is a table definition.
 *
 * @param string $literal Reconstructed statement text.
 * @return bool
 */
function wp_mcp_ai_charset_is_create_table( string $literal ): bool {
	return (bool) preg_match( '/\bCREATE\s+TABLE\b[\s\S]{0,120}?\(/i', $literal );
}

/**
 * Whether a reconstructed literal declares a character set.
 *
 * Matches any `charset` token rather than only `$charset_collate`, because the
 * repo uses both names (`$charset` in `checkout-api` and `qms`, `$charset_collate`
 * elsewhere) and both carry `$wpdb->get_charset_collate()`. Matching the broad
 * form risks a false negative; matching the narrow form produced five false
 * positives, and a gate that blocks a clean tree is worse than one that misses
 * an exotic case.
 *
 * `COLLATE` is accepted alongside `CHARACTER SET` because a table-level
 * collation implies the matching charset, and because `get_charset_collate()`
 * always emits both.
 *
 * @param string $literal Reconstructed statement text.
 * @return bool
 */
function wp_mcp_ai_charset_literal_is_safe( string $literal ): bool {
	return (bool) preg_match( '/charset|CHARACTER\s+SET|utf8mb4|COLLATE/i', $literal );
}

/**
 * Whether a statement is annotated as exempt.
 *
 * Checks the literal's first line and the two lines above it, so the annotation
 * can sit directly above a multi-line statement or at its end.
 *
 * @param array<int, string> $lines      File lines.
 * @param int                $start_line 1-based first line of the literal.
 * @param int                $end_line   1-based last line of the literal.
 * @return bool
 */
function wp_mcp_ai_charset_is_exempt( array $lines, int $start_line, int $end_line ): bool {
	$from = max( 1, $start_line - 2 );
	$to   = min( count( $lines ), $end_line + 1 );

	for ( $line = $from; $line <= $to; $line++ ) {
		if ( false !== stripos( $lines[ $line - 1 ], 'charset-exempt' ) ) {
			return true;
		}
	}

	return false;
}

$scope       = 'base';
$report_only = false;
$only        = '';
$selftest    = false;

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( '--report' === $argument ) {
		$report_only = true;
		continue;
	}

	if ( '--selftest' === $argument ) {
		$selftest = true;
		continue;
	}

	if ( 0 === strpos( $argument, '--scope=' ) ) {
		$scope = substr( $argument, strlen( '--scope=' ) );
		if ( ! in_array( $scope, array( 'base', 'all' ), true ) ) {
			fwrite( STDERR, "Unknown --scope value: {$scope}\n" );
			wp_mcp_ai_charset_usage( 2 );
		}
		continue;
	}

	if ( 0 === strpos( $argument, '--only=' ) ) {
		$only = substr( $argument, strlen( '--only=' ) );
		continue;
	}

	if ( '--help' === $argument || '-h' === $argument ) {
		wp_mcp_ai_charset_usage( 0 );
	}

	fwrite( STDERR, "Unknown argument: {$argument}\n" );
	wp_mcp_ai_charset_usage( 2 );
}

$roots = 'all' === $scope
	? array( 'includes', 'addons', 'plugins' )
	: array( 'includes' );

/*
 * Self-test. The detector is heuristic, so a refactor could quietly stop
 * matching and turn the gate permanently green. These fixtures fail the run
 * before the scan if that happens.
 *
 * Each case is [statement, is_ddl, declares_charset].
 */
if ( $selftest ) {
	$cases = array(
		array( 'CREATE TABLE {$wpdb->prefix}x ( id INT NOT NULL );', true, false ),
		array( 'create table if not exists {$t} ( id INT );', true, false ),
		array( 'CREATE TABLE {$t} ( id INT ) {$charset_collate};', true, true ),
		array( 'CREATE TABLE {$t} ( id INT ) {$charset};', true, true ),
		array( 'CREATE TABLE {$t} ( id INT ) DEFAULT CHARACTER SET utf8mb4;', true, true ),
		array( 'CREATE TABLE {$t} ( id INT ) COLLATE utf8mb4_unicode_ci;', true, true ),
		array( '/^CREATE TABLE /', false, false ),
		array( 'CREATE TABLE IF NOT EXISTS ', false, false ),
	);

	$failures = 0;

	foreach ( $cases as $case ) {
		list( $statement, $expect_ddl, $expect_safe ) = $case;

		$is_ddl = wp_mcp_ai_charset_is_create_table( $statement );
		if ( $is_ddl !== $expect_ddl ) {
			++$failures;
			fwrite( STDERR, "selftest: DDL detection mismatch for: {$statement}\n" );
			continue;
		}

		if ( ! $is_ddl ) {
			continue;
		}

		if ( wp_mcp_ai_charset_literal_is_safe( $statement ) !== $expect_safe ) {
			++$failures;
			fwrite( STDERR, "selftest: charset detection mismatch for: {$statement}\n" );
		}
	}

	if ( $failures > 0 ) {
		echo "Database charset gate: SELFTEST FAILED ({$failures}).\n";
		exit( 1 );
	}

	echo 'Database charset gate: selftest OK (' . count( $cases ) . " fixtures).\n";
}

$files = array();
foreach ( $roots as $root ) {
	$files = array_merge( $files, wp_mcp_ai_charset_collect( $root ) );
}
sort( $files );

$findings   = array();
$scanned    = 0;
$exemptions = 0;

foreach ( $files as $file ) {
	$relative = ltrim( str_replace( '\\', '/', $file ), './' );

	if ( '' !== $only && false === strpos( $relative, $only ) ) {
		continue;
	}

	if ( wp_mcp_ai_charset_is_excluded( $relative ) ) {
		continue;
	}

	$source = file_get_contents( $file );
	if ( false === $source ) {
		continue;
	}

	++$scanned;

	$lines  = preg_split( '/\R/', $source );
	$tokens = token_get_all( $source );
	$total  = count( $tokens );

	for ( $index = 0; $index < $total; $index++ ) {
		$token    = $tokens[ $index ];
		$is_array = is_array( $token );
		$id       = $is_array ? $token[0] : null;
		$text     = $is_array ? $token[1] : $token;

		$literal     = null;
		$start_line  = 0;
		$end_line    = 0;
		$resume_from = $index + 1;

		if ( $is_array && T_START_HEREDOC === $id ) {
			$start_line = $token[2];
			$end_line   = $token[2];
			$buffer     = '';

			for ( ++$index; $index < $total; $index++ ) {
				$inner = $tokens[ $index ];
				if ( is_array( $inner ) && T_END_HEREDOC === $inner[0] ) {
					$end_line = $inner[2];
					break;
				}
				$buffer .= is_array( $inner ) ? $inner[1] : $inner;
				if ( is_array( $inner ) ) {
					$end_line = $inner[2];
				}
			}

			$literal     = $buffer;
			$resume_from = $index + 1;
		} elseif ( ! $is_array && '"' === $text ) {
			$start_line = wp_mcp_ai_charset_line_of( $tokens, $index );
			$end_line   = $start_line;
			$buffer     = '';

			for ( ++$index; $index < $total; $index++ ) {
				$inner = $tokens[ $index ];
				if ( ! is_array( $inner ) && '"' === $inner ) {
					break;
				}
				$buffer .= is_array( $inner ) ? $inner[1] : $inner;
				if ( is_array( $inner ) ) {
					$end_line = $inner[2];
				}
			}

			$literal     = $buffer;
			$resume_from = $index + 1;
		} elseif ( $is_array && T_CONSTANT_ENCAPSED_STRING === $id ) {
			$start_line  = $token[2];
			$end_line    = $token[2] + substr_count( $text, "\n" );
			$literal     = $text;
			$resume_from = $index + 1;
		}

		if ( null === $literal || ! wp_mcp_ai_charset_is_create_table( $literal ) ) {
			continue;
		}

		if ( wp_mcp_ai_charset_literal_is_safe( $literal ) ) {
			continue;
		}

		// Accept a charset appended after the literal, before the statement ends.
		$lookahead = '';
		$budget    = 40;
		for ( $cursor = $resume_from; $cursor < $total && $budget > 0; $cursor++, $budget-- ) {
			$inner = $tokens[ $cursor ];
			$piece = is_array( $inner ) ? $inner[1] : $inner;
			$lookahead .= $piece;
			if ( ';' === $piece ) {
				break;
			}
		}

		if ( wp_mcp_ai_charset_literal_is_safe( $lookahead ) ) {
			continue;
		}

		if ( wp_mcp_ai_charset_is_exempt( $lines, $start_line, $end_line ) ) {
			++$exemptions;
			continue;
		}

		$findings[] = sprintf(
			'%s:%d  CREATE TABLE without a character set',
			$relative,
			$start_line
		);

		$index = $resume_from - 1;
	}
}

// Dedupe: two literals on one line must read as one finding.
$findings = array_values( array_unique( $findings ) );

$summary = sprintf(
	'Scanned %d PHP file(s); %d finding(s); %d exemption(s).',
	$scanned,
	count( $findings ),
	$exemptions
);

if ( array() === $findings ) {
	echo "Database charset gate: OK. {$summary}\n";
	exit( 0 );
}

echo "Database charset gate: FAILED\n\n";
foreach ( $findings as $finding ) {
	echo "  {$finding}\n";
}
echo "\n{$summary}\n";
echo "Pass \$wpdb->get_charset_collate() into every CREATE TABLE, or annotate a\n";
echo "non-MySQL target with '// charset-exempt: <reason>'.\n";

exit( $report_only ? 0 : 1 );
