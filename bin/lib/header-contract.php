<?php
/**
 * Shared implementation of the cross-language documentation contract.
 *
 * Proposal 166 (Unix Theory P8–P12). Used by:
 *
 *   bin/add-spdx-headers.php   — licence insertion
 *   bin/check-file-docs.php    — audit, header checks, ratchet budget
 *   bin/check-license-boundary.sh — licence presence (via --licence-only)
 *
 * The contract is deliberately text-based: no AST, no network, no Composer
 * dependency. It must run before `npm ci` / `cargo fetch` and stay under 5 s on
 * the full tree.
 *
 * @package WP_MCP_AI
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace WP_MCP_AI\Bin\DocsContract;

const HEAD_BYTES = 4096;
const SPDX_BYTES = 2048;

/**
 * Repository root (two levels above bin/lib).
 *
 * @return string
 */
function root(): string {
	return dirname( __DIR__, 2 );
}

/**
 * Load and validate the committed scope map.
 *
 * @param string $root Repository root.
 * @return array
 */
function load_config( string $root ): array {
	$path = $root . '/.github/docs-scopes.json';
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "Missing scope map: {$path}\n" );
		exit( 2 );
	}

	$json = file_get_contents( $path );
	$data = json_decode( (string) $json, true );

	if ( ! is_array( $data ) || ! isset( $data['scopes'] ) || ! is_array( $data['scopes'] ) ) {
		fwrite( STDERR, "Invalid scope map: {$path}\n" );
		exit( 2 );
	}

	return $data;
}

/**
 * Language key for a path, or null when the extension is out of scope.
 *
 * @param string $rel Repository-relative path (forward slashes).
 * @return string|null
 */
function lang_for( string $rel ): ?string {
	$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	switch ( $ext ) {
		case 'ts':
		case 'tsx':
			return 'ts';
		case 'js':
		case 'jsx':
		case 'mjs':
		case 'cjs':
			return 'js';
		case 'astro':
			return 'astro';
		case 'rs':
			return 'rust';
		default:
			return null;
	}
}

/**
 * Convert a glob to an anchored PCRE.
 *
 * Supports `**` (any depth), `*` (one segment), `?` and `{a,b}`.
 *
 * @param string $glob Glob.
 * @return string
 */
function glob_to_regex( string $glob ): string {
	$glob = str_replace( '\\', '/', $glob );
	$out  = '';
	$len  = strlen( $glob );

	for ( $i = 0; $i < $len; $i++ ) {
		$char = $glob[ $i ];

		if ( '*' === $char ) {
			if ( $i + 1 < $len && '*' === $glob[ $i + 1 ] ) {
				if ( $i + 2 < $len && '/' === $glob[ $i + 2 ] ) {
					$out .= '(?:.*/)?';
					$i   += 2;
				} else {
					$out .= '.*';
					++$i;
				}
			} else {
				$out .= '[^/]*';
			}
			continue;
		}

		if ( '?' === $char ) {
			$out .= '[^/]';
			continue;
		}

		if ( '{' === $char ) {
			$close = strpos( $glob, '}', $i );
			if ( false !== $close ) {
				$parts = explode( ',', substr( $glob, $i + 1, $close - $i - 1 ) );
				$quoted = array();
				foreach ( $parts as $part ) {
					$quoted[] = preg_quote( $part, '/' );
				}
				$out .= '(?:' . implode( '|', $quoted ) . ')';
				$i    = $close;
				continue;
			}
		}

		$out .= preg_quote( $char, '/' );
	}

	return '#^' . $out . '$#';
}

/**
 * Whether a path matches any exclude pattern.
 *
 * @param string $rel    Repository-relative path.
 * @param array  $config Scope map.
 * @return bool
 */
function path_excluded( string $rel, array $config ): bool {
	$segments = explode( '/', $rel );
	$dirs     = isset( $config['exclude_dirs'] ) && is_array( $config['exclude_dirs'] )
		? $config['exclude_dirs']
		: array( 'vendor', 'node_modules', 'dist', 'build', 'target', 'coverage', '.git' );

	foreach ( $segments as $segment ) {
		if ( in_array( $segment, $dirs, true ) ) {
			return true;
		}
	}

	$globs = isset( $config['exclude_paths'] ) && is_array( $config['exclude_paths'] )
		? $config['exclude_paths']
		: array();

	foreach ( $globs as $glob ) {
		if ( @preg_match( glob_to_regex( $glob ), $rel ) ) {
			return true;
		}
	}

	return false;
}

