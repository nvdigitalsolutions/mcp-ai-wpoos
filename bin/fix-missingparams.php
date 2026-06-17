#!/usr/bin/env php
<?php
// Add @param array $arguments + @param array $context to all MissingParamTag errors

while ($line = fgets(STDIN)) {
	$line = trim($line);
	if (!preg_match('/^([A-Za-z]:[^:]++):(\d+):\d+: error - .*\(([^)]+)\)\s*$/', $line, $m)) continue;
	if (strpos($m[3], 'MissingParamTag') === false) continue;
	
	$f = str_replace('\\', '/', $m[1]);
	$i = (int)$m[2] - 1;
	if (!file_exists($f)) continue;
	
	$lines = file($f);
	if ($i >= count($lines)) continue;
	
	// Find docblock
	$start = $i;
	while ($start >= 0 && strpos($lines[$start], '/**') === false && $start > $i - 20) $start--;
	if ($start < 0 || strpos($lines[$start], '/**') === false) continue;
	
	$end = $start;
	while ($end < count($lines) && strpos($lines[$end], '*/') === false && $end < $start + 30) $end++;
	if ($end >= count($lines) || strpos($lines[$end], '*/') === false) continue;
	
	// Check if @param already present
	$docblock_text = implode('', array_slice($lines, $start, $end - $start + 1));
	if (strpos($docblock_text, '@param array $arguments') !== false &&
	    strpos($docblock_text, '@param array $context') !== false) continue;
	
	// Add @param before */
	preg_match('/^(\s+)\*/', $lines[$start+1], $im);
	$pad = $im ? $im[1] : "\t";
	$insert = "{$pad}*\n{$pad}* @param array \$arguments Tool arguments.\n{$pad}* @param array \$context   Execution context.\n";
	array_splice($lines, $end, 0, array($insert));
	
	file_put_contents($f, implode('', $lines));
}
