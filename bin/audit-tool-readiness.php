#!/usr/bin/env php
<?php
/**
 * Tool readiness auditor — Proposal 090-M.
 *
 * Reads the readiness manifest and every tool-contract class in `lib/core`,
 * then reports the three things that can go wrong when a shared tool package
 * meets a host that only serves some of it:
 *
 *   G1  a tool class exists but the manifest says nothing about it
 *   G2  the manifest names a class that is missing, a slug that disagrees with
 *       the class, or a readiness value outside the vocabulary
 *   O3  a *servable* core tool shares a slug with a WordPress tool — internal
 *       tool shadowing, where one definition displaces another silently
 *
 * A prompt-builder stand-in that returns `success` without doing the work is
 * the failure this exists to catch. Readiness has to be declared, because it
 * cannot be inferred: inheritance hides inherited constructors and inherited
 * `execute()`, and a property name (`$this->p`) hides which service a tool
 * really uses. Both were observed while writing this.
 *
 * Usage:
 *   php bin/audit-tool-readiness.php                # human report
 *   php bin/audit-tool-readiness.php --json         # machine-readable
 *   php bin/audit-tool-readiness.php --write        # add missing rows as `unverified`
 *   php bin/audit-tool-readiness.php --classify=map.json  # apply readiness decisions
 *   php bin/audit-tool-readiness.php --manifest=/tmp/x.php  # audit another manifest
 *   php bin/audit-tool-readiness.php --tools=/tmp/tools     # scan another tool tree
 *   php bin/audit-tool-readiness.php --report       # always exit 0 (CI report mode)
 *
 * `--write` never overwrites an existing decision: it only inserts rows for
 * classes the manifest has not seen, and it sorts by slug so the diff shows
 * exactly which tools appeared.
 *
 * Exit codes: 0 clean, 1 violations, 2 usage error.
 *
 * @package NV_oOS
 * @since   2.6.0
 */

use Nvoos\Core\Application\Tool\ToolClassScanner;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Domain\Contract\LlmSanitizerInterface;

$root = dirname( __DIR__ );

$options = array(
	'json'     => false,
	'write'    => false,
	'report'   => false,
	'classify' => '',
	'manifest' => '',
	'tools'    => '',
);

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( '--json' === $argument ) {
		$options['json'] = true;
	} elseif ( '--write' === $argument ) {
		$options['write'] = true;
	} elseif ( 0 === strpos( $argument, '--manifest=' ) ) {
		$options['manifest'] = substr( $argument, 11 );
	} elseif ( 0 === strpos( $argument, '--tools=' ) ) {
		$options['tools'] = substr( $argument, 8 );
	} elseif ( 0 === strpos( $argument, '--classify=' ) ) {
		$options['classify'] = substr( $argument, 11 );
	} elseif ( '--report' === $argument ) {
		$options['report'] = true;
	} elseif ( '--help' === $argument || '-h' === $argument ) {
		fwrite( STDOUT, "Usage: php bin/audit-tool-readiness.php [--json] [--write] [--report]\n" );
		exit( 0 );
	} else {
		fwrite( STDERR, "Unknown argument: {$argument}\n" );
		exit( 2 );
	}
}

require_once $root . '/lib/core/src/Application/Tool/ToolReadiness.php';

// Tool classes are resolved by FQCN during the integrity check, so the core
// autoloader has to be available. Fall back to a PSR-4 shim when the package
// has not been installed yet (`lib/core/vendor` is a dev dependency).
$core_autoload = $root . '/lib/core/vendor/autoload.php';
if ( is_file( $core_autoload ) ) {
	require_once $core_autoload;
} else {
	spl_autoload_register(
		static function ( string $class ) use ( $root ): void {
			if ( 0 !== strpos( $class, 'Nvoos\\Core\\' ) ) {
				return;
			}

			$relative = str_replace( '\\', '/', substr( $class, strlen( 'Nvoos\\Core\\' ) ) );
			$file     = $root . '/lib/core/src/' . $relative . '.php';

			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	);
}

$manifest_path = $root . '/lib/core/resources/tool-readiness.php';

if ( '' !== $options['manifest'] ) {
	$manifest_path = $options['manifest'];
}

$tools_path = $root . '/lib/core/src/Tool';

if ( '' !== $options['tools'] ) {
	$tools_path = $options['tools'];
}

/**
 * WordPress tool slugs, for the shadowing check.
 *
 * @param string $root Repository root.
 * @return array<string, string> Slug => file basename.
 */
function nvoos_audit_wordpress_slugs( string $root ): array {
	$slugs = array();

	foreach ( array( 'includes/tools', 'addons/pro/includes/tools' ) as $relative ) {
		$directory = $root . '/' . $relative;

		if ( ! is_dir( $directory ) ) {
			continue;
		}

		foreach ( glob( $directory . '/*.php' ) ?: array() as $file ) {
			$source = file_get_contents( $file );

			if ( preg_match_all( "/return\\s+['\"]([a-z0-9_]+)['\"]\\s*;/", $source, $matches ) ) {
				foreach ( $matches[1] as $slug ) {
					$slugs[ $slug ] = basename( $file );
				}
			}
		}
	}

	return $slugs;
}

/**
 * Serialise one manifest row.
 *
 * @param string              $slug Slug.
 * @param array<string,mixed> $row  Row.
 * @return string
 */
function nvoos_audit_row( string $slug, array $row ): string {
	$order = array( 'class', 'readiness', 'parity', 'probe', 'reason', 'replaced_by', 'evidence', 'annotations', 'since' );
	$lines = array( "\t\t" . var_export( $slug, true ) . ' => array(' );

	foreach ( $order as $key ) {
		if ( ! array_key_exists( $key, $row ) ) {
			continue;
		}

		$value = $row[ $key ];

		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $k => $v ) {
				$flat[] = var_export( $k, true ) . ' => ' . var_export( $v, true );
			}
			$rendered = 'array( ' . implode( ', ', $flat ) . ' )';
		} else {
			$rendered = var_export( $value, true );
		}

		$lines[] = "\t\t\t" . var_export( $key, true ) . ' => ' . $rendered . ',';
	}

	$lines[] = "\t\t),";

	return implode( "\n", $lines );
}

