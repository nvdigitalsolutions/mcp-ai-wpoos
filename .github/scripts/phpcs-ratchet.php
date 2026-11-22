<?php
/**
 * CI gate — the tree-wide WPCS budget may only shrink.
 *
 * Why this exists: the previous gate allowed ten errors per changed file.
 * That is a per-file allowance, so a large file could keep adding errors
 * forever, and a PR touching twenty files could leave the tree two hundred
 * findings worse off without ever tripping it. Warnings were not counted at
 * all. The push-to-main job did compare a tree-wide total, but the number was
 * hardcoded in the workflow (1175, with a 1200 ceiling) and nothing made it
 * move: the `elif` that noticed an improvement only printed a message.
 *
 * The result is the state this gate was written for — a tree carrying
 * thousands of findings, where each cleanup was invisible to CI and the next
 * one was therefore optional.
 *
 * The rule here is the one the research keeps landing on: a baseline without
 * a ratchet is a suggestion.
 *
 *  1. No scope may exceed its committed errors or warnings.
 *  2. A scope that comes in *under* budget by more than `tolerance` fails as
 *     stale. The improvement has to be recorded in the same PR, so it cannot
 *     be quietly given back later.
 *  3. Named sources — the security sniffs, the database sniffs and this
 *     repo's own WPMCPAI gate sniffs — carry their own budgets, so a category
 *     cannot hide inside a scope total.
 *  4. A source listed in `zero_tolerance` must be zero. A parse error is not
 *     debt; neither is a rule the tree has already fully satisfied.
 *
 * Usage:
 *   php .github/scripts/phpcs-ratchet.php --check
 *   php .github/scripts/phpcs-ratchet.php --update
 *   php .github/scripts/phpcs-ratchet.php --update --allow-increase
 *   php .github/scripts/phpcs-ratchet.php --check --input=build/phpcs.json
 *
 * Exit 0 = within budget and not stale; exit 1 = violation; exit 2 = setup
 * or parse problem.
 *
 * `--input` reads a PHPCS JSON report instead of running PHPCS. It exists for
 * CI log triage and for testing this gate without a ten-minute scan.
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

/*
 * This is a CI gate, not plugin code. It runs on a bare PHP runner with no
 * WordPress loaded, so wp_json_encode(), WP_Filesystem and the escaping
 * helpers do not exist, and its output goes to a terminal or a CI log rather
 * than to HTML. Running PHPCS is also the whole point of the script, so the
 * system-call warning has no alternative here. The gate's own behaviour is
 * covered by its failure-mode tests rather than by these sniffs.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

/**
 * Repository root.
 *
 * @return string
 */
function phpcs_ratchet_root(): string {
	return dirname( __DIR__, 2 );
}

/**
 * Print usage and stop.
 *
 * @param string $message Optional failure message.
 * @param int    $code    Exit code.
 * @return never
 */
function phpcs_ratchet_fail( string $message = '', int $code = 2 ): never {
	if ( '' !== $message ) {
		fwrite( STDERR, $message . "\n" );
	}
	fwrite( STDERR, "Usage: php .github/scripts/phpcs-ratchet.php [--check|--update] [--allow-increase] [--input=FILE] [--budget=FILE] [--standard=PATH]\n" );
	exit( $code );
}

/**
 * Read and validate the committed budget.
 *
 * @param string $file Budget path.
 * @return array
 */
