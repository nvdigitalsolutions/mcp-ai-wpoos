#!/usr/bin/env php
<?php
/**
 * Tool portability classifier — Proposal 090-D.
 *
 * Inventories every tool slug in the WordPress plugin (base + Pro), diffs it
 * against `lib/core`, and classifies each missing tool by the WordPress
 * coupling of its **dependency closure** (the tool file, its parent class and
 * the files it `require_once`s). Scoring only the tool file undercounts badly:
 * the F&B and GTmetrix families look dependency-free until you notice their
 * base class does all the WordPress work.
 *
 * Usage:
 *   php bin/classify-tool-portability.php                 # summary + tiers
 *   php bin/classify-tool-portability.php --tier=A        # list tier A
 *   php bin/classify-tool-portability.php --tier=B --limit=50
 *   php bin/classify-tool-portability.php --match=jet_engine,get_post
 *   php bin/classify-tool-portability.php --json          # machine-readable
 *
 * Tiers (hard references in the closure):
 *   A  0        portable as-is (pure PHP + i18n)
 *   B  1-3      one adapter (capability check, settings read, plugin helper)
 *   C  4-10     small cluster port (HTTP client, filesystem, store)
 *   D  11-30    WordPress-shaped; port only with a strong reason
 *   E  31+      WordPress-bound; stays in the plugin
 *
 * `WP_Error` is deliberately *not* a hard reference: `lib/core` ships an
 * ErrorFactory and the canonical envelope, so it is a mechanical swap.
 *
 * @package NV_oOS
 * @since   2.6.0
 */

$root = dirname( __DIR__ );

$options = array(
	'tier'  => '',
	'limit' => 0,
	'match' => '',
	'json'  => false,
);

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( 0 === strpos( $argument, '--tier=' ) ) {
		$options['tier'] = strtoupper( substr( $argument, 7 ) );
	} elseif ( 0 === strpos( $argument, '--limit=' ) ) {
		$options['limit'] = (int) substr( $argument, 8 );
	} elseif ( 0 === strpos( $argument, '--match=' ) ) {
		$options['match'] = substr( $argument, 8 );
	} elseif ( '--json' === $argument ) {
		$options['json'] = true;
	} else {
		fwrite( STDERR, "Unknown option: {$argument}\n" );
		exit( 1 );
	}
}

/**
 * Find tool slugs and the file that declares them.
 *
 * @param string      $dir      Directory to scan.
 * @param string|null $filename Optional filename pattern.
 * @return array<string, string> Slug => absolute path.
 */
function nvoos_slugs_from( string $dir, ?string $filename = null, ?string $implements = null ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$slugs    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}

		if ( null !== $filename && ! preg_match( $filename, $file->getFilename() ) ) {
			continue;
		}

		$code = (string) file_get_contents( $file->getPathname() );

		if ( null !== $implements && ! nvoos_class_implements( $code, $implements ) ) {
			continue;
		}

		if ( preg_match_all( '/function\s+get_?[sS]lug\s*\([^)]*\)[^{;]*\{\s*return\s+\'([^\']+)\'/s', $code, $matches ) ) {
			foreach ( $matches[1] as $slug ) {
				$slugs[ $slug ] = $file->getPathname();
			}
		}
	}

	return $slugs;
}

/**
 * Does a file declare a class that implements an interface?
 *
 * Deliberately line-oriented: every tool in this repository declares its
 * `implements` list on the class line. A declaration split across lines is
 * reported as "no" rather than guessed at, which under-includes instead of
 * inventing a tool.
 *
 * @param string $code       File contents.
 * @param string $interface  Interface name to look for.
 */
function nvoos_class_implements( string $code, string $interface ): bool {
	return 1 === preg_match(
		'/^[ \t]*(?:final[ \t]+|abstract[ \t]+)*class[ \t]+\w+[^\n]*\bimplements\b[^\n]*\b' . preg_quote( $interface, '/' ) . '\b/m',
		$code
	);
}

