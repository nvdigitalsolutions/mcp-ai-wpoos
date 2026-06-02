<?php
/**
 * Folder README compliance checker.
 *
 * Enforces the per-folder `README.md` convention introduced by Phase P7 of the
 * Unix Theory Compliance Enhancement Proposal:
 *
 *   - Every immediate subdirectory of `includes/` (and optionally
 *     `addons/pro/includes/`) that contains at least one PHP file must ship a
 *     `README.md`.
 *   - That README must include the seven required H2 sections defined by the
 *     canonical template at `.context/templates/folder-readme-template.md`.
 *   - The README must NOT restate naming, security, or PHP-compat rules
 *     (those are the layering-rule "forbidden" markers — `AGENTS.md` §2).
 *
 * This script is intentionally dependency-free and runs under PHP 7.4+ so it
 * can execute in CI before Composer install completes if needed.
 *
 * Exit codes:
 *   0 — no issues
 *   1 — at least one missing-README error or missing-required-section error
 *   2 — at least one drift warning (only with --strict)
 *
 * Usage:
 *   php bin/check-folder-readmes.php                  # base only, warnings non-fatal
 *   php bin/check-folder-readmes.php --scope=base     # explicit base
 *   php bin/check-folder-readmes.php --scope=pro      # pro only
 *   php bin/check-folder-readmes.php --scope=all      # base + pro
 *   php bin/check-folder-readmes.php --strict         # exit 2 on drift warnings
 *   php bin/check-folder-readmes.php --json           # machine-readable output
 *
 * @package WP_MCP_AI
 * @since   1.x.x
 * @link    docs/developer/folder-readme-convention.md
 * @link    docs/project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md
 */

declare( strict_types=1 );

namespace WP_MCP_AI\Bin\CheckFolderReadmes;

/* -------------------------------------------------------------------------- */
/* Configuration                                                              */
/* -------------------------------------------------------------------------- */

/** Required H2 sections (case-insensitive, matched after stripping prefixes). */
const REQUIRED_SECTIONS = array(
	'purpose',
	'tier',
	'public surface',
	'inputs / outputs / neighbors',
	'conventions',
	'tests',
	'also load',
);

/**
 * Folders inside `includes/` that are exempt from the README requirement.
 *
 * Add a folder here only if it is purely a data directory (e.g., generated
 * fixtures) where a README is genuinely redundant. Prefer writing the README.
 */
const EXEMPT_FOLDERS = array(
	// none yet — every includes/ subfolder should have a README.
);

/**
 * Drift heuristics — phrases that, if present in a folder README, indicate the
 * author restated cross-cutting concerns instead of linking to the canonical
 * source. These are warnings, not errors, because some folders have legitimate
 * reasons to mention these words in context.
 */
const DRIFT_PATTERNS = array(
	'/sanitize_text_field\s*\(/i'      => 'mentions specific sanitiser — link to .context/security-checklist.md instead',
	'/current_user_can\s*\(/i'         => 'mentions capability check — link to .context/security-checklist.md instead',
	'/PHP\s*7\.4\s*\+|PHP\s*8\.1\s*\+/' => 'restates PHP compat — link to CLAUDE.md instead (use Tier table only)',
	'/WP_MCP_AI_\{[A-Z]/'              => 'restates class-naming pattern — link to .context/conventions.md instead',
);

/* -------------------------------------------------------------------------- */
/* CLI argument parsing                                                       */
/* -------------------------------------------------------------------------- */

$opts        = parse_cli_args( $argv );
$scope       = $opts['scope'];
$strict      = $opts['strict'];
$as_json     = $opts['json'];
$plugin_root = realpath( __DIR__ . '/..' );

