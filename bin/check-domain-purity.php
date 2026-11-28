<?php
/**
 * Domain-purity gate for the ADR 001 module boundaries.
 *
 * Fails when a file in a domain-layer directory references a WordPress API, a
 * WordPress core class, a database global, or filesystem/network I/O. Comments
 * and string literals are ignored so documentation prose cannot produce false
 * positives.
 *
 * Layer policy (see docs/project/architecture-decisions/ADR_001_module_boundaries.md):
 *
 *   includes/domain/        strict    — zero WordPress, zero I/O, PHP stdlib only
 *   includes/application/   strict    — use-case layer; must use injected interfaces
 *   includes/services/      advisory  — transitional; violations are reported only
 *   includes/repositories/  advisory  — transitional; violations are reported only
 *
 *   addons/nvoos-wifi/includes/Domain/      strict
 *   addons/nvoos-wifi/includes/Services/    strict
 *   addons/nvoos-wifi/includes/Interfaces/  strict
 *
 * The WiFi addon (Proposal 150) is built to ADR 001's *target* layering rather
 * than migrated toward it, so its three portable layers are strict from the
 * first commit. That is what makes its Laravel host possible; if these turn
 * advisory the portability claim becomes unenforced and, in practice, false.
 *
 * Missing directories are skipped, so this gate is safe to run before the
 * application/ rename described in ADR 001 lands.
 *
 * Usage:
 *   php bin/check-domain-purity.php             Strict + advisory report, exits 1 on strict failures.
 *   php bin/check-domain-purity.php --strict    Exits 1 on strict OR advisory failures.
 *   php bin/check-domain-purity.php --report    Never fails; prints findings only.
 *   php bin/check-domain-purity.php --only=addons/nvoos-wifi
 *                                               Restrict the scan to layers whose path
 *                                               contains the filter. A filter that matches
 *                                               no layer exits 1 rather than passing.
 *   php bin/check-domain-purity.php --help      Show this help.
 *
 * @package WP_MCP_AI
 * @since   2.7.0
 */

declare( strict_types=1 );

/**
 * PHP functions that put a class in the WordPress, database or I/O layer.
 *
 * Matched case-insensitively against T_STRING tokens that are not member or
 * static calls, so `$this->get_option()` does not trip the gate.
 */
const WP_MCP_AI_DOMAIN_PURITY_FUNCTIONS = array(
	// WordPress options / settings.
	'get_option',
	'add_option',
	'update_option',
	'delete_option',
	'get_site_option',
	'update_site_option',
	'delete_site_option',
	// WordPress transients and caching.
	'get_transient',
	'set_transient',
	'delete_transient',
	'wp_cache_get',
	'wp_cache_set',
	// WordPress capabilities and hooks.
	'current_user_can',
	'user_can',
	'is_user_logged_in',
	'add_action',
	'add_filter',
	'remove_action',
	'remove_filter',
	'do_action',
	'apply_filters',
	'has_action',
	'has_filter',
	// WordPress HTTP and JSON helpers.
	'wp_remote_get',
	'wp_remote_post',
	'wp_remote_request',
	'wp_safe_remote_get',
	'wp_safe_remote_post',
	'wp_json_encode',
	'wp_json_decode',
	// WordPress content APIs.
	'wp_insert_post',
	'wp_update_post',
	'wp_delete_post',
	'get_post',
	'get_posts',
	'get_user_by',
	'wp_query',
	// Filesystem I/O.
	'file_get_contents',
	'file_put_contents',
	'fopen',
	'fwrite',
	'fread',
	'unlink',
	// Network I/O.
	'curl_init',
	'curl_exec',
	'fsockopen',
	'stream_socket_client',
);

/**
 * WordPress core classes that must not be imported or extended by a domain class.
 *
 * NV oOS classes intentionally carry a WP_MCP_AI_ prefix, so a bare `/^WP_/`
 * match would flag every domain class. Only genuine WordPress core symbols are
 * listed.
 */
const WP_MCP_AI_DOMAIN_PURITY_CLASSES = array(
	'WP_Error',
	'WP_Query',
	'WP_Post',
	'WP_User',
	'WP_Role',
	'WP_REST_Request',
	'WP_REST_Response',
	'WP_REST_Server',
	'WP_List_Table',
	'WP_Http',
	'wpdb',
);