/**
 * List candidate source files in the repository.
 *
 * Uses `git ls-files`; falls back to a recursive scan when git is unavailable.
 *
 * @param string $root Repository root.
 * @return string[]
 */
function list_files( string $root ): array {
	$raw = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files -z' );

	if ( is_string( $raw ) && '' !== $raw ) {
		$files = array_filter( explode( "\0", $raw ) );
	} else {
		$files = scan_files( $root );
	}

	$out = array();
	foreach ( $files as $file ) {
		$rel = str_replace( '\\', '/', (string) $file );
		if ( null !== lang_for( $rel ) ) {
			$out[] = $rel;
		}
	}

	sort( $out );
	return $out;
}

/**
 * Recursive fallback scan.
 *
 * @param string $root Repository root.
 * @return string[]
 */
function scan_files( string $root ): array {
	$out      = array();
	$skip     = array( 'vendor', 'node_modules', 'dist', 'build', 'target', 'coverage', '.git', '.next', '.astro' );
	$iterator = new \RecursiveIteratorIterator(
		new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			static function ( $current ) use ( $skip ) {
				if ( $current->isDir() ) {
					return ! in_array( $current->getFilename(), $skip, true );
				}
				return true;
			}
		)
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$out[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		}
	}

	return $out;
}

/**
 * Scopes matching a path (normally exactly one).
 *
 * @param string $rel    Repository-relative path.
 * @param array  $config Scope map.
 * @return array[]
 */
function scopes_for( string $rel, array $config ): array {
	$matches = array();

	foreach ( $config['scopes'] as $scope ) {
		if ( ! isset( $scope['paths'] ) || ! is_array( $scope['paths'] ) ) {
			continue;
		}
		foreach ( $scope['paths'] as $glob ) {
			if ( @preg_match( glob_to_regex( $glob ), $rel ) ) {
				$matches[] = $scope;
				break;
			}
		}
	}

	return $matches;
}

/**
 * First matching scope, or null.
 *
 * @param string $rel    Repository-relative path.
 * @param array  $config Scope map.
 * @return array|null
 */
function scope_for( string $rel, array $config ): ?array {
	$matches = scopes_for( $rel, $config );
	return isset( $matches[0] ) ? $matches[0] : null;
}

/**
 * Longest matching pending product, or null.
 *
 * @param string $rel    Repository-relative path.
 * @param array  $config Scope map.
 * @return string|null
 */
function pending_product( string $rel, array $config ): ?string {
	$pending = isset( $config['pending_licence'] ) && is_array( $config['pending_licence'] )
		? $config['pending_licence']
		: array();

	$best = null;
	foreach ( $pending as $prefix ) {
		$prefix = rtrim( (string) $prefix, '/' );
		if ( $rel === $prefix || 0 === strpos( $rel, $prefix . '/' ) ) {
			if ( null === $best || strlen( $prefix ) > strlen( $best ) ) {
				$best = $prefix;
			}
		}
	}

	return $best;
}

/**
 * Read the licence declared by a manifest, if it declares one.
 *
 * Returns null both when no manifest exists and when the nearest manifest has
 * no licence field — the caller must not walk past the first manifest, because
 * that would cross a product boundary.
 *
 * @param string $root     Repository root.
 * @param string $rel      Repository-relative file path.
 * @param string $manifest Manifest filename (`package.json` or `Cargo.toml`).
 * @return string|null
 */
function manifest_licence( string $root, string $rel, string $manifest ): ?string {
	$dir = dirname( $rel );

	while ( true ) {
		$candidate = ( '.' === $dir || '' === $dir ? '' : $dir . '/' ) . $manifest;
		$abs       = $root . '/' . $candidate;

		if ( is_file( $abs ) ) {
			return normalise_licence( read_manifest_licence( $abs, $manifest ) );
		}

		if ( '.' === $dir || '' === $dir ) {
			break;
		}

		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}

	return null;
}

