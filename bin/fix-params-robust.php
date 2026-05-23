#!/usr/bin/env php
<?php
// Robust @param fixer: pre-parses files to find method/docblock boundaries
// Then matches MissingParamTag errors to the correct methods

$by_file = array();
while ($line = fgets(STDIN)) {
	$line = trim($line);
	if (!preg_match('/^([A-Za-z]:[^:]++):(\d+):\d+: error - .+\(([^)]+)\)\s*$/', $line, $m)) continue;
	if (strpos($m[3], 'MissingParamTag') === false && strpos($m[3], 'ExtraParamComment') === false) continue;
	$f = str_replace('\\', '/', $m[1]);
	$by_file[$f][] = (int)$m[2];
}

$fixed = 0;
foreach ($by_file as $fpath => $error_lines) {
	if (!file_exists($fpath)) continue;
	$lines = file($fpath);
	$error_lines = array_unique($error_lines);
	rsort($error_lines);

	// Pre-compute method boundaries: [start_line, end_line, params, docblock_start, docblock_end]
	// start_line = line of function keyword, docblock_start = line of /**
	$methods = array();
	$i = 0;
	while ($i < count($lines)) {
		if (preg_match('/^\s*(?:(?:public|protected|private)(?:\s+static)?\s+)?function\s+(\w+)\s*\(([^)]*)\)/', $lines[$i], $fm)) {
			$func_start = $i + 1; // 1-based
			$params = trim($fm[2]);
			
			// Find docblock before function
			$db_start = $i - 1;
			$db_end = $i - 1;
			// Skip whitespace and phpcs:ignore lines
			while ($db_start >= 0 && (trim($lines[$db_start]) === '' || strpos($lines[$db_start], 'phpcs:ignore') !== false)) $db_start--;
			if ($db_start >= 0 && strpos($lines[$db_start], '*/') !== false) {
				// Found end of docblock
				$db_end = $db_start + 1; // 1-based
				$db_start_search = $db_start - 1;
				while ($db_start_search >= 0 && strpos($lines[$db_start_search], '/**') === false && $db_start_search > $db_start - 30) $db_start_search--;
				if ($db_start_search >= 0 && strpos($lines[$db_start_search], '/**') !== false) {
					$db_start = $db_start_search + 1; // 1-based
				} else {
					$db_start = 0; // No docblock found
				}
			} else {
				$db_start = 0; // No docblock
				$db_end = 0;
			}
			
			$methods[] = array('start' => $func_start, 'params' => $params, 'db_start' => $db_start, 'db_end' => $db_end);
		}
		$i++;
	}

	// For each error line, find the method it belongs to
	$processed = array();
	foreach ($error_lines as $err_line) {
		// Find method whose start line is closest AFTER the error
		$best = null;
		$best_dist = PHP_INT_MAX;
		foreach ($methods as $idx => $m) {
			$dist = $m['start'] - $err_line;
			if ($dist > 0 && $dist < $best_dist) {
				$best_dist = $dist;
				$best = $idx;
			}
		}
		if ($best === null || $best_dist > 20) continue; // Too far
		$m = $methods[$best];
		if ($m['db_start'] === 0) continue; // No docblock
		if (in_array($best, $processed)) continue; // Already processed

		$processed[] = $best;
		$db_start_idx = $m['db_start'] - 1;
		$db_end_idx = $m['db_end'] - 1;
		$params = $m['params'];
		
		// Get existing docblock text
		$doc_text = implode('', array_slice($lines, $db_start_idx, $db_end_idx - $db_start_idx + 1));
		
		// Get indentation
		preg_match('/^(\s+)\*/', $lines[$db_start_idx + 1], $im);
		$pad = $im ? $im[1] : "\t";

		// Add @param for each missing param
		$added = false;
		foreach (preg_split('/\s*,\s*/', $params) as $part) {
			if (!preg_match('/\$(\w+)/', $part, $vm)) continue;
			$pn = '$' . $vm[1];
			if (preg_match('/@param\s+\S+\s+' . preg_quote($pn, '/') . '\b/', $doc_text)) continue;

			$type = 'mixed';
			if (preg_match('/\barray\b/', $part)) $type = 'array';
			elseif (preg_match('/\bint\b/', $part)) $type = 'int';
			elseif (preg_match('/\bbool\b/', $part)) $type = 'bool';
			elseif (preg_match('/\bstring\b/', $part)) $type = 'string';
			elseif (preg_match('/\bfloat\b/', $part)) $type = 'float';

			$desc = ($pn === '$arguments') ? 'Tool arguments.' :
			        (($pn === '$context') ? 'Execution context.' : 'Parameter.');
			
			array_splice($lines, $db_end_idx, 0, array("{$pad}* @param {$type} {$pn} {$desc}\n"));
			$db_end_idx++; // Shift
			$added = true;
		}
		if ($added) $fixed++;
	}

	file_put_contents($fpath, implode('', $lines));
}

echo "Fixed $fixed methods with missing @param tags.\n";
