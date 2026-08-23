<?php
/**
 * Per-file PHPUnit sweep — detects suite-killing test files.
 *
 * Some tests invoke real AJAX / SSE / admin handlers whose code paths end in
 * bare exit()/die() calls (WP 6.9 added several: wp_send_json() ends with
 * `die;` when not doing AJAX, check_ajax_referer() calls `die( '-1' )` on a
 * missing nonce). When such a handler fires inside a test, the whole phpunit
 * process terminates with exit code 0 and WITHOUT printing a summary — so the
 * suite silently "finishes" and the killing file is invisible.
 *
 * This runner executes each test file in its own phpunit process and
 * classifies the outcome:
 *
 *   PASS     — summary printed, 0 failures and 0 errors.
 *   FAIL     — summary printed, failures/errors reported (loud, not fatal).
 *   DIED     — NO summary line: the process was killed mid-run (exit trap).
 *   TIMEOUT  — exceeded --timeout seconds.
 *   NO_TESTS — phpunit reported no tests (e.g. helper file).
 *
 * Usage:
 *   php bin/sweep-tests.php                              # all suite files
 *   php bin/sweep-tests.php --files tests/test-admin-settings.php,tests/security/test-sse-auth-cors.php
 *   php bin/sweep-tests.php --only trap                   # re-check previous DIED/TIMEOUT files
 *   php bin/sweep-tests.php --runner 'bash bin/run-tests-docker.sh'   # prefix each command
 *   php bin/sweep-tests.php --timeout 120 --limit 20 --offset 40
 *   php bin/sweep-tests.php --report tests/sweep-results.w1.json      # parallel workers
 *
 * Reports are written to tests/sweep-results.json (overwritten per run,
 * or overridden with --report) and a human-readable table is printed to
 * stdout. Exit code is 1 when any file DIED or TIMED OUT, 0 otherwise.
 *
 * @package WP_MCP_AI
 */

$options = parse_options( $argv );

$plugin_root = dirname( __DIR__ );
if ( empty( $options['report'] ) ) {
	$options['report'] = $plugin_root . '/tests/sweep-results.json';
}
$test_dirs   = array(
	$plugin_root . '/tests',
	$plugin_root . '/addons/pro/tests',
	$plugin_root . '/addons/canvas-toolkit/tests',
	$plugin_root . '/addons/media-studio/tests',
	$plugin_root . '/addons/saas-controller/tests',
);

$excluded_substrings = array( '/manual/', '/helpers/', '/fixtures/', '/env/', 'bootstrap.php', '/regression/' );

$files = discover_test_files( $test_dirs, $excluded_substrings );

// Resume mode: only re-check files that previously died / timed out.
if ( isset( $options['only'] ) && 'trap' === $options['only'] ) {
	$previous = array();
	$report   = $options['report'];
	if ( file_exists( $report ) ) {
		$data = json_decode( file_get_contents( $report ), true );
		if ( is_array( $data ) ) {
			foreach ( $data as $row ) {
				if ( in_array( $row['status'], array( 'DIED', 'TIMEOUT' ), true ) ) {
					$previous[] = $row['file'];
				}
			}
		}
	}
	if ( empty( $previous ) ) {
		fwrite( STDOUT, "No previous DIED/TIMEOUT entries in {$report}. Run a full sweep first.\n" );
		exit( 0 );
	}
	$files = array_values( array_intersect( $files, $previous ) );
	fwrite( STDOUT, "Re-checking " . count( $files ) . " previously trapped files.\n\n" );
}

if ( isset( $options['files'] ) ) {
	$requested = array_map( 'trim', explode( ',', $options['files'] ) );
	// Normalise requested paths to absolute so they can intersect with the
	// discovered absolute paths.
	$requested_abs = array();
	foreach ( $requested as $requested_file ) {
		$requested_abs[] = ( 0 === strpos( $requested_file, $plugin_root ) )
			? $requested_file
			: $plugin_root . '/' . ltrim( $requested_file, '/\\' );
	}
	$files = array_values( array_intersect( $files, $requested_abs ) );
	if ( count( $files ) !== count( $requested ) ) {
		$found   = array_map(
			function ( $f ) use ( $plugin_root ) {
				return ltrim( str_replace( $plugin_root, '', $f ), '/\\' );
			},
			$files
		);
		$missing = array_diff( $requested, $found );
		fwrite( STDERR, 'Skipped non-test paths: ' . implode( ', ', $missing ) . "\n" );
	}
}

// List mode: print the discovered files (one per line) and exit. Used by
// parallel worker scripts to slice the file list deterministically.
if ( isset( $options['list'] ) ) {
	foreach ( $files as $file ) {
		fwrite( STDOUT, ltrim( str_replace( $plugin_root, '', $file ), '/\\' ) . "\n" );
	}
	exit( 0 );
}

$offset = (int) ( $options['offset'] ?? 0 );
$limit  = isset( $options['limit'] ) ? (int) $options['limit'] : 0;
if ( $limit > 0 ) {
	$files = array_slice( $files, $offset, $limit );
} else {
	$files = array_slice( $files, $offset );
}