/**
 * Collect forbidden references from a single PHP source string.
 *
 * @param string $source PHP source code, without the opening tag restrictions.
 * @return array<int, array{line:int, symbol:string, kind:string}> Findings.
 */
function wp_mcp_ai_domain_purity_scan_source( string $source ): array {
	$findings  = array();
	$tokens    = token_get_all( $source );
	$functions = array_flip( WP_MCP_AI_DOMAIN_PURITY_FUNCTIONS );
	$classes   = array_flip( array_map( 'strtolower', WP_MCP_AI_DOMAIN_PURITY_CLASSES ) );

	// Member-access operators mean the following T_STRING is a method name.
	$member_operators = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON );
	if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
		$member_operators[] = T_NULLSAFE_OBJECT_OPERATOR;
	}

	$previous_significant = null;

	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			// Skip comments and plain string literals entirely.
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
				continue;
			}

			if ( T_STRING === $token[0] ) {
				$lower = strtolower( $token[1] );
				$is_member = is_int( $previous_significant )
					|| ( is_array( $previous_significant ) && in_array( $previous_significant[0], $member_operators, true ) );

				if ( ! $is_member ) {
					if ( isset( $functions[ $lower ] ) ) {
						$findings[] = array(
							'line'   => (int) $token[2],
							'symbol' => $token[1],
							'kind'   => 'function',
						);
					} elseif ( isset( $classes[ $lower ] ) ) {
						$findings[] = array(
							'line'   => (int) $token[2],
							'symbol' => $token[1],
							'kind'   => 'class',
						);
					}
				}
			}

			if ( T_VARIABLE === $token[0] && '$wpdb' === $token[1] ) {
				$findings[] = array(
					'line'   => (int) $token[2],
					'symbol' => '$wpdb',
					'kind'   => 'global',
				);
			}

			$previous_significant = $token;
			continue;
		}

		$previous_significant = $token;
	}

	return $findings;
}

/**
 * Recursively list PHP files beneath a directory.
 *
 * @param string $directory Absolute directory path.
 * @return array<int, string> Absolute file paths.
 */
function wp_mcp_ai_domain_purity_files( string $directory ): array {
	if ( ! is_dir( $directory ) ) {
		return array();
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
	);

	$files = array();
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$files[] = $file->getPathname();
	}

	sort( $files );

	return $files;
}

/**
 * Render a path relative to the repository root with forward slashes.
 *
 * @param string $root Repository root.
 * @param string $path Absolute path.
 * @return string Relative path.
 */
function wp_mcp_ai_domain_purity_relative( string $root, string $path ): string {
	$prefix = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
	$path   = str_replace( '\\', '/', $path );

	return str_starts_with( $path, $prefix ) ? substr( $path, strlen( $prefix ) ) : $path;
}

// ---------------------------------------------------------------------------
// Entry point.
// ---------------------------------------------------------------------------

$arguments = array_slice( $argv, 1 );

if ( in_array( '--help', $arguments, true ) || in_array( '-h', $arguments, true ) ) {
	echo "Domain-purity gate for ADR 001 module boundaries.\n\n";
	echo "Usage: php bin/check-domain-purity.php [--strict] [--report] [--only=<path>]\n\n";
	echo "  --strict   Exit 1 on advisory findings as well as strict findings.\n";
	echo "  --report   Print findings but always exit 0.\n";
	echo "  --verbose  Print every advisory finding (default caps at 15 per layer).\n";
	echo "  --only=.   Restrict the scan to layers whose path contains <path>.\n";
	exit( 0 );
}

$strict_mode = in_array( '--strict', $arguments, true );
$report_only = in_array( '--report', $arguments, true );
$verbose     = in_array( '--verbose', $arguments, true );

/** Optional layer filter, e.g. `addons/nvoos-wifi`. */
$only = null;

foreach ( $arguments as $argument ) {
	if ( 0 === strpos( $argument, '--only=' ) ) {
		$only = substr( $argument, 7 );
	}
}

/** Maximum advisory findings printed per layer unless --verbose is passed. */
$advisory_cap = 15;

$advisory_printed = array();
$advisory_totals  = array();

$root = dirname( __DIR__ );

