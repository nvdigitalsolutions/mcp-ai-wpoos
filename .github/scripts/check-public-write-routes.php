<?php
/**
 * CI gate — every public WRITE REST route must be reviewed.
 *
 * Why this exists: the wp.org AI reviewer repeatedly flags public write
 * endpoints whose only "authorization" is an origin/CSRF gate or an optional
 * token. PHPCS and Plugin Check do not flag `permission_callback =>
 * '__return_true'` on a CREATABLE route, so this check forces one human
 * decision per route: implement a real permission callback, or review the
 * route and list it (with its compensating controls) in
 * `public-write-routes.txt`.
 *
 * It also fails on a STALE allowlist entry (a listed route that is no longer a
 * public write route), so the allowlist cannot rot.
 *
 * Usage: php .github/scripts/check-public-write-routes.php
 * Exit 0 = every public write route is reviewed; exit 1 = violation.
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

$root       = dirname( __DIR__, 2 );
$scan_roots = array(
	$root . '/includes',
	$root . '/addons/pro/includes',
	$root . '/plugins',
);
$allow_file = __DIR__ . '/public-write-routes.txt';

/**
 * Unquote a T_CONSTANT_ENCAPSED_STRING token value.
 *
 * @param string $literal Quoted literal as tokenized.
 * @return string
 */
function pwrg_literal( string $literal ): string {
	$quote = '' !== $literal ? $literal[0] : '';
	$body  = strlen( $literal ) >= 2 ? substr( $literal, 1, -1 ) : $literal;
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}
	return stripcslashes( $body );
}

/**
 * Render a namespace/path expression from its tokens, resolving `self::CONST`
 * against the file's string constants. Dynamic fragments become `*`.
 *
 * @param array<int, array|string> $slice  Token slice.
 * @param array<string, string>    $consts File constants.
 * @return string
 */
function pwrg_render( array $slice, array $consts ): string {
	$out     = '';
	$prev    = '';
	$pending = null;

	$flush = static function () use ( &$pending, &$out, $consts ) {
		if ( null === $pending ) {
			return;
		}
		$out    .= array_key_exists( $pending, $consts ) ? $consts[ $pending ] : '*';
		$pending = null;
	};

	foreach ( $slice as $t ) {
		if ( ! is_array( $t ) ) {
			$flush();
			$prev = $t;
			continue;
		}
		if ( T_WHITESPACE === $t[0] || T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) {
			continue;
		}
		if ( T_DOUBLE_COLON === $t[0] ) {
			$pending = null; // The preceding identifier was a class name.
			$prev    = '::';
			continue;
		}
		if ( T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
			$flush();
			$out .= pwrg_literal( $t[1] );
			$prev = 'str';
			continue;
		}
		if ( T_STRING === $t[0] || T_NAME_QUALIFIED === $t[0] || T_NAMESPACE === $t[0] ) {
			if ( '::' === $prev ) {
				$out .= array_key_exists( $t[1], $consts ) ? $consts[ $t[1] ] : '{' . $t[1] . '}';
				$prev = 'str';
				continue;
			}
			$flush();
			$pending = $t[1];
			$prev    = 'ident';
			continue;
		}
		$flush();
		$out .= '*';
		$prev = 'dyn';
	}

	$flush();
	return (string) preg_replace( '/\*{2,}/', '*', $out );
}

/**
 * Split a call's inner tokens into top-level arguments.
 *
 * @param array<int, array|string> $tokens Call tokens (between the parens).
 * @return array<int, array<int, array|string>>
 */
function pwrg_split_args( array $tokens ): array {
	$args  = array();
	$cur   = array();
	$depth = 0;
	foreach ( $tokens as $t ) {
		if ( ! is_array( $t ) ) {
			if ( '(' === $t || '[' === $t || '{' === $t ) {
				$depth++;
			} elseif ( ')' === $t || ']' === $t || '}' === $t ) {
				$depth--;
			} elseif ( ',' === $t && 0 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
		}
		$cur[] = $t;
	}
	if ( array() !== $cur ) {
		$args[] = $cur;
	}
	return $args;
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

foreach ( $scan_roots as $scan_root ) {
	if ( ! is_dir( $scan_root ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $scan_root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$file_path = $file->getPathname();
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		if ( preg_match( '#[\\\\/](vendor|node_modules|tests|examples|build|dist)[\\\\/]#', $file_path ) ) {
			continue;
		}

		$src = (string) file_get_contents( $file_path );
		if ( false === strpos( $src, '__return_true' ) || false === strpos( $src, 'register_rest_route' ) ) {
			continue;
		}

		$consts = array();
		if ( preg_match_all( "/const\\s+([A-Z_][A-Z0-9_]*)\\s*=\\s*'([^']*)'/", $src, $cm, PREG_SET_ORDER ) ) {
			foreach ( $cm as $c ) {
				$consts[ $c[1] ] = $c[2];
			}
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

			$depth = 0;
			$inner = array();
			for ( $k = $j; $k < $count; $k++ ) {
				$t = $tokens[ $k ];
				if ( is_array( $t ) ) {
					if ( $k > $j ) {
						$inner[] = $t;
					}
					continue;
				}
				if ( '(' === $t ) {
					$depth++;
					if ( 1 === $depth ) {
						continue;
					}
					$inner[] = $t;
					continue;
				}
				if ( ')' === $t ) {
					$depth--;
					if ( 0 === $depth ) {
						break;
					}
					$inner[] = $t;
					continue;
				}
				$inner[] = $t;
			}

			$args = pwrg_split_args( $inner );
			if ( count( $args ) < 3 ) {
				continue;
			}
			$arg2 = $args[2];
			$is_public = false;
			$verbs     = array();
			foreach ( $arg2 as $t ) {
				if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && '__return_true' === pwrg_literal( $t[1] ) ) {
					$is_public = true;
				}
				if ( is_array( $t ) && T_STRING === $t[0] && isset( $write_verbs[ $t[1] ] ) ) {
					$verbs[] = $write_verbs[ $t[1] ];
				}
			}
			if ( ! $is_public || array() === $verbs ) {
				continue; // Read-only public route, or a permission-checked write route.
			}

			$ns   = pwrg_render( $args[0], $consts );
			$route_path = pwrg_render( $args[1], $consts );
			$full = '/' . trim( preg_replace( '#/+#', '/', '/' . trim( $ns, '/' ) . '/' . trim( $route_path, '/' ) ), '/' );

			$relative = ltrim( str_replace( $root, '', $file_path ), '/\\' );
			foreach ( array_unique( $verbs ) as $verb ) {
				$key     = $verb . ' ' . $full;
				$found[] = $key;
				if ( ! in_array( $key, $allowed, true ) ) {
					$violations[] = sprintf( '%s  (%s:%d)', $key, $relative, $line );
				}
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
		fwrite( STDOUT, '  - ' . $key . "\n" );
	}
}

if ( array() !== $violations ) {
	foreach ( $violations as $violation ) {
		fwrite( STDERR, '::error::Un-reviewed public write route: ' . $violation . "\n" );
	}
	fwrite( STDERR, "\nAdd a real permission_callback, or review the route and list it in\n.github/scripts/public-write-routes.txt.\n" );
	exit( 1 );
}

if ( array() !== $stale ) {
	foreach ( $stale as $key ) {
		fwrite( STDERR, '::error::Stale allowlist entry (not a public write route any more): ' . $key . "\n" );
	}
	fwrite( STDERR, "\nRemove the stale entry from .github/scripts/public-write-routes.txt.\n" );
	exit( 1 );
}

echo "OK — every public write route is reviewed.\n";
exit( 0 );