function phpcs_ratchet_load_budget( string $file ): array {
	if ( ! is_readable( $file ) ) {
		phpcs_ratchet_fail( "Budget file not found: {$file}" );
	}

	$raw    = (string) file_get_contents( $file );
	$budget = json_decode( $raw, true );

	if ( ! is_array( $budget ) ) {
		phpcs_ratchet_fail( "Budget file is not valid JSON: {$file}" );
	}

	if ( empty( $budget['scopes'] ) || ! is_array( $budget['scopes'] ) ) {
		phpcs_ratchet_fail( 'Budget file has no scopes.' );
	}

	$catch_all = 0;
	foreach ( $budget['scopes'] as $scope ) {
		if ( empty( $scope['name'] ) ) {
			phpcs_ratchet_fail( 'Every scope needs a name.' );
		}
		if ( ! isset( $scope['paths'] ) || ! is_array( $scope['paths'] ) ) {
			phpcs_ratchet_fail( "Scope {$scope['name']} needs a paths array (empty for the catch-all)." );
		}
		if ( empty( $scope['paths'] ) ) {
			++$catch_all;
		}
		foreach ( array( 'errors', 'warnings' ) as $metric ) {
			if ( ! isset( $scope[ $metric ] ) || ! is_int( $scope[ $metric ] ) ) {
				phpcs_ratchet_fail( "Scope {$scope['name']} needs an integer {$metric} budget." );
			}
		}
	}

	if ( 1 !== $catch_all ) {
		phpcs_ratchet_fail( 'Exactly one scope must be the catch-all (an empty paths array).' );
	}

	$budget['tolerance'] = isset( $budget['tolerance'] ) ? (int) $budget['tolerance'] : 0;

	return $budget;
}

/**
 * Run PHPCS and return its raw output.
 *
 * @param string $root     Repository root.
 * @param string $standard Path to the ruleset.
 * @return string Raw stdout (progress line plus JSON).
 */
