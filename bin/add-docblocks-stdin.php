#!/usr/bin/env php
<?php
/**
 * Read phpcs emacs errors from stdin, add docblocks.
 * Usage: grep "FunctionComment.Missing" errors.txt | php add-docblocks.php
 */

$std = array(
	'execute' => "Execute the tool.\n *\n * @param array \$arguments Tool arguments.\n * @param array \$context   Execution context.\n * @return array|WP_Error",
	'get_slug' => "Get the tool slug.\n *\n * @return string",
	'get_name' => "Get the tool name.\n *\n * @return string",
	'get_description' => "Get the tool description.\n *\n * @return string",
	'get_required_capability' => "Get required capability.\n *\n * @return string",
	'get_parameters_schema' => "Get parameters schema.\n *\n * @return array",
	'get_capability_flags' => "Get capability flags.\n *\n * @return array",
	'get_definition' => "Get tool definition.\n *\n * @return array",
	'get_unavailable_reason' => "Get unavailable reason.\n *\n * @return string",
	'is_available' => "Check if tool is available.\n *\n * @return bool",
);

$by_file = array();
$matched = 0;
$skipped_no_file = 0;
while ($line = fgets(STDIN)) {
	$line = trim($line);
	if (!preg_match('/^([A-Za-z]:[^:]++):(\d+):\d+: error - .*\(([^)]+)\)\s*$/', $line, $m)) continue;
	$matched++;
	$f = $m[1];
	// Normalize to forward slashes
	$f = str_replace('\\', '/', $f);
	if (!file_exists($f)) { $skipped_no_file++; continue; }
	$by_file[$f][] = (int)$m[2];
}
echo "Matched: $matched, skipped (no file): $skipped_no_file, files: " . count($by_file) . "\n";

$count = 0;
$func_fail = 0;
foreach ($by_file as $fpath => $lines_err) {
	if (!file_exists($fpath)) continue;
	// Sort descending
	rsort($lines_err);
	$file_lines = file($fpath);
	
	foreach ($lines_err as $line_num) {
		$i = $line_num - 1;
		if ($i >= count($file_lines)) continue;
		
		// Find function on this or next 2 lines
		$found = false;
		for ($j = $i; $j < min($i + 3, count($file_lines)); $j++) {
			if (preg_match('/^\s*(?:(?:public|protected|private)(?:\s+static)?\s+)?function\s+(\w+)\s*\(([^)]*)\)/', $file_lines[$j], $fm)) {
				$found = true; break;
			}
		}
		if (!$found) { $func_fail++; continue; }
		
		$fname = $fm[1];
		$fparams = trim($fm[2]);
		
		if (isset($std[$fname])) {
			$doc = $std[$fname];
		} else {
			$desc = ucfirst($fname) . '.';
			$pli = '';
			if ($fparams !== '') {
				foreach (preg_split('/\s*,\s*/', $fparams) as $part) {
					if (preg_match('/\$(\w+)/', $part, $vm)) {
						$type = 'mixed';
						if (preg_match('/\barray\b/', $part)) $type = 'array';
						elseif (preg_match('/\bint\b/', $part)) $type = 'int';
						elseif (preg_match('/\bbool\b/', $part)) $type = 'bool';
						elseif (preg_match('/\bstring\b/', $part)) $type = 'string';
						elseif (preg_match('/\bfloat\b/', $part)) $type = 'float';
						$pli .= " * @param {$type} \${$vm[1]} Parameter.\n";
					}
				}
			}
			$doc = "{$desc}\n *{$pli} * @return array|WP_Error Result.";
		}
		
		preg_match('/^(\s*)/', $file_lines[$j], $im);
		$ind = $im[1];
		$block = "{$ind}/**\n";
		foreach (explode("\n", $doc) as $dl) {
			$dl = trim($dl);
			$block .= ($dl === '') ? "{$ind} *\n" : "{$ind} * {$dl}\n";
		}
		$block .= "{$ind} */\n";
		array_splice($file_lines, $j, 0, array($block));
		$count++;
	}
	
	file_put_contents($fpath, implode('', $file_lines));
}

echo "Added $count docblocks (failed to find function: $func_fail).\n";
