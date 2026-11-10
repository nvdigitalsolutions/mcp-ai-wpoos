<?php
/**
 * CI gate — the forge-bridge public WRITE REST routes must be reviewed.
 *
 * Why this exists: the wp.org AI reviewer repeatedly flags public write
 * endpoints whose only "authorization" is an origin/CSRF gate. PHPCS and
 * Plugin Check do not flag `permission_callback => '__return_true'` on a
 * CREATABLE route, so this check forces one human decision per route:
 * implement a real permission callback, or review the route and list it in
 * `forge-bridge-public-write-routes.txt`.
 *
 * It also fails on a STALE allowlist entry (a listed route that is no longer a
 * public write route), so the allowlist cannot rot.
 *
 * Usage: php .github/scripts/check-forge-bridge-public-routes.php
 * Exit 0 = every public write route is reviewed; exit 1 = violation.
 *
 * @package NvoosForgeBridge
 */

declare( strict_types=1 );

$root       = dirname( __DIR__, 2 );
$plugin_dir = $root . '/plugins/nvoos-forge-bridge/includes';
$allow_file = __DIR__ . '/forge-bridge-public-write-routes.txt';

if ( ! is_dir( $plugin_dir ) ) {
	fwrite( STDERR, "Plugin includes directory not found: {$plugin_dir}\n" );
	exit( 1 );
}

/**
 * Unquote a T_CONSTANT_ENCAPSED_STRING token value.
 *
 * @param string $literal Quoted literal as tokenized.
 * @return string
 */
function gate_literal( string $literal ): string {
	$quote = '' !== $literal ? $literal[0] : '';
	$body  = strlen( $literal ) >= 2 ? substr( $literal, 1, -1 ) : $literal;
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}
	return stripcslashes( $body );
}

$allowed = array();
if ( is_readable( $allow_file ) ) {
	foreach ( file( $allow_file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( (string) preg_replace( '/#.*$/', '', (string) $line ) );
		if ( '' === $line ) {
			continue;
		}
		$allowed[] = (string) preg_replace( '/\s+/', ' ', $line );
	}
}

$write_verbs = array(
	'CREATABLE' => 'POST',
	'EDITABLE'  => 'POST',
	'DELETABLE' => 'DELETE',
);

$found      = array();
$violations = array();

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$path = $file->getPathname();
	$src  = (string) file_get_contents( $path );

	$ns = 'nvoos-forge/v1';
	if ( preg_match( "/const\\s+NS\\s*=\\s*'([^']+)'/", $src, $ns_match ) ) {
		$ns = $ns_match[1];
	}

	$tokens = token_get_all( $src );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) || T_STRING !== $token[0] || 'register_rest_route' !== $token[1] ) {
			continue;
		}
		$line = (int) $token[2];

		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			continue;
		}

		$depth   = 0;
		$strings = array();
		$idents  = array();

		for ( $k = $j; $k < $count; $k++ ) {
			$t = $tokens[ $k ];
			if ( is_array( $t ) ) {
				if ( T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
					$strings[] = gate_literal( $t[1] );
				} elseif ( T_STRING === $t[0] ) {
					$idents[] = $t[1];
				}
				continue;
			}
			if ( '(' === $t || '[' === $t || '{' === $t ) {
				$depth++;
				continue;
			}
			if ( ')' === $t || ']' === $t || '}' === $t ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			}
		}

		if ( ! in_array( '__return_true', $strings, true ) ) {
			continue;
		}

		// First argument is `self::NS` (route is the first literal) or a
		// namespace literal (namespace first, route second).
		if ( isset( $idents[0] ) && 'self' === $idents[0] ) {
			$route_ns = $ns;
			$route    = isset( $strings[0] ) ? $strings[0] : '';
		} else {
			$route_ns = isset( $strings[0] ) ? $strings[0] : $ns;
			$route    = isset( $strings[1] ) ? $strings[1] : '';
		}
		if ( '' === $route ) {
			continue;
		}

		$verbs = array();
		foreach ( $write_verbs as $keyword => $verb ) {
			if ( in_array( $keyword, $idents, true ) ) {
				$verbs[] = $verb;
			}
		}
		if ( array() === $verbs ) {
			continue; // Read-only public route.
		}

		$relative = ltrim( str_replace( $root, '', $path ), '/\\' );
		$route_path = '/' . ltrim( $route_ns . '/' . ltrim( $route, '/' ), '/' );
		foreach ( array_unique( $verbs ) as $verb ) {
			$key     = $verb . ' ' . $route_path;
			$found[] = $key;
			if ( ! in_array( $key, $allowed, true ) ) {
				$violations[] = sprintf( '%s  (%s:%d)', $key, $relative, $line );
			}
		}
	}
}

$found = array_values( array_unique( $found ) );
sort( $found );
$stale = array_values( array_diff( $allowed, $found ) );

if ( array() !== $found ) {
	echo "Public write routes (permission_callback = __return_true):\n";
	foreach ( $found as $key ) {
		echo '  - ' . $key . "\n";
	}
}

if ( array() !== $violations ) {
	foreach ( $violations as $violation ) {
		fwrite( STDERR, '::error::Un-reviewed public write route: ' . $violation . "\n" );
	}
	fwrite( STDERR, "\nAdd a real permission_callback, or review the route and list it in\n.github/scripts/forge-bridge-public-write-routes.txt.\n" );
	exit( 1 );
}

if ( array() !== $stale ) {
	foreach ( $stale as $key ) {
		fwrite( STDERR, '::error::Stale allowlist entry (not a public write route any more): ' . $key . "\n" );
	}
	fwrite( STDERR, "\nRemove the stale entry from .github/scripts/forge-bridge-public-write-routes.txt.\n" );
	exit( 1 );
}

echo "OK — every public write route is reviewed.\n";
exit( 0 );