if ( false === $plugin_root ) {
	fwrite( STDERR, "Unable to resolve plugin root.\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------- */
/* Scope resolution                                                           */
/* -------------------------------------------------------------------------- */

$roots = array();
if ( 'base' === $scope || 'all' === $scope ) {
	$roots[] = $plugin_root . '/includes';
}
if ( 'pro' === $scope || 'all' === $scope ) {
	$pro_root = $plugin_root . '/addons/pro/includes';
	if ( is_dir( $pro_root ) ) {
		$roots[] = $pro_root;
	}
}

/* -------------------------------------------------------------------------- */
/* Walk and check                                                             */
/* -------------------------------------------------------------------------- */

$errors   = array();
$warnings = array();
$ok_count = 0;

foreach ( $roots as $root ) {
	foreach ( walk_immediate_dirs( $root ) as $dir ) {
		$rel = relative_path( $plugin_root, $dir );

		if ( in_array( basename( $dir ), EXEMPT_FOLDERS, true ) ) {
			continue;
		}

		if ( ! folder_has_php( $dir ) ) {
			continue;
		}

		$readme = $dir . '/README.md';
		if ( ! is_file( $readme ) ) {
			$errors[] = array(
				'level'  => 'error',
				'folder' => $rel,
				'code'   => 'missing-readme',
				'msg'    => 'Folder contains PHP files but has no README.md. ' .
					'Copy `.context/templates/folder-readme-template.md` and fill it in.',
			);
			continue;
		}

		$content = file_get_contents( $readme );
		if ( false === $content ) {
			$errors[] = array(
				'level'  => 'error',
				'folder' => $rel,
				'code'   => 'unreadable-readme',
				'msg'    => 'README.md exists but could not be read.',
			);
			continue;
		}

		$missing_sections = find_missing_sections( $content );
		if ( ! empty( $missing_sections ) ) {
			$errors[] = array(
				'level'    => 'error',
				'folder'   => $rel,
				'code'     => 'missing-sections',
				'msg'      => 'README is missing required H2 section(s): ' .
					implode( ', ', $missing_sections ),
				'sections' => $missing_sections,
			);
			continue;
		}

		$drift = find_drift( $content );
		if ( ! empty( $drift ) ) {
			foreach ( $drift as $pattern_msg ) {
				$warnings[] = array(
					'level'  => 'warning',
					'folder' => $rel,
					'code'   => 'drift',
					'msg'    => $pattern_msg,
				);
			}
		}

		++$ok_count;
	}
}

/* -------------------------------------------------------------------------- */
/* Report                                                                     */
/* -------------------------------------------------------------------------- */

if ( $as_json ) {
	$payload = array(
		'scope'      => $scope,
		'ok'         => $ok_count,
		'errors'     => $errors,
		'warnings'   => $warnings,
		'strict'     => $strict,
		'plugin_root'=> $plugin_root,
	);
	echo wp_safe_json_encode( $payload ) . "\n";
} else {
	render_text_report( $scope, $ok_count, $errors, $warnings );
}

/* -------------------------------------------------------------------------- */
/* Exit                                                                       */
/* -------------------------------------------------------------------------- */

if ( ! empty( $errors ) ) {
	exit( 1 );
}
if ( $strict && ! empty( $warnings ) ) {
	exit( 2 );
}
exit( 0 );

/* -------------------------------------------------------------------------- */
/* Functions                                                                  */
/* -------------------------------------------------------------------------- */

/**
 * Parse CLI arguments.
 *
 * @param array<int,string> $argv Raw argv.
 * @return array{scope:string,strict:bool,json:bool}
 */
function parse_cli_args( array $argv ): array {
	$out = array(
		'scope'  => 'base',
		'strict' => false,
		'json'   => false,
	);
	foreach ( $argv as $arg ) {
		if ( 0 === strpos( $arg, '--scope=' ) ) {
			$val = substr( $arg, strlen( '--scope=' ) );
			if ( in_array( $val, array( 'base', 'pro', 'all' ), true ) ) {
				$out['scope'] = $val;
			}
		} elseif ( '--strict' === $arg ) {
			$out['strict'] = true;
		} elseif ( '--json' === $arg ) {
			$out['json'] = true;
		} elseif ( '--help' === $arg || '-h' === $arg ) {
			echo "Usage: php bin/check-folder-readmes.php [--scope=base|pro|all] [--strict] [--json]\n";
			exit( 0 );
		}
	}
	return $out;
}

/**
 * Yield each immediate subdirectory of $root.
 *
 * @param string $root Absolute path.
 * @return iterable<string>
 */
function walk_immediate_dirs( string $root ): iterable {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$entries = scandir( $root );
	if ( false === $entries ) {
		return;
	}
	sort( $entries );
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$full = $root . DIRECTORY_SEPARATOR . $entry;
		if ( is_dir( $full ) ) {
			yield $full;
		}
	}
}

/**
 * Check whether a directory contains at least one PHP file (recursively).
 *
 * We recurse because folders like `includes/tools/orchestration/` count toward
 * the parent `tools/` PHP-presence check, but the parent still needs its own
 * README. The README requirement is per-immediate-subdirectory only.
 *
 * @param string $dir Absolute path.
 * @return bool
 */
function folder_has_php( string $dir ): bool {
	$it = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( $file->isFile() && strtolower( $file->getExtension() ) === 'php' ) {
			return true;
		}
	}
	return false;
}