/**
 * Read a licence value from a manifest file.
 *
 * @param string $abs      Absolute manifest path.
 * @param string $manifest Manifest filename.
 * @return string|null
 */
function read_manifest_licence( string $abs, string $manifest ): ?string {
	$raw = file_get_contents( $abs );
	if ( false === $raw ) {
		return null;
	}

	if ( 'Cargo.toml' === $manifest ) {
		if ( preg_match( '/^\s*license\s*=\s*"([^"]+)"/m', $raw, $m ) ) {
			return $m[1];
		}
		return null;
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return null;
	}

	if ( isset( $data['license'] ) && is_string( $data['license'] ) ) {
		return $data['license'];
	}

	if ( isset( $data['licenses'][0]['type'] ) && is_string( $data['licenses'][0]['type'] ) ) {
		return $data['licenses'][0]['type'];
	}

	return null;
}

/**
 * Longest matching product licence, or null.
 *
 * @param string $rel    Repository-relative path.
 * @param array  $config Scope map.
 * @return string|null
 */
function product_licence( string $rel, array $config ): ?string {
	$map = isset( $config['product_licences'] ) && is_array( $config['product_licences'] )
		? $config['product_licences']
		: array();

	$best      = null;
	$best_len  = -1;

	foreach ( $map as $prefix => $licence ) {
		$prefix = rtrim( (string) $prefix, '/' );
		if ( $rel === $prefix || 0 === strpos( $rel, $prefix . '/' ) ) {
			if ( strlen( $prefix ) > $best_len ) {
				$best     = normalise_licence( (string) $licence );
				$best_len = strlen( $prefix );
			}
		}
	}

	return $best;
}

/**
 * Normalise a licence declaration to an SPDX expression.
 *
 * @param string|null $raw Raw value.
 * @return string|null
 */
function normalise_licence( ?string $raw ): ?string {
	if ( null === $raw ) {
		return null;
	}

	$raw = trim( $raw );
	if ( '' === $raw ) {
		return null;
	}

	$lower = strtolower( $raw );

	if ( 'unlicensed' === $lower || 'proprietary' === $lower || 0 === strpos( $lower, 'see license' ) ) {
		return 'LicenseRef-Proprietary';
	}

	if ( 'mit' === $lower || 'mit license' === $lower ) {
		return 'MIT';
	}

	if ( preg_match( '/^gpl(v)?[- ]?3(\.0)?(\+|-or-later)?$/', $lower ) || 'gplv3 or later' === $lower || 'gpl-3.0-or-later' === $lower ) {
		return 'GPL-3.0-or-later';
	}

	if ( 'agplv3 or later' === $lower || 'agpl-3.0-or-later' === $lower ) {
		return 'AGPL-3.0-or-later';
	}

	return $raw;
}

/**
 * Resolve the licence for a file: manifest → product → scope fallback.
 *
 * @param string $root  Repository root.
 * @param string $rel   Repository-relative path.
 * @param array  $scope Matching scope.
 * @param array  $config Scope map.
 * @return string|null
 */
function resolve_licence( string $root, string $rel, array $scope, array $config ): ?string {
	$manifest = isset( $scope['manifest'] ) ? (string) $scope['manifest'] : 'package.json';

	$from_manifest = manifest_licence( $root, $rel, $manifest );
	if ( null !== $from_manifest ) {
		return $from_manifest;
	}

	$from_product = product_licence( $rel, $config );
	if ( null !== $from_product ) {
		return $from_product;
	}

	if ( isset( $scope['fallback'] ) ) {
		return normalise_licence( (string) $scope['fallback'] );
	}

	return null;
}

/**
 * Read the head of a file.
 *
 * @param string $abs   Absolute path.
 * @param int    $bytes Bytes to read.
 * @return string
 */
function read_head( string $abs, int $bytes = HEAD_BYTES ): string {
	$handle = @fopen( $abs, 'rb' );
	if ( false === $handle ) {
		return '';
	}
	$head = (string) fread( $handle, $bytes );
	fclose( $handle );
	return $head;
}

/**
 * Extract the SPDX identifier from a file head.
 *
 * @param string $head File head.
 * @return string|null
 */
