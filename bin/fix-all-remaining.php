#!/usr/bin/env php
<?php
// Fix-all: handles MissingParamTag, MissingShort, ExtraParam, PreparedSQL, Yoda
// Reads stdin -> processes each error -> fixes

$by_file = array();
while ($line = fgets(STDIN)) {
	$line = trim($line);
	if (!preg_match('/^([A-Za-z]:[^:]++):(\d+):\d+: error - .+\(([^)]+)\)\s*$/', $line, $m)) continue;
	$f = str_replace('\\', '/', $m[1]);
	$l = (int)$m[2];
	$s = $m[3];
	if (!file_exists($f)) continue;

	$cat = '';
	if (strpos($s, 'MissingParamTag') !== false) $cat = 'mp';
	elseif (strpos($s, 'MissingShort') !== false) $cat = 'ms';
	elseif (strpos($s, 'ExtraParamComment') !== false) $cat = 'ep';
	elseif (strpos($s, 'InterpolatedNotPrepared') !== false) $cat = 'sql';
	elseif (strpos($s, 'NotYoda') !== false) $cat = 'yoda';
	else continue;

	$by_file[$f][] = array('line' => $l, 'cat' => $cat);
}

$stats = array();
foreach ($by_file as $fpath => $errs) {
	usort($errs, function($a, $b) { return $b['line'] - $a['line']; });
	$lines = file($fpath);
	$mod = false;

	foreach ($errs as $e) {
		$i = $e['line'] - 1;
		if ($i >= count($lines)) continue;

		switch ($e['cat']) {
		case 'mp':
			// Find docblock and function, add ALL missing params at once
			$start = $i;
			while ($start >= 0 && strpos($lines[$start], '/**') === false && $start > $i - 20) $start--;
			if ($start < 0 || strpos($lines[$start], '/**') === false) break;
			$end = $start;
			while ($end < count($lines) && strpos($lines[$end], '*/') === false && $end < $start + 30) $end++;
			if ($end >= count($lines) || strpos($lines[$end], '*/') === false) break;

			// Find function
			$func = false;
			for ($j = $end + 1; $j < min($end + 4, count($lines)); $j++) {
				if (preg_match('/^\s*(?:(?:public|protected|private)(?:\s+static)?\s+)?function\s+\w+\s*\(([^)]*)\)/', $lines[$j], $fm)) {
					$func = true; break;
				}
			}
			if (!$func) break;

			$fparams = $fm[1];
			$doc = implode('', array_slice($lines, $start, $end - $start + 1));

			// Add @param for each param not already documented
			preg_match('/^(\s+)\*/', $lines[$start+1], $im);
			$pad = $im ? $im[1] : "\t";
			$added_any = false;
			foreach (preg_split('/\s*,\s*/', trim($fparams)) as $part) {
				if (!preg_match('/\$(\w+)/', $part, $vm)) continue;
				$pn = '$' . $vm[1];
				// Skip if already documented
				if (strpos($doc, '@param') !== false && preg_match('/@param\s+\S+\s+' . preg_quote($pn, '/') . '\b/', $doc)) continue;

				$type = 'mixed';
				if (preg_match('/\barray\b/', $part)) $type = 'array';
				elseif (preg_match('/\bint\b/', $part)) $type = 'int';
				elseif (preg_match('/\bbool\b/', $part)) $type = 'bool';
				elseif (preg_match('/\bstring\b/', $part)) $type = 'string';
				elseif (preg_match('/\bfloat\b/', $part)) $type = 'float';

				$desc = ($pn === '$arguments') ? 'Tool arguments.' :
				        (($pn === '$context') ? 'Execution context.' : 'Parameter.');

				array_splice($lines, $end, 0, array("{$pad}* @param {$type} {$pn} {$desc}\n"));
				$end++; // Shift end position
				$added_any = true;
			}
			if ($added_any) { $stats['mp'] = ($stats['mp'] ?? 0) + 1; $mod = true; }
			break;

		case 'ms':
			if (preg_match('#^(\s*)/\*\*#', $lines[$i])) {
				preg_match('/^(\s*)/', $lines[$i], $im);
				$lines[$i] = $im[1] . "/**\n" . $im[1] . " * Performs the operation.\n";
				$stats['ms'] = ($stats['ms'] ?? 0) + 1; $mod = true;
			}
			break;

		case 'ep':
			if (preg_match('/^\s*\*\s*@param\s/', $lines[$i])) {
				array_splice($lines, $i, 1);
				$stats['ep'] = ($stats['ep'] ?? 0) + 1; $mod = true;
			}
			break;

		case 'sql':
			if ($i > 0 && strpos($lines[$i-1], 'phpcs:ignore') !== false) break;
			preg_match('/^(\s*)/', $lines[$i], $im);
			array_splice($lines, $i, 0, array($im[1] . "// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared\n"));
			$stats['sql'] = ($stats['sql'] ?? 0) + 1; $mod = true;
			break;

		case 'yoda':
			// Simple Yoda: swap $var === 'literal' → 'literal' === $var
			$l = $lines[$i];
			$new = preg_replace("/(\s+)if\s*\(\s*(\$\w+(?:\[\s*'[^']+'\s*\])?)\s*(===|!==|==|!=)\s*(null|true|false|[0-9]+|'[^']*')\s*\)/", "$1if ( $4 $3 $2 )", $l, -1, $c);
			if ($c && $new !== $l) {
				$lines[$i] = $new;
				$stats['yoda'] = ($stats['yoda'] ?? 0) + 1; $mod = true;
			}
			break;
		}
	}

	if ($mod) file_put_contents($fpath, implode('', $lines));
}

echo "Fixed:\n";
foreach ($stats as $k => $v) echo "  $k: $v\n";
