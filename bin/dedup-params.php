#!/usr/bin/env php
<?php
// Deduplicate @param lines within each docblock
$base = __DIR__ . '/../addons/pro/includes/tools/';
$ex = array('crm', 'financial-planning', 'site-creator-toolkit');

$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));
$fixed = 0;

foreach ($iter as $f) {
	if ($f->getExtension() !== 'php') continue;
	$path = $f->getPathname();
	$rel = str_replace('\\', '/', str_replace($base, '', $path));
	foreach ($ex as $e) if (strpos($rel, $e.'/') === 0) continue 2;

	$lines = file($path);
	$in_docblock = false;
	$seen_params = array();
	$mod = false;

	foreach ($lines as $i => &$line) {
		if (strpos($line, '/**') !== false) {
			$in_docblock = true;
			$seen_params = array();
		}
		if ($in_docblock && strpos($line, '*/') !== false) {
			$in_docblock = false;
		}
		if ($in_docblock && preg_match('/^\s*\*\s*@param\s+(\S+)\s+(\$\w+)/', $line, $pm)) {
			$key = $pm[1] . ' ' . $pm[2];
			if (isset($seen_params[$key])) {
				// Duplicate — remove this line
				$line = null;
				$mod = true;
				$fixed++;
			} else {
				$seen_params[$key] = true;
			}
		}
	}

	if ($mod) {
		file_put_contents($path, implode('', array_filter($lines, function($l) { return $l !== null; })));
	}
}

echo "Removed $fixed duplicate @param lines.\n";