/**
 * Write the manifest in a stable, reviewable shape.
 *
 * @param string                            $path Manifest path.
 * @param array<string, array<string,mixed>> $rows Rows keyed by slug.
 * @return void
 */
function nvoos_audit_write( string $path, array $rows ): void {
	ksort( $rows );

	$directory = dirname( $path );
	if ( ! is_dir( $directory ) ) {
		mkdir( $directory, 0777, true );
	}

	$body = array();
	foreach ( $rows as $slug => $row ) {
		$body[] = nvoos_audit_row( (string) $slug, $row );
	}

	$header = <<<'PHP'
<?php
/**
 * Tool readiness manifest — Proposal 090-M.
 *
 * Generated and audited by `bin/audit-tool-readiness.php`; hand-classified
 * rows are the point of the file, so `--write` only ever *adds* a row for a
 * class the manifest has not seen. It never overwrites a decision.
 *
 * Readiness vocabulary (closed):
 *
 *   ready        real implementation, constructor satisfiable, evidence recorded
 *   pure         no I/O by nature; correctness is self-evident or unit-tested
 *   delegated    the client performs the work; server half only describes it
 *   unverified   constructible, but no evidence recorded — NOT served
 *   quarantined  describes work it does not perform — NOT served
 *   unavailable  needs a service this runtime does not bind — NOT served
 *
 * Only `ready`, `pure` and `delegated` are servable. The default for an
 * unknown slug is `unverified`, so failing to classify a tool cannot make it
 * callable.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

return array(
	'version' => 1,
	'tools'   => array(

PHP;

	file_put_contents( $path, $header . implode( "\n", $body ) . "\n\t),\n);\n" );
}

// ─── Scan ────────────────────────────────────────────────────────────────

$classes   = ( new ToolClassScanner() )->scan( $tools_path );
$wordpress = nvoos_audit_wordpress_slugs( $root );

$existing = array();
if ( file_exists( $manifest_path ) ) {
	$loaded = require $manifest_path;
	$existing = isset( $loaded['tools'] ) && is_array( $loaded['tools'] ) ? $loaded['tools'] : array();
}

$added = array();

if ( $options['write'] ) {
	foreach ( $classes as $entry ) {
		if ( '' === $entry['slug'] || isset( $existing[ $entry['slug'] ] ) ) {
			continue;
		}

		$existing[ $entry['slug'] ] = array(
			'class'     => $entry['fqcn'],
			'readiness' => ToolReadiness::UNVERIFIED,
			'reason'    => 'Auto-added by the auditor; not classified yet.',
		);
		$added[] = $entry['slug'];
	}

	if ( array() !== $added ) {
			nvoos_audit_write( $manifest_path, $existing );
		}
	}

	// `--classify` is the deliberate path: it overwrites decisions from a reviewed
	// JSON map. `--write` never does, so adding and deciding stay separable.
	if ( '' !== $options['classify'] ) {
		if ( ! is_file( $options['classify'] ) ) {
			fwrite( STDERR, "Classify map not found: {$options['classify']}\n" );
			exit( 2 );
		}

		$map = json_decode( (string) file_get_contents( $options['classify'] ), true );

		if ( ! is_array( $map ) ) {
			fwrite( STDERR, "Classify map is not a JSON object: {$options['classify']}\n" );
			exit( 2 );
		}

		foreach ( $map as $slug => $decision ) {
			if ( ! isset( $existing[ $slug ] ) ) {
				fwrite( STDERR, "classify: unknown slug '{$slug}' — run --write first.\n" );
				exit( 2 );
			}

			if ( ! is_array( $decision ) ) {
				fwrite( STDERR, "classify: '{$slug}' must map to an object.\n" );
				exit( 2 );
			}

			// A decision retires the placeholder reason; otherwise the manifest
			// keeps claiming the row is unclassified after it has been classified.
			if ( isset( $decision['readiness'] ) && 'Auto-added by the auditor; not classified yet.' === ( $existing[ $slug ]['reason'] ?? '' ) ) {
				unset( $existing[ $slug ]['reason'] );
			}
			$existing[ $slug ] = array_merge( $existing[ $slug ], $decision );
		}

		nvoos_audit_write( $manifest_path, $existing );
		fwrite( STDOUT, 'classified ' . count( $map ) . " row(s).\n" );

		// Authoring step: the gate runs on its own invocation, so a partially
		// classified manifest is not a failure of the edit that just happened.
		exit( 0 );
	}

// ─── Check ───────────────────────────────────────────────────────────────

$violations = array(
	'unclassified' => array(),
	'dangling'     => array(),
	'vocabulary'   => array(),
	'slug_mismatch' => array(),
	'shadowed'     => array(),
	'replacement'  => array(),
);

$decisions = array();

foreach ( $classes as $entry ) {
	if ( '' === $entry['slug'] ) {
		continue;
	}

	if ( ! isset( $existing[ $entry['slug'] ] ) ) {
		$violations['unclassified'][] = $entry['class'] . ' (' . $entry['slug'] . ')';
		continue;
	}

	$decisions[ $entry['slug'] ] = $existing[ $entry['slug'] ];
}

foreach ( $existing as $slug => $row ) {
	$readiness = (string) ( $row['readiness'] ?? '' );

	if ( ! ToolReadiness::isKnown( $readiness ) ) {
		$violations['vocabulary'][] = $slug . ' => ' . $readiness;
	}

	$probe = (string) ( $row['probe'] ?? '' );
	if ( '' !== $probe && ! in_array( $probe, array( 'call', 'validate_only', 'dry_run' ), true ) ) {
		$violations['vocabulary'][] = $slug . ' => probe \'' . $probe . '\' is outside the probe vocabulary';
	}

	// `replaced_by` names the canonical slug on the WordPress host, not a row
	// here. A target no host serves is a 404 for the reader who follows it, so
	// it must resolve against the WordPress corpus (or, for a future port, a
	// row in this manifest).
	$replaced_by = (string) ( $row['replaced_by'] ?? '' );

	if ( '' !== $replaced_by && ! isset( $wordpress[ $replaced_by ] ) && ! isset( $existing[ $replaced_by ] ) ) {
		$violations['replacement'][] = $slug . ' => replaced_by \'' . $replaced_by . '\' is not a WordPress tool or a manifest row';
	}

	$fqcn = (string) ( $row['class'] ?? '' );

	if ( '' === $fqcn || ! class_exists( $fqcn ) ) {
		$violations['dangling'][] = $slug . ' => ' . ( '' === $fqcn ? '(no class)' : $fqcn );
		continue;
	}

	$declared = '';
	foreach ( $classes as $entry ) {
		if ( $entry['fqcn'] === $fqcn ) {
			$declared = $entry['slug'];
			break;
		}
	}

	if ( '' !== $declared && $declared !== $slug ) {
		$violations['slug_mismatch'][] = $slug . ' => class declares ' . $declared;
	}

	if ( ToolReadiness::isServableClass( $readiness ) && isset( $wordpress[ $slug ] ) ) {
		// A collision is only a defect when the manifest has not recorded what
		// the collision means. `parity => 'port'` is a deliberate 090-D port of
		// the WordPress tool (same slug, same capability); a quarantined row
		// names the WordPress tool it defers to. Anything else is an
		// unacknowledged shadow and fails the gate.
		$parity = (string) ( $row['parity'] ?? '' );

		if ( ! in_array( $parity, array( 'port', 'equivalent' ), true ) ) {
			$violations['shadowed'][] = $slug . ' (WordPress: ' . $wordpress[ $slug ] . ', parity not declared)';
		}
	}
}

$counts = array();
foreach ( $existing as $row ) {
	$key = (string) ( $row['readiness'] ?? 'missing' );
	$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
}
ksort( $counts );

$servable = 0;
foreach ( $existing as $row ) {
	if ( ToolReadiness::isServableClass( (string) ( $row['readiness'] ?? '' ) ) ) {
		$servable++;
	}
}

$total_violations = array_sum( array_map( 'count', $violations ) );

// ─── Context contract: encoded payloads ──────────────────────────────────
//
// A `ready` tool whose result carries an encoded payload should keep it out of
// the model's context. The runtime backstop (`ToolResultSanitizer`) already drops
// these keys at any depth, so rows that *rely* on it are baselined rather than
// failing. The list can only shrink: remove an entry when the tool declares its
// own `sanitizeForLlm()`. A row outside the baseline is a warning, not a failure —
// the point is that a new one cannot appear silently.
$sanitizer_baseline = array(
	'create_image_variation',
	'edit_openai_image',
	'generate_cloudflareai_image',
	'generate_music',
	'generate_openai_image',
	'generate_openai_speech',
	'generate_openai_speech_validated',
);

$sanitizer = array(
	'baselined'  => array(),
	'undeclared' => array(),
);

foreach ( $existing as $slug => $row ) {
	if ( 'ready' !== (string) ( $row['readiness'] ?? '' ) ) {
		// `pure` rows such as `base64` encode on purpose; their payload is the
		// product, so they are exempt rather than baselined.
		continue;
	}

	$fqcn = (string) ( $row['class'] ?? '' );

	if ( '' === $fqcn || ! class_exists( $fqcn ) ) {
		continue;
	}

	try {
		$file = ( new ReflectionClass( $fqcn ) )->getFileName();
	} catch ( Throwable $e ) {
		continue;
	}

	if ( false === $file || ! is_file( $file ) ) {
		continue;
	}

	$source = (string) file_get_contents( $file );

	if ( ! preg_match( '/b64_json|audio_base64|image_base64|inline_data|inlineData/', $source ) ) {
		continue;
	}

	if ( is_subclass_of( $fqcn, LlmSanitizerInterface::class ) ) {
		continue;
	}

	if ( in_array( $slug, $sanitizer_baseline, true ) ) {
		$sanitizer['baselined'][] = $slug;
	} else {
		$sanitizer['undeclared'][] = $slug;
	}
}

$warnings = count( $sanitizer['undeclared'] );

if ( $options['json'] ) {
	fwrite(
		STDOUT,
		(string) json_encode(
			array(
				'classes'    => count( $classes ),
				'manifest'   => count( $existing ),
				'servable'   => $servable,
				'added'      => $added,
				'counts'     => $counts,
				'violations' => $violations,
				'sanitizer'  => $sanitizer,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
} else {
	fwrite( STDOUT, "tool-contract classes : " . count( $classes ) . "\n" );
	fwrite( STDOUT, "manifest rows         : " . count( $existing ) . "\n" );
	fwrite( STDOUT, "servable              : " . $servable . "\n" );

	foreach ( $counts as $readiness => $count ) {
		fwrite( STDOUT, '  ' . str_pad( $readiness, 14 ) . $count . "\n" );
	}

	if ( array() !== $added ) {
		fwrite( STDOUT, "\nadded as unverified:\n" );
		foreach ( $added as $slug ) {
			fwrite( STDOUT, '  - ' . $slug . "\n" );
		}
	}

	$labels = array(
		'unclassified'  => 'G1 unclassified classes',
		'dangling'      => 'G2 manifest rows with no class',
		'vocabulary'    => 'G2 readiness outside the vocabulary',
		'slug_mismatch' => 'G2 manifest slug disagrees with the class',
		'shadowed'      => 'O3 servable core tool shadows a WordPress slug without a parity claim',
		'replacement'   => 'G2 replaced_by names a slug no host serves',
	);

	foreach ( $labels as $key => $label ) {
		if ( array() === $violations[ $key ] ) {
			continue;
		}

		fwrite( STDOUT, "\n" . $label . " (" . count( $violations[ $key ] ) . "):\n" );
		foreach ( $violations[ $key ] as $line ) {
			fwrite( STDOUT, '  - ' . $line . "\n" );
		}
	}

	fwrite( STDOUT, "\n" . ( 0 === $total_violations ? "OK — no violations.\n" : "FAIL — {$total_violations} violation(s).\n" ) );

	if ( array() !== $sanitizer['baselined'] ) {
		fwrite(
			STDOUT,
			'context contract      : ' . count( $sanitizer['baselined'] ) . " row(s) return an encoded payload and rely on the runtime backstop.\n"
		);
	}

	if ( 0 !== $warnings ) {
		fwrite( STDOUT, "\nG6 encoded payload with no sanitizer (warning, does not fail the gate):\n" );
		foreach ( $sanitizer['undeclared'] as $slug ) {
			fwrite( STDOUT, '  - ' . $slug . "\n" );
		}
	}
}

if ( 0 === $total_violations || $options['report'] ) {
	exit( 0 );
}

exit( 1 );