function phpcs_ratchet_run_phpcs( string $root, string $standard ): string {
	$phpcs = $root . '/vendor/bin/phpcs';

	if ( ! is_readable( $phpcs ) ) {
		phpcs_ratchet_fail( 'vendor/bin/phpcs is missing. Run `composer install` first.' );
	}

	$command = array(
		PHP_BINARY,
		'-d',
		'memory_limit=2G',
		$phpcs,
		'--standard=' . $standard,
		'--report=json',
		'--basepath=' . $root,
		'-q',
	);

	/*
	 * No --cache here on purpose. PHPCS's cache changes which files are
	 * re-tokenised in a run, and a few WPCS sniffs are order-sensitive, so a
	 * cached run and a cold run can disagree by a handful of warnings. A budget
	 * gate needs the same answer twice more than it needs the speed.
	 */
	$command[] = '.';

	$descriptors = array(
		0 => array( 'file', '/dev/null', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	// On Windows /dev/null does not exist; use the platform null device.
	if ( '\\' === DIRECTORY_SEPARATOR ) {
		$descriptors[0] = array( 'file', 'NUL', 'r' );
	}

	$process = proc_open( $command, $descriptors, $pipes, $root );

	if ( ! is_resource( $process ) ) {
		phpcs_ratchet_fail( 'Could not start PHPCS.' );
	}

	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );

	/*
	 * PHPCS exit codes for a run: 0 = clean, 1 = errors found with nothing
	 * auto-fixable, 2 = errors found with some auto-fixable. All three carry a
	 * report, so the exit code is not a health signal here — the report is.
	 * Only a missing report is a failure.
	 */
	if ( $exit > 2 ) {
		fwrite( STDERR, $stdout . "\n" . $stderr );
		phpcs_ratchet_fail( "PHPCS exited {$exit} before producing a report." );
	}

	return $stdout;
}

/**
 * Pull the JSON report out of PHPCS output.
 *
 * @param string $raw Raw output.
 * @return array
 */
function phpcs_ratchet_parse_report( string $raw ): array {
	$start = strpos( $raw, '{' );
	if ( false === $start ) {
		phpcs_ratchet_fail( "No JSON found in the PHPCS report. Last 2,000 characters:\n" . substr( $raw, -2000 ), 2 );
	}

	$report = json_decode( substr( $raw, $start ), true );

	if ( ! is_array( $report ) || ! isset( $report['files'] ) ) {
		phpcs_ratchet_fail( 'PHPCS report JSON did not contain a files map.' );
	}

	return $report;
}

/**
 * Normalise a report path to a repository-relative path with forward slashes.
 *
 * @param string $path Absolute or relative path from the report.
 * @param string $root Repository root.
 * @return string
 */
function phpcs_ratchet_relative( string $path, string $root ): string {
	$path = str_replace( '\\', '/', $path );
	$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';

	if ( 0 === strpos( $path, $root ) ) {
		$path = substr( $path, strlen( $root ) );
	}

	return ltrim( $path, '/' );
}

/**
 * Resolve a path to its scope.
 *
 * First match wins, so a budget file must list specific prefixes before
 * general ones. A path matching no scope falls to the catch-all.
 *
 * @param string $relative Repository-relative path.
 * @param array  $scopes   Scope definitions.
 * @return array The matching scope.
 */
function phpcs_ratchet_scope_for( string $relative, array $scopes ): array {
	$catch_all = null;

	foreach ( $scopes as $scope ) {
		if ( empty( $scope['paths'] ) ) {
			$catch_all = $scope;
			continue;
		}
		foreach ( $scope['paths'] as $prefix ) {
			if ( 0 === strpos( $relative, $prefix ) ) {
				return $scope;
			}
		}
	}

	if ( null === $catch_all ) {
		phpcs_ratchet_fail( "No scope and no catch-all for {$relative}." );
	}

	return $catch_all;
}

/**
 * Aggregate the report by scope and by source.
 *
 * @param array  $report Parsed PHPCS report.
 * @param string $root   Repository root.
 * @param array  $scopes Scope definitions.
 * @return array{scopes: array, sources: array, files: int}
 */
function phpcs_ratchet_aggregate( array $report, string $root, array $scopes ): array {
	$aggregate = array(
		'scopes'  => array(),
		'sources' => array(),
		'files'   => 0,
	);

	foreach ( $scopes as $scope ) {
		$aggregate['scopes'][ $scope['name'] ] = array(
			'errors'   => 0,
			'warnings' => 0,
			'files'    => 0,
		);
	}

	foreach ( $report['files'] as $path => $info ) {
		$relative = phpcs_ratchet_relative( (string) $path, $root );
		$scope    = phpcs_ratchet_scope_for( $relative, $scopes );
		$name     = $scope['name'];

		++$aggregate['files'];
		++$aggregate['scopes'][ $name ]['files'];
		$aggregate['scopes'][ $name ]['errors']   += (int) $info['errors'];
		$aggregate['scopes'][ $name ]['warnings'] += (int) $info['warnings'];

		foreach ( $info['messages'] as $message ) {
			$source = (string) $message['source'];
			if ( ! isset( $aggregate['sources'][ $source ] ) ) {
				$aggregate['sources'][ $source ] = 0;
			}
			++$aggregate['sources'][ $source ];
		}
	}

	return $aggregate;
}

/**
 * Collect any zero-tolerance sources that are not zero.
 *
 * @param array $budget    Budget definition.
 * @param array $aggregate Observed values.
 * @return array List of human-readable violations.
 */
function phpcs_ratchet_zero_hits( array $budget, array $aggregate ): array {
	$hits = array();

	foreach ( $budget['zero_tolerance'] ?? array() as $source ) {
		$actual = $aggregate['sources'][ $source ] ?? 0;
		if ( $actual > 0 ) {
			$hits[] = sprintf( '%s: %d (must be 0)', $source, $actual );
		}
	}

	return $hits;
}

/**
 * Write the budget file from observed values.
 *
 * @param string $file       Budget path.
 * @param array  $budget     Current budget.
 * @param array  $aggregate  Observed values.
 * @param bool   $allow_up   Whether an increase is permitted.
 * @return never
 */
function phpcs_ratchet_write_budget( string $file, array $budget, array $aggregate, bool $allow_up ): never {
	$increases = array();

	foreach ( $budget['scopes'] as $index => $scope ) {
		$observed = $aggregate['scopes'][ $scope['name'] ];
		foreach ( array( 'errors', 'warnings' ) as $metric ) {
			if ( $observed[ $metric ] > $scope[ $metric ] ) {
				$increases[] = sprintf(
					'%s %s: %d -> %d',
					$scope['name'],
					$metric,
					$scope[ $metric ],
					$observed[ $metric ]
				);
			}
			$budget['scopes'][ $index ][ $metric ] = $observed[ $metric ];
		}
	}

	$source_budgets = isset( $budget['source_budgets'] ) && is_array( $budget['source_budgets'] )
		? $budget['source_budgets']
		: array();

	foreach ( $source_budgets as $source => $committed ) {
		$observed = $aggregate['sources'][ $source ] ?? 0;
		if ( $observed > (int) $committed ) {
			$increases[] = sprintf( '%s: %d -> %d', $source, (int) $committed, $observed );
		}
		$source_budgets[ $source ] = $observed;
	}

	ksort( $source_budgets );
	$budget['source_budgets'] = $source_budgets;
	$budget['measured_at']    = gmdate( 'Y-m-d' );

	if ( ! empty( $increases ) && ! $allow_up ) {
		fwrite( STDERR, "Refusing to raise the budget:\n  " . implode( "\n  ", $increases ) . "\n" );
		fwrite( STDERR, "Fix the findings, or re-run with --allow-increase and explain it in the PR.\n" );
		exit( 1 );
	}

	$json = json_encode( $budget, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		phpcs_ratchet_fail( 'Could not encode the budget as JSON.' );
	}

	file_put_contents( $file, $json . "\n" );

	foreach ( $increases as $line ) {
		echo "raised: {$line}\n";
	}
	echo "Budget written to {$file}\n";
	exit( 0 );
}

// ---------------------------------------------------------------------------
// Entry point.
// ---------------------------------------------------------------------------

$root        = phpcs_ratchet_root();
$budget_file = $root . '/.github/phpcs-budget.json';

$run_mode = 'check';
$allow_up = false;
$input    = '';
$standard = $root . '/phpcs.xml.dist';

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( '--check' === $arg ) {
		$run_mode = 'check';
	} elseif ( '--update' === $arg ) {
		$run_mode = 'update';
	} elseif ( '--allow-increase' === $arg ) {
		$allow_up = true;
	} elseif ( 0 === strpos( $arg, '--input=' ) ) {
		$input = substr( $arg, 8 );
	} elseif ( 0 === strpos( $arg, '--budget=' ) ) {
		$budget_file = substr( $arg, 9 );
	} elseif ( 0 === strpos( $arg, '--standard=' ) ) {
		$standard = substr( $arg, 11 );
	} else {
		phpcs_ratchet_fail( "Unknown argument: {$arg}" );
	}
}

