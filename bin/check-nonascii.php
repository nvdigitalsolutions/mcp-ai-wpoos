<?php
/**
 * Dev utility: report every non-ASCII byte/char in a set of files.
 *
 * Usage: php bin/check-nonascii.php <file> [<file> ...]
 *
 * @package WP_MCP_AI
 */

foreach ( array_slice( $argv, 1 ) as $file ) {
	if ( ! is_file( $file ) ) {
		echo "SKIP (missing): {$file}\n";
		continue;
	}
	$raw  = file_get_contents( $file );
	$text = preg_replace_callback(
		'/./u',
		function ( $m ) {
			$ch = $m[0];
			return ( ord( $ch ) < 0x80 ) ? $ch : '';
		},
		$raw
	);

	$first_non_ascii = null;
	$line_no         = 1;
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$cleaned = preg_replace_callback(
			'/./u',
			function ( $m ) {
				$ch = $m[0];
				return ( ord( $ch ) < 0x80 ) ? $ch : '';
			},
			$line
		);
		if ( $cleaned !== $line && null === $first_non_ascii ) {
			$first_non_ascii = $line_no;
		}
		$line_no++;
	}

	$count = 0;
	$chars = array();
	foreach ( preg_split( '//u', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
		if ( ord( $ch ) >= 0x80 ) {
			$count++;
			$chars[ $ch ] = isset( $chars[ $ch ] ) ? $chars[ $ch ] + 1 : 1;
		}
	}

	echo basename( $file ) . ': ' . $count . ' non-ASCII char(s)';
	if ( null !== $first_non_ascii ) {
		echo ' (first on line ' . $first_non_ascii . ')';
	}
	echo "\n";
	foreach ( $chars as $ch => $n ) {
		echo '  ' . bin2hex( $ch ) . ' ' . json_encode( $ch, JSON_UNESCAPED_UNICODE ) . " x{$n}\n";
	}
}
