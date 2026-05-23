#!/usr/bin/env php
<?php
// Smart @param fixer: only adds @param for params that actually exist in the function signature

while ($line = fgets(STDIN)) {
	$line = trim($line);
	if (!preg_match('/^([A-Za-z]:[^:]++):(\d+):\d+: error - Doc comment for parameter "([^"]+)" missing \(([^)]+)\)\s*$/', $line, $m)) continue;
	if (strpos($m[4], 'MissingParamTag') === false) continue;

	$f = str_replace('\\', '/', $m[1]);
	$missing_param_name = $m[3]; // e.g., "$arguments" or "$context"
	$i = (int)$m[2] - 1;
	if (!file_exists($f)) continue;

	$lines = file($f);
	if ($i >= count($lines)) continue;

	// Find docblock start (search backwards for /**)
	$start = $i;
	while ($start >= 0 && strpos($lines[$start], '/**') === false && $start > $i - 25) $start--;
	if ($start < 0 || strpos($lines[$start], '/**') === false) continue;

	// Find docblock end (search forwards for */)
	$end = $start;
	while ($end < count($lines) && strpos($lines[$end], '*/') === false && $end < $start + 30) $end++;
	if ($end >= count($lines) || strpos($lines[$end], '*/') === false) continue;

	// Find function after docblock
	$func_line = $end + 1;
	$found_func = false;
	for ($j = $func_line; $j < min($func_line + 3, count($lines)); $j++) {
		if (preg_match('/^\s*(?:(?:public|protected|private)(?:\s+static)?\s+)?function\s+\w+\s*\(([^)]*)\)/', $lines[$j], $fm)) {
			$found_func = true;
			break;
		}
	}
	if (!$found_func) continue;

	// Check if the missing param actually exists in the function signature
	$func_params = $fm[1];
	if (strpos($func_params, $missing_param_name) === false) continue;

	// Check if the @param already exists in the docblock
	$doc_text = implode('', array_slice($lines, $start, $end - $start + 1));
	if (strpos($doc_text, '@param .* ' . preg_quote($missing_param_name, '/')) !== false) continue;

	// Determine type for the param
	$type = 'array';
	if ($missing_param_name === '$context') $type = 'array';
	elseif (strpos($func_params, 'array') !== false) $type = 'array';
	elseif (strpos($func_params, 'int') !== false) $type = 'int';
	elseif (strpos($func_params, 'bool') !== false) $type = 'bool';
	elseif (strpos($func_params, 'string') !== false) $type = 'string';

	// Add @param before */
	preg_match('/^(\s+)\*/', $lines[$start+1], $im);
	$pad = $im ? $im[1] : "\t";
	$desc = ($missing_param_name === '$arguments') ? 'Tool arguments.' : 
	        (($missing_param_name === '$context') ? 'Execution context.' : 'Parameter.');
	$insert = "{$pad}* @param {$type} {$missing_param_name} {$desc}\n";
	array_splice($lines, $end, 0, array($insert));

	file_put_contents($f, implode('', $lines));
}