$layers = array(
	'includes/domain'                       => 'strict',
	'includes/application'                  => 'strict',
	'includes/services'                     => 'advisory',
	'includes/repositories'                 => 'advisory',
	// Proposal 150 — NV oOS WiFi. Portable layers, strict from day one.
		'addons/nvoos-wifi/includes/Domain'     => 'strict',
		'addons/nvoos-wifi/includes/Services'   => 'strict',
		'addons/nvoos-wifi/includes/Interfaces' => 'strict',
		// Proposal 167 — database toolkit. The portable kernel must stay free of
		// WordPress symbols and I/O. D15 moved it out of lib/core and into the Pro
		// add-on (addons/pro/lib/database) so a base+pro install — which has no lib/
		// directory at all — can run it. The layer stays strict: the kernel is the
		// one place a scope decision is made, so it must not acquire a host.
		'addons/pro/lib/database/src'           => 'strict',
	);

// Restrict to a subset of layers when asked (CI runs a focused addon job so a
// pre-existing violation in an unrelated layer cannot fail it). A filter that
// matches nothing is a hard error: a job that silently scans zero layers looks
// identical to a passing job, which is worse than having no job at all.
if ( null !== $only ) {
	$filtered = array();

	foreach ( $layers as $layer => $severity ) {
		if ( false !== strpos( $layer, $only ) ) {
			$filtered[ $layer ] = $severity;
		}
	}

	if ( array() === $filtered ) {
		fwrite(
			STDERR,
			sprintf( "✗ --only=\"%s\" matched no layers — check the path.\n", $only )
		);
		exit( 1 );
	}

	$layers = $filtered;
}

$strict_findings   = 0;
$advisory_findings = 0;
$scanned_files     = 0;
$skipped_layers    = array();

foreach ( $layers as $relative_layer => $severity ) {
	$directory = $root . '/' . $relative_layer;

	if ( ! is_dir( $directory ) ) {
		$skipped_layers[] = $relative_layer;
		continue;
	}

	$files = wp_mcp_ai_domain_purity_files( $directory );

	if ( array() === $files ) {
		$skipped_layers[] = $relative_layer;
		continue;
	}

	foreach ( $files as $file ) {
		++$scanned_files;
		$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI gate reading local repository source.
		if ( false === $source ) {
			continue;
		}

		$findings = wp_mcp_ai_domain_purity_scan_source( $source );

		foreach ( $findings as $finding ) {
			$location = wp_mcp_ai_domain_purity_relative( $root, $file ) . ':' . $finding['line'];
			$message  = sprintf(
				'%s  %s(%s)  [%s layer]',
				$location,
				$finding['symbol'],
				$finding['kind'],
				$severity
			);

			if ( 'strict' === $severity ) {
				++$strict_findings;
				fwrite( STDERR, "✗ {$message}\n" );
			} else {
				++$advisory_findings;
				$advisory_totals[ $relative_layer ] = ( $advisory_totals[ $relative_layer ] ?? 0 ) + 1;
				$printed                          = $advisory_printed[ $relative_layer ] ?? 0;
				if ( $verbose || $printed < $advisory_cap ) {
					echo "! {$message}\n";
					$advisory_printed[ $relative_layer ] = $printed + 1;
				}
			}
		}
	}
}

if ( array() !== $skipped_layers ) {
	echo sprintf(
		"· skipped (absent or empty): %s\n",
		implode( ', ', $skipped_layers )
	);
}

if ( 0 === $strict_findings ) {
	printf( "✓ domain purity: %d file(s) scanned, 0 strict violations\n", $scanned_files );
} else {
	fwrite(
		STDERR,
		sprintf( "✗ domain purity: %d strict violation(s) in %d file(s)\n", $strict_findings, $scanned_files )
	);
}

if ( $advisory_findings > 0 ) {
	printf(
		"· %d advisory finding(s) in transitional layers (ADR 001 target: 0 after migration)\n",
		$advisory_findings
	);

	if ( ! $verbose ) {
		foreach ( $advisory_printed as $layer => $printed ) {
			$total = $advisory_totals[ $layer ] ?? 0;
			if ( $total > $printed ) {
				printf(
					"  … %d more finding(s) suppressed in %s (use --verbose)\n",
					$total - $printed,
					$layer
				);
			}
		}
	}
}

if ( $report_only ) {
	exit( 0 );
}

if ( $strict_findings > 0 ) {
	exit( 1 );
}

if ( $strict_mode && $advisory_findings > 0 ) {
	exit( 1 );
}

exit( 0 );
