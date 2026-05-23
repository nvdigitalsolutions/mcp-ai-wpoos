#!/usr/bin/env php
<?php
/**
 * Batch-fix: inline comment periods/caps (29), @param doc capitalization (19), @param full stop (3).
 * Safe — no comment closers, no date, no SQL.
 */
$base = __DIR__ . '/../addons/pro/includes/tools/';
$exclude = array( 'crm', 'financial-planning', 'site-creator-toolkit' );

$stats = array( 'inline' => 0, 'doccap' => 0, 'param' => 0 );
$files = 0;
$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ) );

foreach ( $iter as $f ) {
	if ( $f->getExtension() !== 'php' ) continue;
	$p = $f->getPathname();
	$rel = str_replace( $base, '', $p );
	foreach ( $exclude as $ex ) {
		if ( strpos( $rel, $ex . '/' ) === 0 || strpos( $rel, $ex . '\\' ) === 0 ) continue 2;
	}
	$c = file_get_contents( $p );
	$o = $c;
	$ch = false;

	// 1. Inline comment: capitalize first letter, add period
	$c = preg_replace_callback(
		'#^(\s*//\s+)([a-z])([\w\s\'\"\-\(\)\[\]\{\}\,\;\:\@\#\$\%\^\&\*\+\=\/\<\>\~]+?)(\s*)$#m',
		function($m) use (&$stats) {
			$rest = rtrim($m[3]);
			if (preg_match('/^phpcs:/i', $m[2] . $m[3])) return $m[0];
			if (preg_match('/[\.!\?:]\)$/', $rest)) return $m[0];
			$stats['inline']++;
			return $m[1] . strtoupper($m[2]) . $rest . '.' . $m[4];
		},
		$c, -1, $count
	); if($count) $ch=true;

	// 2. @param description: lowercase → capitalize first letter
	$c = preg_replace_callback(
		'#^(\s+\*\s+@param\s+\S+\s+\$\S+\s+)([a-z])#m',
		function($m) use (&$stats) { $stats['doccap']++; return $m[1] . strtoupper($m[2]); },
		$c, -1, $count
	); if($count) $ch=true;

	// 3. @param description ending with letter → add period
	$c = preg_replace_callback(
		'#^(\s+\*\s+@param\s+\S+\s+\$\S+\s+[A-Z][^\n\r]*?)(\s*)$#m',
		function($m) use (&$stats) {
			$desc = rtrim($m[1]);
			if (strlen($desc) > 2 && !preg_match('/[\.!\?]$/', $desc)) {
				$stats['param']++;
				return $desc . '.' . $m[2];
			}
			return $m[0];
		},
		$c, -1, $count
	); if($count) $ch=true;

	if ($ch) { file_put_contents($p, $c); $files++; }
}

echo "Fixed $files files.\n";
foreach ($stats as $k => $v) if ($v) echo "  $k: $v\n";
echo "Done.\n";