$budget = phpcs_ratchet_load_budget( $budget_file );

/*
 * A handful of WPCS sniffs are order-sensitive and a few tokenise differently
 * across PHP minor versions, so two runs of the same tree can disagree by a
 * few warnings. `noise` is the allowance for that on the over-budget side;
 * it is not slack to spend, which is what `tolerance` measures on the other
 * side. Without it a platform difference of three warnings fails a PR that
 * changed nothing.
 */
$noise = isset( $budget['noise'] ) ? (int) $budget['noise'] : 0;

if ( '' !== $input ) {
	if ( ! is_readable( $input ) ) {
		phpcs_ratchet_fail( "Input report not found: {$input}" );
	}
	$report = phpcs_ratchet_parse_report( (string) file_get_contents( $input ) );
} else {
	$report = phpcs_ratchet_parse_report( phpcs_ratchet_run_phpcs( $root, $standard ) );
}

$aggregate = phpcs_ratchet_aggregate( $report, $root, $budget['scopes'] );
$zero_hits = phpcs_ratchet_zero_hits( $budget, $aggregate );

if ( 'update' === $run_mode ) {
	if ( ! empty( $zero_hits ) ) {
		fwrite( STDERR, "Refusing to update while a zero-tolerance source is not zero:\n  " . implode( "\n  ", $zero_hits ) . "\n" );
		exit( 1 );
	}
	phpcs_ratchet_write_budget( $budget_file, $budget, $aggregate, $allow_up );
}

