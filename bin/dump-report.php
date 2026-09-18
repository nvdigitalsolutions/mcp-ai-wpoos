<?php
/**
 * Dev utility: dump selected keys from verify-out/report.json.
 *
 * Usage: php bin/dump-report.php [key1 key2 ...]
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

$file = dirname( __DIR__ ) . '/verify-out/report.json';
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "Missing {$file}\n" );
	exit( 1 );
}

$report = json_decode( (string) file_get_contents( $file ), true );

$keys = array_slice( $argv, 1 );
if ( ! $keys ) {
	$keys = array_keys( $report );
}

foreach ( $keys as $key ) {
	if ( ! array_key_exists( $key, $report ) ) {
		echo "{$key}: (missing)\n";
		continue;
	}
	$value = $report[ $key ];
	if ( is_string( $value ) && strlen( $value ) > 220 ) {
		$value = substr( $value, 0, 220 ) . '…';
	}
	echo "{$key}: " . var_export( $value, true ) . "\n";
}
