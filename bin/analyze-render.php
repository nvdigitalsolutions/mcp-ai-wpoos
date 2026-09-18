<?php
/**
 * Dev utility: analyze a rendered page for the Playground crash signature.
 *
 * Prints, for verify-out/real-render.html:
 *   - line 668 (and neighbours) with byte offsets and the characters around
 *     column 127
 *   - every inline script block with its line number, byte length, and a
 *     non-ASCII/control-char scan
 *   - non-ASCII line inventory
 *
 * Usage: php bin/analyze-render.php [path]
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

$path = $argv[1] ?? dirname( __DIR__ ) . '/verify-out/real-render.html';
if ( ! is_file( $path ) ) {
	fwrite( STDERR, "Missing render: {$path}\n" );
	exit( 1 );
}

$raw = (string) file_get_contents( $path );
$lines = preg_split( '/\r\n|\r|\n/', $raw );

echo 'FILE: ' . $path . ' — ' . strlen( $raw ) . " bytes, " . count( $lines ) . " lines\n\n";

// --- Line 668 region -------------------------------------------------------
foreach ( array( 664, 665, 666, 667, 668, 669, 670, 671, 672 ) as $n ) {
	if ( ! isset( $lines[ $n - 1 ] ) ) {
		continue;
	}
	$line = $lines[ $n - 1 ];
	$prefix = ( 668 === $n ) ? '>>> ' : '    ';
	echo "{$prefix}LINE {$n} (len " . strlen( $line ) . "):\n";
	if ( strlen( $line ) > 0 ) {
		// Show the first 200 bytes with hex offsets every 50.
		$chunk = substr( $line, 0, 200 );
		echo "        " . $chunk . "\n";
		$hex = '';
		for ( $i = 0; $i < min( strlen( $line ), 200 ); $i++ ) {
			$hex .= sprintf( '%02x', ord( $line[ $i ] ) );
			if ( 0 === ( $i + 1 ) % 10 ) {
				$hex .= '|';
			}
		}
		echo '        hex: ' . $hex . "\n";
	}
	if ( 668 === $n && isset( $line[126] ) ) {
		echo '        around col 127: ...' . substr( $line, max( 0, 110 ), 40 ) . "...\n";
		echo '        char at col 127: ' . sprintf( '0x%02x', ord( $line[126] ) ) . ' (' . var_export( $line[126], true ) . ")\n";
	}
	echo "\n";
}

// --- Inline scripts --------------------------------------------------------
$script_lines = array();
$offset = 0;
foreach ( $lines as $i => $line ) {
	$script_lines[ $i + 1 ] = $offset;
	$offset += strlen( $line ) + 1;
}

$idx = 0;
$offset = 0;
while ( preg_match( '/<script\b[^>]*>(.*?)<\/script>/s', $raw, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
	$idx++;
	$src = $m[1][0];
	$pos = $m[0][1];
	$line_no = 1;
	foreach ( $script_lines as $ln => $off ) {
		if ( $off > $pos ) {
			break;
		}
		$line_no = $ln;
	}

	$nonascii = array();
	if ( preg_match_all( '/[^\x20-\x7E\r\n\t]/', $src, $na ) ) {
		foreach ( array_slice( array_unique( $na[0] ), 0, 8 ) as $ch ) {
			$nonascii[] = bin2hex( $ch );
		}
	}
	$ctrl = preg_match_all( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $src, $c ) ? count( $c[0] ) : 0;

	printf(
		"SCRIPT %d @ line %d, %d bytes%s%s\n    head: %s\n",
		$idx,
		$line_no,
		strlen( $src ),
		$nonascii ? ', NONASCII: ' . implode( ',', $nonascii ) : '',
		$ctrl ? ", CTRL x{$ctrl}" : '',
		substr( trim( $src ), 0, 110 )
	);

	// Extract for node --check.
	$dir = dirname( __DIR__ ) . '/verify-out/js';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents( $dir . '/real-block-' . $idx . '.js', $src );

	$offset = $m[0][1] + strlen( $m[0][0] );
}

// --- Non-ASCII lines -------------------------------------------------------
echo "\nNon-ASCII lines:\n";
foreach ( $lines as $i => $line ) {
	if ( preg_match( '/[^\x20-\x7E\t]/', $line ) ) {
		echo '  L' . ( $i + 1 ) . ': ' . substr( $line, 0, 120 ) . "\n";
	}
}