/**
 * Find missing required H2 sections in a README's content.
 *
 * Matches any `## ...` heading whose normalized text equals one of the
 * required-section keywords.
 *
 * @param string $content README markdown body.
 * @return array<int,string> Missing section names (lower-case, canonical form).
 */
function find_missing_sections( string $content ): array {
	$found = array();
	if ( preg_match_all( '/^##\s+([^\n]+)$/mu', $content, $matches ) ) {
		foreach ( $matches[1] as $heading ) {
			$normalized = strtolower( trim( preg_replace( '/[#*_`]/u', '', $heading ) ) );
			$found[]    = $normalized;
		}
	}
	$missing = array();
	foreach ( REQUIRED_SECTIONS as $section ) {
		$matched = false;
		foreach ( $found as $heading ) {
			if ( $heading === $section || str_starts_with_polyfill( $heading, $section ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			$missing[] = $section;
		}
	}
	return $missing;
}

/**
 * Find drift markers — phrases that restate cross-cutting concerns.
 *
 * @param string $content README markdown body.
 * @return array<int,string> Human-readable warning messages.
 */
function find_drift( string $content ): array {
	$hits = array();
	foreach ( DRIFT_PATTERNS as $pattern => $message ) {
		if ( preg_match( $pattern, $content ) ) {
			$hits[] = $message;
		}
	}
	return $hits;
}

/**
 * Compute path of $target relative to $root.
 *
 * @param string $root   Absolute path.
 * @param string $target Absolute path inside $root.
 * @return string Relative path with forward slashes.
 */
function relative_path( string $root, string $target ): string {
	$root   = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
	$target = str_replace( '\\', '/', $target );
	if ( 0 === strpos( $target, $root ) ) {
		return substr( $target, strlen( $root ) );
	}
	return $target;
}

/**
 * Polyfill for PHP 8.0 `str_starts_with()` since this script must run on 7.4+.
 *
 * @param string $haystack Source string.
 * @param string $needle   Prefix to test.
 * @return bool
 */
function str_starts_with_polyfill( string $haystack, string $needle ): bool {
	if ( '' === $needle ) {
		return true;
	}
	return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
}

/**
 * JSON-encode a payload, falling back to `json_encode` since this script runs
 * outside WordPress and `wp_json_encode` is unavailable.
 *
 * @param mixed $data Anything json_encode can handle.
 * @return string
 */
function wp_safe_json_encode( $data ): string {
	$encoded = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	return false === $encoded ? '{}' : $encoded;
}

/**
 * Print a human-readable report to stdout.
 *
 * @param string $scope    Scope name.
 * @param int    $ok_count Number of OK folders.
 * @param array  $errors   List of error records.
 * @param array  $warnings List of warning records.
 * @return void
 */
function render_text_report( string $scope, int $ok_count, array $errors, array $warnings ): void {
	echo "NV oOS — Folder README compliance check\n";
	echo str_repeat( '=', 60 ) . "\n";
	echo "Scope:    {$scope}\n";
	echo 'OK:       ' . $ok_count . " folder(s)\n";
	echo 'Errors:   ' . count( $errors ) . "\n";
	echo 'Warnings: ' . count( $warnings ) . "\n";
	echo str_repeat( '=', 60 ) . "\n\n";

	if ( ! empty( $errors ) ) {
		echo "ERRORS\n";
		echo str_repeat( '-', 60 ) . "\n";
		foreach ( $errors as $err ) {
			echo "  [{$err['code']}] {$err['folder']}\n";
			echo "      {$err['msg']}\n\n";
		}
	}

	if ( ! empty( $warnings ) ) {
		echo "WARNINGS (drift — link to canonical source instead of restating)\n";
		echo str_repeat( '-', 60 ) . "\n";
		foreach ( $warnings as $warn ) {
			echo "  [{$warn['code']}] {$warn['folder']}\n";
			echo "      {$warn['msg']}\n\n";
		}
	}

	if ( empty( $errors ) && empty( $warnings ) ) {
		echo "All folders compliant. ✓\n";
	}
}