fwrite( STDOUT, 'Sweeping ' . count( $files ) . " test files (timeout {$options['timeout']}s each)...\n\n" );

$results    = array();
$counts     = array(
	'PASS'     => 0,
	'FAIL'     => 0,
	'DIED'     => 0,
	'TIMEOUT'  => 0,
	'NO_TESTS' => 0,
);

foreach ( $files as $index => $file ) {
	$relative = ltrim( str_replace( $plugin_root, '', $file ), '/\\' );

	$cmd = str_replace(
		array( '{file}', '{relfile}' ),
		array( escapeshellarg( $file ), escapeshellarg( $relative ) ),
		$options['command']
	);

	$output     = '';
	$exit_code  = 0;
	$timed_out  = false;

	if ( ! empty( $options['runner'] ) ) {
		$cmd = $options['runner'] . ' ' . $cmd;
	}

	$proc = proc_open(
		$cmd,
		array(
			0 => array( 'file', 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? 'NUL' : '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$plugin_root
	);

	if ( is_resource( $proc ) ) {
		$deadline  = microtime( true ) + (float) $options['timeout'];
		$streams   = array( $pipes[1], $pipes[2] );
		$all       = array();
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		while ( true ) {
			$read   = $streams;
			$write  = null;
			$except = null;
			$count  = stream_select( $read, $write, $except, 0, 200000 );

			if ( false === $count ) {
				break;
			}

			foreach ( $read as $stream ) {
				$chunk = stream_get_contents( $stream );
				if ( false !== $chunk && '' !== $chunk ) {
					$all[] = $chunk;
				}
			}

			$status = proc_get_status( $proc );
			if ( ! $status['running'] ) {
				$exit_code = (int) $status['exitcode'];
				break;
			}

			if ( microtime( true ) > $deadline ) {
				proc_terminate( $proc, 9 );
				proc_close( $proc );
				$timed_out = true;
				break;
			}
		}

		if ( ! $timed_out ) {
			// Drain remaining output.
			foreach ( $streams as $stream ) {
				$chunk = stream_get_contents( $stream );
				if ( false !== $chunk && '' !== $chunk ) {
					$all[] = $chunk;
				}
			}
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
		}

		$output = implode( '', $all );
	}

	$status = classify( $output, $timed_out );

	$results[] = array(
		'file'      => $relative,
		'status'    => $status,
		'exit_code' => $exit_code,
		'summary'   => extract_summary( $output ),
		'tail'      => substr( $output, -400 ),
	);

	$counts[ $status ]++;

	$marker = array(
		'PASS'     => 'PASS ',
		'FAIL'     => 'FAIL ',
		'DIED'     => 'DIED ',
		'TIMEOUT'  => 'TIME ',
		'NO_TESTS' => 'NONE ',
	);
	fwrite( STDOUT, sprintf( '[%3d/%d] %s %s', $index + 1, count( $files ), $marker[ $status ], $relative ) );
	$summary = extract_summary( $output );
	if ( '' !== $summary ) {
		fwrite( STDOUT, "  ({$summary})" );
	}
	fwrite( STDOUT, "\n" );
}

file_put_contents(
	$options['report'],
	wp_json_encode_indent( $results, 0 ) . "\n"
);

fwrite( STDOUT, "\n===== SWEEP SUMMARY =====\n" );
foreach ( $counts as $status => $count ) {
	fwrite( STDOUT, sprintf( "%-8s %d\n", $status, $count ) );
}

$trapped = array();
foreach ( $results as $row ) {
	if ( in_array( $row['status'], array( 'DIED', 'TIMEOUT' ), true ) ) {
		$trapped[] = $row['file'];
	}
}

if ( $trapped ) {
	fwrite( STDOUT, "\nProcess-killing files (fix these first):\n" );
	foreach ( $trapped as $file ) {
		fwrite( STDOUT, "  - {$file}\n" );
	}
	fwrite( STDOUT, "\nRe-check after fixing: php bin/sweep-tests.php --only trap\n" );
	exit( 1 );
}

fwrite( STDOUT, "\nNo process-killing files detected.\n" );
exit( 0 );

/**
 * Minimal JSON pretty-printer (PHP 7.4 compatible).
 *
 * @param mixed $value Value to encode.
 * @param int   $depth Indent depth.
 * @return string
 */
function wp_json_encode_indent( $value, $depth = 0 ) {
	$indent = str_repeat( '  ', $depth );
	if ( is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
		if ( empty( $value ) ) {
			return '[]';
		}
		$items = array();
		foreach ( $value as $item ) {
			$items[] = "\n" . $indent . '  ' . wp_json_encode_indent( $item, $depth + 1 );
		}
		return '[' . implode( ',', $items ) . "\n" . $indent . ']';
	}
	if ( is_array( $value ) ) {
		if ( empty( $value ) ) {
			return '{}';
		}
		$items = array();
		foreach ( $value as $key => $item ) {
			$items[] = "\n" . $indent . '  ' . json_encode( (string) $key, JSON_UNESCAPED_SLASHES ) . ': ' . wp_json_encode_indent( $item, $depth + 1 );
		}
		return '{' . implode( ',', $items ) . "\n" . $indent . '}';
	}

	// Captured output can contain invalid UTF-8 (byte-truncated tails, binary
	// output from tests). json_encode() returns false for such values, which
	// would silently drop the value from the report — substitute instead.
	$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $encoded ) {
		$encoded = '""';
	}
	return $encoded;
}

/**
 * Parse CLI options.
 *
 * @param array $argv Raw argv.
 * @return array<string,mixed>
 */
function parse_options( array $argv ) {
	$options = array(
		'command' => PHP_BINARY . ' vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage {file}',
		'runner'  => '',
		'timeout' => 600,
		'report'  => '',
	);

	for ( $i = 1; $i < count( $argv ); $i++ ) {
		switch ( $argv[ $i ] ) {
			case '--runner':
				$options['runner'] = $argv[ ++$i ];
				break;
			case '--timeout':
				$options['timeout'] = (int) $argv[ ++$i ];
				break;
			case '--limit':
				$options['limit'] = (int) $argv[ ++$i ];
				break;
			case '--offset':
				$options['offset'] = (int) $argv[ ++$i ];
				break;
			case '--files':
				$options['files'] = $argv[ ++$i ];
				break;
			case '--only':
				$options['only'] = $argv[ ++$i ];
				break;
			case '--report':
				$options['report'] = $argv[ ++$i ];
				break;
			case '--list':
				$options['list'] = true;
				break;
			default:
				fwrite( STDERR, "Unknown option: {$argv[ $i ]}\n" );
				exit( 2 );
		}
	}

	return $options;
}

/**
 * Collect suite test files.
 *
 * Uses scandir()/glob() recursion rather than SPL iterators: SPL directory
 * iterators can return truncated listings on some networked filesystems
 * (Docker Desktop's bind mounts), which silently hides test files.
 *
 * @param array $dirs               Directory list.
 * @param array $excluded_substrings Path substrings to skip.
 * @return string[]
 */
function discover_test_files( array $dirs, array $excluded_substrings ) {
	$files = array();
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$files = array_merge( $files, scan_dir_recursive( $dir, $excluded_substrings ) );
	}
	sort( $files );
	return $files;
}