/**
 * Index declared classes so `extends` can be resolved to a file.
 *
 * @param array<int, string> $directories Directories to scan.
 * @return array<string, string> Class name => absolute path.
 */
function nvoos_class_index( array $directories ): array {
	$index = array();

	foreach ( $directories as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$code = (string) file_get_contents( $file->getPathname() );

			if ( preg_match_all( '/^\s*(?:abstract\s+|final\s+)?class\s+([A-Za-z0-9_]+)/m', $code, $matches ) ) {
				foreach ( $matches[1] as $class ) {
					$index[ $class ] = $file->getPathname();
				}
			}
		}
	}

	return $index;
}

/**
 * Walk `extends` and sibling `require_once` references, capped.
 *
 * @param string                $file    Starting file.
 * @param array<string, string> $classes Class index.
 * @return array<int, string> Absolute paths in the closure.
 */
function nvoos_closure( string $file, array $classes ): array {
	$seen  = array();
	$queue = array( $file );

	while ( $queue && count( $seen ) < 25 ) {
		$current = array_shift( $queue );

		if ( isset( $seen[ $current ] ) || ! is_file( $current ) ) {
			continue;
		}

		$seen[ $current ] = true;
		$code             = (string) file_get_contents( $current );

		if ( preg_match( '/class\s+[A-Za-z0-9_]+\s+extends\s+([A-Za-z0-9_]+)/', $code, $match ) && isset( $classes[ $match[1] ] ) ) {
			$queue[] = $classes[ $match[1] ];
		}

		if ( preg_match_all( '/require_once\s+__DIR__\s*\.\s*\'([^\']+)\'/', $code, $matches ) ) {
			foreach ( $matches[1] as $relative ) {
				$queue[] = dirname( $current ) . $relative;
			}
		}
	}

	return array_keys( $seen );
}

$hard_references = array(
	'$wpdb', 'WP_Query', 'get_post', 'wp_insert_post', 'wp_update_post', 'get_posts',
	'update_post_meta', 'get_post_meta', 'delete_post_meta', 'get_option', 'update_option',
	'current_user_can', 'get_current_user_id', 'wp_get_current_user', 'wp_set_current_user',
	'wp_remote_', 'wp_safe_remote_', 'download_url', 'wp_upload_dir', 'wp_handle_upload',
	'wp_generate_attachment_metadata', 'get_attached_file', 'wp_get_attachment',
	'wp_insert_attachment', 'wp_mkdir_p', 'WP_Filesystem', 'wp_tempnam',
	'wc_', 'WC_', 'jet_engine', 'get_terms', 'wp_get_object_terms', 'wp_set_object_terms',
	'wp_schedule_event', 'wp_next_scheduled', 'register_rest_route', 'wp_send_json',
	'shortcode_exists', 'get_bloginfo', 'home_url', 'site_url', 'wp_cache_',
	'wp_mcp_ai_', 'switch_to_blog', 'is_multisite',
	'wpautop', 'do_shortcode', 'get_the_ID', 'wp_nonce', 'check_admin_referer',
	'get_site_option', 'get_user_meta', 'update_user_meta', 'wp_redirect',
);

$base_tools = nvoos_slugs_from( $root . '/includes/tools', '/^class-wp-mcp-ai-tool-.*\.php$/' );
$pro_tools  = nvoos_slugs_from( $root . '/addons/pro/includes/tools' );

// Tools that live outside `…/includes/tools`: the model-foundry exporters, the
// harness pair and the MCP-App bridge. A bare scan of `addons/pro/includes`
// would also sweep in ~50 classes that carry a `get_slug()` for a different
// purpose — CCTs name their record type, MCP servers name their endpoint,
// booking adapters name the provider — so the scan is restricted to classes
// that implement the tool interface. Without this, the exporters were absent
// from the inventory entirely and the gap under-reported the real surface.
$pro_tools += nvoos_slugs_from( $root . '/addons/pro/includes', null, 'WP_MCP_AI_Tool_Interface' );

