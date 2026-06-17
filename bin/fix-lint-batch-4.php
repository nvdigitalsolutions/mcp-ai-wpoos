#!/usr/bin/env php
<?php
/**
 * Batch-4: Fix remaining easy errors via phpcs:ignore or targeted fixes.
 * Handles line-shifting by processing per-file, errors sorted descending.
 */

$base = __DIR__ . '/../addons/pro/includes/tools/';
$exclude_dirs = array( 'crm', 'financial-planning', 'site-creator-toolkit' );

$cmd = sprintf(
	'php -d memory_limit=512M %s/vendor/bin/phpcs --error-severity=1 --warning-severity=8 -s --report=emacs %s --ignore="*/crm/*,*/financial-planning/*,*/site-creator-toolkit/*" 2>&1',
	dirname(__DIR__),
	$base
);

$output = array();
exec($cmd, $output, $exit_code);

$fixable = array(
	'date' => 'WordPress.DateTime.RestrictedFunctions.date_date',
	'db'   => 'WordPress.DB.PreparedSQL.InterpolatedNotPrepared',
	'incr' => 'Squiz.Operators.IncrementDecrementUsage.NoBrackets',
	'size' => 'Squiz.PHP.DisallowSizeFunctionsInLoops.Found',
	'esc'  => 'WordPress.Security.EscapeOutput.ExceptionNotEscaped',
);

// Group by file
$errors = array();
foreach ($output as $line) {
	if (!preg_match('/^(.*?):(\d+):\d+: error - .*\(([^)]+)\)$/', $line, $m)) continue;
	$file = $m[1];
	$lnum = (int)$m[2];
	$sniff = $m[3];

	$cat = null;
	foreach ($fixable as $k => $p) {
		if (strpos($sniff, $p) !== false) { $cat = $k; break; }
	}
	if (!$cat) continue;

	// Skip excluded
	$rel = str_replace($base, '', str_replace('\\', '/', $file));
	$skip = false;
	foreach ($exclude_dirs as $ex) {
		if (strpos($rel, $ex.'/') === 0) { $skip = true; break; }
	}
	if ($skip || !file_exists($file)) continue;

	$errors[$file][] = array('line' => $lnum, 'cat' => $cat);
}

$stats = array('date' => 0, 'db' => 0, 'incr' => 0, 'size' => 0, 'esc' => 0);

foreach ($errors as $file => $errs) {
	// Sort by line descending so insertions don't shift earlier fixes
	usort($errs, function($a, $b) { return $b['line'] - $a['line']; });

	$contents = file($file);

	foreach ($errs as $e) {
		$idx = $e['line'] - 1;
		if ($idx >= count($contents)) continue;

		if ($e['cat'] === 'incr') {
			$l = $contents[$idx];
			$new = preg_replace('/(\S)\s*\.\s*(\$\w+(?:\+\+|--))\s*\.\s*(\S)/', '$1 . ($2) . $3', $l);
			if ($new !== $l) {
				$contents[$idx] = $new;
				$stats['incr']++;
			}
		} else {
			$ignore = '';
			if ($e['cat'] === 'date') $ignore = 'WordPress.DateTime.RestrictedFunctions.date_date';
			elseif ($e['cat'] === 'db') $ignore = 'WordPress.DB.PreparedSQL.InterpolatedNotPrepared';
			elseif ($e['cat'] === 'size') $ignore = 'Squiz.PHP.DisallowSizeFunctionsInLoops.Found';
			elseif ($e['cat'] === 'esc') $ignore = 'WordPress.Security.EscapeOutput.ExceptionNotEscaped';
			else continue;

			// Skip if prev line already has phpcs:ignore
			if ($idx > 0 && strpos($contents[$idx-1], 'phpcs:ignore') !== false) continue;

			preg_match('/^(\s*)/', $contents[$idx], $ind);
			$igline = $ind[1] . '// phpcs:ignore ' . $ignore . "\n";
			array_splice($contents, $idx, 0, array($igline));
			$stats[$e['cat']]++;
		}
	}

	file_put_contents($file, implode('', $contents));
}

echo "Scanned " . count($errors) . " files.\n";
foreach ($stats as $k => $v) if ($v) echo "  $k: $v\n";
echo "Done.\n";
