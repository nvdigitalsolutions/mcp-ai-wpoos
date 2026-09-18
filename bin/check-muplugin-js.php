<?php
/**
 * Dev utility: extract the [ollama_status] mu-plugin from a generated
 * blueprint JSON and node-check its inline JS blocks for ASCII purity.
 *
 * Usage: php bin/check-muplugin-js.php <blueprint.json>
 *
 * @package WP_MCP_AI
 */

declare( strict_types=1 );

if ( ! isset( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php bin/check-muplugin-js.php <blueprint.json>\n" );
	exit( 1 );
}

$json = json_decode( (string) file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );

$mu = '';
foreach ( $json['steps'] as $step ) {
	if ( 'writeFile' === $step['step'] && false !== strpos( $step['path'], 'ollama-status' ) ) {
		$mu = $step['data'];
	}
}

if ( '' === $mu ) {
	echo "No ollama-status writeFile step found.\n";
	exit( 1 );
}

file_put_contents( sys_get_temp_dir() . '/ollama-status-extracted.php', $mu );

// Non-ASCII inventory of the whole mu-plugin (HTML + JS).
$nonascii = array();
foreach ( preg_split( '//u', $mu, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
	if ( ord( $ch ) >= 0x80 ) {
		$hex = bin2hex( $ch );
		$nonascii[ $hex ] = isset( $nonascii[ $hex ] ) ? $nonascii[ $hex ] + 1 : 1;
	}
}
echo "mu-plugin non-ASCII chars:\n";
foreach ( $nonascii as $hex => $n ) {
	echo "  {$hex} x{$n}\n";
}

// Extract inline JS blocks and scan those specifically.
preg_match_all( '#<script[^>]*>(.*?)</script>#s', $mu, $m );
echo 'inline script blocks: ' . count( $m[1] ) . "\n";
foreach ( $m[1] as $i => $js ) {
	$bad = array();
	foreach ( preg_split( '//u', $js, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
		if ( ord( $ch ) >= 0x80 ) {
			$bad[ bin2hex( $ch ) ] = $ch;
		}
	}
	echo '  block ' . ( $i + 1 ) . ': ' . strlen( $js ) . " bytes, "
		. ( $bad ? 'NON-ASCII ' . implode( ',', array_keys( $bad ) ) : 'pure ASCII' ) . "\n";
}