$tolerance = $budget['tolerance'];
$failures  = array();
$stale     = array();

printf( "WPCS ratchet — %d file(s) with findings\n\n", $aggregate['files'] );
printf( "%-14s %-22s %-22s\n", 'scope', 'errors', 'warnings' );

foreach ( $budget['scopes'] as $scope ) {
	$name     = $scope['name'];
	$observed = $aggregate['scopes'][ $name ];

	foreach ( array( 'errors', 'warnings' ) as $metric ) {
		$actual    = $observed[ $metric ];
		$committed = $scope[ $metric ];

		if ( $actual > $committed + $noise ) {
			$failures[] = sprintf( '%s %s: %d (budget %d + noise %d)', $name, $metric, $actual, $committed, $noise );
		} elseif ( $committed - $actual > $tolerance ) {
			$stale[] = sprintf( '%s %s: %d (budget %d)', $name, $metric, $actual, $committed );
		}
	}

	printf(
		"%-14s %-22s %-22s\n",
		$name,
		sprintf( '%d / %d', $observed['errors'], $scope['errors'] ),
		sprintf( '%d / %d', $observed['warnings'], $scope['warnings'] )
	);
}

if ( ! empty( $budget['source_budgets'] ) ) {
	echo "\nNamed sources\n";
	foreach ( $budget['source_budgets'] as $source => $committed ) {
		$actual = $aggregate['sources'][ $source ] ?? 0;

		if ( $actual > (int) $committed + $noise ) {
			$failures[] = sprintf( '%s: %d (budget %d + noise %d)', $source, $actual, (int) $committed, $noise );
		} elseif ( (int) $committed - $actual > $tolerance ) {
			$stale[] = sprintf( '%s: %d (budget %d)', $source, $actual, (int) $committed );
		}

		printf( "  %-70s %d / %d\n", $source, $actual, (int) $committed );
	}
}

if ( ! empty( $budget['zero_tolerance'] ) ) {
	echo "\nZero tolerance\n";
	foreach ( $budget['zero_tolerance'] as $source ) {
		$actual = $aggregate['sources'][ $source ] ?? 0;
		printf( "  %-70s %d\n", $source, $actual );
	}
}

echo "\n";

if ( ! empty( $failures ) ) {
	echo "❌ Over budget:\n";
	foreach ( $failures as $line ) {
		echo "   - {$line}\n";
	}
	echo "\nRun `composer run lint` to see the findings, then `composer run format` for the auto-fixable ones.\n";
	exit( 1 );
}

if ( ! empty( $zero_hits ) ) {
	echo "❌ Zero-tolerance violations:\n";
	foreach ( $zero_hits as $line ) {
		echo "   - {$line}\n";
	}
	echo "\nA parse error or an already-satisfied gate rule is not debt; fix it rather than budgeting it.\n";
	exit( 1 );
}

if ( ! empty( $stale ) ) {
	echo "❌ Budget is stale — findings dropped by more than {$tolerance} without being recorded:\n";
	foreach ( $stale as $line ) {
		echo "   - {$line}\n";
	}
	echo "\nRun `php .github/scripts/phpcs-ratchet.php --update` and commit .github/phpcs-budget.json.\n";
	echo "A baseline without a ratchet is a suggestion; this is what locks the improvement in.\n";
	exit( 1 );
}

	echo "✅ Within budget, and no slack larger than {$tolerance}.\n";
exit( 0 );