function spdx_from_head( string $head ): ?string {
	$sample = substr( $head, 0, SPDX_BYTES );
	if ( preg_match( '/SPDX-License-Identifier:\s*([^\s*\/]+)/', $sample, $m ) ) {
		return trim( $m[1] );
	}
	return null;
}

/**
 * Whether a file head carries a purpose overview.
 *
 * @param string $head File head.
 * @param string $lang Language key.
 * @return bool
 */
function has_overview( string $head, string $lang ): bool {
	if ( 'rust' === $lang ) {
		return (bool) preg_match( '/^\s*\/\/!/m', $head );
	}

	return (bool) preg_match( '/(@fileoverview|@file)(?=\s|$)/', $head );
}

/**
 * Whether a file is generated.
 *
 * @param string $rel  Repository-relative path.
 * @param string $head File head.
 * @return bool
 */
function is_generated( string $rel, string $head ): bool {
	if ( false !== strpos( $head, '@generated' ) ) {
		return true;
	}

	if ( '.d.ts' === substr( $rel, -5 ) ) {
		return true;
	}

	return (bool) preg_match( '#/(gen|generated|__generated__)/#', $rel );
}

/**
 * Classify a file for the documentation minimum bar.
 *
 * @param string $rel  Repository-relative path.
 * @param string $lang Language key.
 * @return string
 */
function file_class( string $rel, string $lang ): string {
	$base = strtolower( basename( $rel ) );

	if ( preg_match( '#(^|/)(__tests__|tests?|spec|specs)/#', $rel ) || preg_match( '/\.(test|spec)\./', $base ) ) {
		return 'test';
	}

	if ( 'build.rs' === $base || preg_match( '/\.(config|conf)\.(js|ts|mjs|cjs)$/', $base ) || preg_match( '/^(vite|eslint|prettier|tailwind|postcss|playwright|vitest|jest|rollup|webpack|tsup|astro|next|nuxt|turbo)\.config\./', $base ) || preg_match( '/\.setup\.(js|ts|mjs|cjs)$/', $base ) ) {
		return 'config';
	}

	if ( '.d.ts' === substr( $rel, -5 ) ) {
		return 'generated';
	}

	if ( in_array( $base, array( 'index.ts', 'index.tsx', 'main.ts', 'main.tsx', 'lib.rs', 'main.rs', 'server.js', 'server.ts', 'app.js', 'app.ts' ), true ) ) {
		return 'entrypoint';
	}

	if ( 'tsx' === strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ) || 'astro' === $lang ) {
		return 'component';
	}

	return 'module';
}

/**
 * Whether a file's purpose statement meets the minimum information bar.
 *
 * @param string $head  File head.
 * @param string $lang  Language key.
 * @param string $class File class.
 * @param string $rel   Repository-relative path.
 * @return bool
 */
function purpose_bar( string $head, string $lang, string $class, string $rel ): bool {
	if ( 'generated' === $class ) {
		return true;
	}

	if ( 'rust' === $lang ) {
		preg_match_all( '/^\s*\/\/!\s?(.*)$/m', $head, $matches );
		$lines = $matches[1];
	} else {
		if ( ! preg_match( '/(@fileoverview|@file)(.*?)(?:\*\/|$)/s', $head, $match ) ) {
			return false;
		}
		$lines = preg_split( '/\R/', $match[2] );
	}

	$meaningful = array();
	foreach ( $lines as $line ) {
		$line = trim( (string) preg_replace( '/^\s*\*\s?/', '', (string) $line ) );
		if ( '' === $line || '@' === substr( $line, 0, 1 ) || false !== strpos( $line, 'SPDX-License-Identifier' ) ) {
			continue;
		}
		if ( strlen( $line ) < 20 ) {
			continue;
		}
		$meaningful[] = $line;
	}

	$min = in_array( $class, array( 'test', 'config' ), true ) ? 1 : 2;
	if ( count( $meaningful ) < $min ) {
		return false;
	}

	$joined = strtolower( implode( ' ', $meaningful ) );
	foreach ( array( 'this file contains', 'this module contains', 'various helper', 'utility functions', 'helper functions' ) as $blocked ) {
		if ( false !== strpos( $joined, $blocked ) && count( $meaningful ) <= 1 ) {
			return false;
		}
	}

	$base = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', pathinfo( $rel, PATHINFO_FILENAME ) ) );
	$text = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $meaningful[0] ) );
	if ( '' !== $base && $text === $base ) {
		return false;
	}

	return true;
}