/**
 * Recursively collect `test-*.php` files below a directory.
 *
 * @param string $dir                Directory to scan.
 * @param array  $excluded_substrings Path substrings to skip.
 * @return string[]
 */
function scan_dir_recursive( $dir, array $excluded_substrings ) {
	$found  = array();
	$names  = scandir( $dir );
	if ( false === $names ) {
		return $found;
	}

	foreach ( $names as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		$path     = rtrim( $dir, '/\\' ) . '/' . $name;
		$relative = str_replace( '\\', '/', $path );

		if ( is_dir( $path ) ) {
			$skip = false;
			// Append a slash so `'/manual/'`-style needles also match the
			// directory itself (e.g. `.../tests/manual`).
			$relative_dir = $relative . '/';
			foreach ( $excluded_substrings as $needle ) {
				if ( false !== strpos( $relative_dir, $needle ) ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				$found = array_merge( $found, scan_dir_recursive( $path, $excluded_substrings ) );
			}
			continue;
		}

		if ( 0 !== strpos( $name, 'test-' ) && 0 !== strpos( $name, 'Test' ) ) {
			continue;
		}
		if ( 'php' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			continue;
		}
		foreach ( $excluded_substrings as $needle ) {
			if ( false !== strpos( $relative . '/', $needle ) ) {
				continue 2;
			}
		}
		$found[] = $path;
	}

	return $found;
}

/**
 * Classify a phpunit run from its output.
 *
 * @param string $output   Combined stdout/stderr.
 * @param bool   $timed_out Whether the process was killed by the timeout.
 * @return string PASS|FAIL|DIED|TIMEOUT|NO_TESTS
 */
function classify( $output, $timed_out ) {
	if ( $timed_out ) {
		return 'TIMEOUT';
	}

	if ( false !== strpos( $output, 'No tests executed' ) ) {
		return 'NO_TESTS';
	}

	$summary = extract_summary( $output );
	if ( '' === $summary ) {
		// No summary = the process was terminated mid-run (exit trap).
		return 'DIED';
	}

	if ( preg_match( '/Failures:\s*(\d+)/', $summary, $m ) && (int) $m[1] > 0 ) {
		return 'FAIL';
	}
	if ( preg_match( '/Errors:\s*(\d+)/', $summary, $m ) && (int) $m[1] > 0 ) {
		return 'FAIL';
	}

	return 'PASS';
}

/**
 * Extract the "Tests: N, Assertions: M, ..." summary line from phpunit output.
 *
 * @param string $output Combined stdout/stderr.
 * @return string
 */
function extract_summary( $output ) {
	if ( preg_match( '/Tests:\s*\d+,[^\n]*/', $output, $m ) ) {
		return trim( $m[0] );
	}
	return '';
}