$core_tools = nvoos_slugs_from( $root . '/lib/core/src' );
$classes    = nvoos_class_index( array( $root . '/includes', $root . '/addons/pro/includes' ) );

$rows = array();

foreach ( $base_tools + $pro_tools as $slug => $path ) {
	if ( isset( $core_tools[ $slug ] ) ) {
		continue;
	}

	$closure = nvoos_closure( $path, $classes );
	$hard    = 0;
	$kinds   = array();
	$bytes   = 0;

	foreach ( $closure as $file ) {
		$code   = (string) file_get_contents( $file );
		$bytes += strlen( $code );

		foreach ( $hard_references as $reference ) {
			$count = substr_count( $code, $reference );

			if ( $count > 0 ) {
				$hard += $count;
				$kinds[ $reference ] = true;
			}
		}
	}

	$rows[] = array(
		'slug'    => $slug,
		'origin'  => isset( $pro_tools[ $slug ] ) ? 'pro' : 'base',
		'hard'    => $hard,
		'tier'    => 0 === $hard ? 'A' : ( $hard <= 3 ? 'B' : ( $hard <= 10 ? 'C' : ( $hard <= 30 ? 'D' : 'E' ) ) ),
		'kinds'   => implode( ',', array_slice( array_keys( $kinds ), 0, 5 ) ),
		'signals' => array_keys( $kinds ),
		'files'   => count( $closure ),
		'bytes'   => $bytes,
	);
}

usort( $rows, static fn ( $a, $b ) => $a['hard'] <=> $b['hard'] ?: $a['bytes'] <=> $b['bytes'] );

$summary = array(
	'slugs' => array(
		'base' => count( $base_tools ),
		'pro'  => count( $pro_tools ),
		'core' => count( $core_tools ),
		'gap'  => count( $rows ),
	),
	'tiers' => array( 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0 ),
);

foreach ( $rows as $row ) {
	++$summary['tiers'][ $row['tier'] ];
}

if ( '' !== $options['match'] ) {
	$needles = array_filter( array_map( 'trim', explode( ',', $options['match'] ) ) );
	$matched = array();

	foreach ( $rows as $row ) {
		$intersect = array_intersect( $needles, $row['signals'] );

		if ( array() !== $intersect ) {
			$row['matched'] = array_values( $intersect );
			$matched[]      = $row;
		}
	}

	if ( $options['json'] ) {
		echo json_encode(
			array(
				'summary' => $summary,
				'match'   => $needles,
				'count'   => count( $matched ),
				'tools'   => $matched,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . PHP_EOL;
		exit( 0 );
	}

	echo 'matched ' . count( $matched ) . ' of ' . count( $rows ) . ' gap tools on: ' . implode( ', ', $needles ) . PHP_EOL . PHP_EOL;

	foreach ( $matched as $row ) {
		printf( "%-5s %-42s tier=%s hard=%-3d %s\n", $row['origin'], $row['slug'], $row['tier'], $row['hard'], implode( ',', $row['matched'] ) );
	}

	exit( 0 );
}

if ( $options['json'] ) {
	$payload = array( 'summary' => $summary, 'tools' => $rows );

	echo json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	exit( 0 );
}

echo 'slugs ' . json_encode( $summary['slugs'] ) . PHP_EOL;
echo 'tiers ' . json_encode( $summary['tiers'] ) . PHP_EOL;

if ( '' === $options['tier'] ) {
	exit( 0 );
}

echo PHP_EOL;

$printed = 0;

foreach ( $rows as $row ) {
	if ( $row['tier'] !== $options['tier'] ) {
		continue;
	}

	printf(
		"%-5s %-42s hard=%-4d files=%-2d %7d  %s\n",
		$row['origin'],
		$row['slug'],
		$row['hard'],
		$row['files'],
		$row['bytes'],
		$row['kinds']
	);

	++$printed;

	if ( $options['limit'] > 0 && $printed >= $options['limit'] ) {
		break;
	}
}