/**
 * Detect the file's end-of-line sequence.
 *
 * @param string $code File contents.
 * @return string
 */
function eol( string $code ): string {
	return false !== strpos( $code, "\r\n" ) ? "\r\n" : "\n";
}

/**
 * Insert an SPDX line into file contents, idempotently.
 *
 * Never writes prose: the purpose header is a separate, human-authored block.
 *
 * @param string $code  File contents.
 * @param string $lang  Language key.
 * @param string $spdx  SPDX expression.
 * @return string
 */
function insert_spdx( string $code, string $lang, string $spdx ): string {
	$bom = '';
	if ( 0 === strpos( $code, "\xEF\xBB\xBF" ) ) {
		$bom  = "\xEF\xBB\xBF";
		$code = substr( $code, 3 );
	}

	if ( null !== spdx_from_head( $code ) ) {
		return $bom . $code;
	}

	$eol  = eol( $code );
	$line = 'SPDX-License-Identifier: ' . $spdx;

	if ( 'astro' === $lang ) {
		if ( 0 === strpos( $code, '---' ) ) {
			$pos = strpos( $code, $eol );
			if ( false !== $pos ) {
				$insert = '// ' . $line . $eol;
				return $bom . substr( $code, 0, $pos + strlen( $eol ) ) . $insert . substr( $code, $pos + strlen( $eol ) );
			}
		}
		return $bom . '<!-- ' . $line . ' -->' . $eol . $code;
	}

	if ( 'ts' === $lang || 'js' === $lang ) {
		if ( 0 === strpos( $code, '#!' ) ) {
			$pos = strpos( $code, $eol );
			if ( false !== $pos ) {
				$insert = $eol . '// ' . $line;
				return $bom . substr( $code, 0, $pos ) . $insert . substr( $code, $pos );
			}
		}
		return $bom . '// ' . $line . $eol . $code;
	}

	if ( 'rust' === $lang ) {
		return $bom . '// ' . $line . $eol . $code;
	}

	return $bom . $code;
}

/**
 * Files with uncommitted changes (unstaged or staged), repository-relative.
 *
 * @param string $root Repository root.
 * @return array<string, bool>
 */
function dirty_files( string $root ): array {
	$dirty = array();

	foreach ( array( 'diff --name-only', 'diff --cached --name-only' ) as $sub ) {
		$raw = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' ' . $sub );
		if ( ! is_string( $raw ) || '' === $raw ) {
			continue;
		}
		foreach ( preg_split( '/\R/', trim( $raw ) ) as $file ) {
			if ( '' !== $file ) {
				$dirty[ str_replace( '\\', '/', $file ) ] = true;
			}
		}
	}

	return $dirty;
}

/**
 * New files in the working tree (staged additions or untracked).
 *
 * @param string $root Repository root.
 * @param string $base Optional base ref for a committed range.
 * @return string[]
 */
function new_files( string $root, string $base = '' ): array {
	$out = array();

	if ( '' !== $base ) {
		$cmd = 'git -C ' . escapeshellarg( $root ) . ' diff --name-only --diff-filter=A -z ' . escapeshellarg( $base . '...HEAD' );
		$raw = shell_exec( $cmd );
		if ( is_string( $raw ) && '' !== $raw ) {
			$out = array_merge( $out, array_filter( explode( "\0", $raw ) ) );
		}
	} else {
		foreach ( array( 'diff --cached --name-only --diff-filter=A -z', 'ls-files --others --exclude-standard -z' ) as $sub ) {
			$raw = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' ' . $sub );
			if ( is_string( $raw ) && '' !== $raw ) {
				$out = array_merge( $out, array_filter( explode( "\0", $raw ) ) );
			}
		}
	}

	$out = array_map(
		static function ( $file ) {
			return str_replace( '\\', '/', (string) $file );
		},
		$out
	);

	sort( $out );
	return array_values( array_unique( $out ) );
}
