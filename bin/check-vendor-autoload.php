<?php
/**
 * Verify that every file referenced by the eager Composer "files" autoload
 * map (vendor/composer/autoload_files.php) actually exists on disk inside
 * the given plugin root.
 *
 * Composer's generated autoloader eagerly requires every entry in this map
 * when vendor/autoload.php is loaded, so a single missing file fatals the
 * whole site on the next request. This checker is used by the release
 * workflows to guarantee the shipped Pro/Complete ZIPs are self-consistent.
 *
 * Exit codes: 0 = OK (or nothing to check), 1 = missing files found.
 *
 * Usage: php check-vendor-autoload.php <plugin-root>
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/\\' ) : getcwd();

$vendor_dir = $root . '/vendor';
$files_php  = $vendor_dir . '/composer/autoload_files.php';

if ( ! file_exists( $files_php ) ) {
	echo 'SKIP: no autoload_files.php found' . PHP_EOL;
	exit( 0 );
}

$missing = array();
$total   = 0;

// Parse autoload_files.php (Composer >= 2.2 "files" autoload map).
$src = file_get_contents( $files_php );
$src = preg_replace( '/^<\?php/', '', $src );
// Drop comments, the $vendorDir/$baseDir definition lines, and the
// `return` keyword, then substitute the concrete paths into the array.
$src = preg_replace( '/^\s*\/\/.*$/m', '', $src );
$src = preg_replace( '/^\s*\$(?:vendorDir|baseDir)\s*=.*$/m', '', $src );
$src = str_replace( array( '$vendorDir', '$baseDir' ), array( "'" . $vendor_dir . "'", "'" . $root . "'" ), $src );
$src = preg_replace( '/^\s*return\s*/m', '', $src );
$src = rtrim( trim( $src ), ';' );
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- one-off CLI integrity checker.
eval( '$map = ' . $src . ';' );
$map = isset( $map ) && is_array( $map ) ? $map : array();
foreach ( (array) $map as $file ) {
	$total++;
	if ( ! file_exists( $file ) ) {
		$missing[] = str_replace( $root . '/', '', $file );
	}
}

if ( ! empty( $missing ) ) {
	echo 'FAIL: ' . count( $missing ) . ' of ' . $total . ' autoloader-referenced files missing:' . PHP_EOL;
	foreach ( $missing as $m ) {
		echo '  ' . $m . PHP_EOL;
	}
	exit( 1 );
}

echo 'OK: all ' . $total . ' autoloader-referenced files present' . PHP_EOL;
exit( 0 );
