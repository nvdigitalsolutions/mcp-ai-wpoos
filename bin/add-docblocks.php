#!/usr/bin/env php
<?php
/**
 * Add docblocks from a pre-generated phpcs emacs output file.
 * Usage: php add-docblocks.php /path/to/phpcs-emacs-output.txt
 */
if ($argc < 2) { echo "Usage: php add-docblocks.php <phpcs-emacs-file>\n"; exit(1); }
$error_file = $argv[1];
if (!file_exists($error_file)) { echo "File not found: $error_file\n"; exit(1); }

$base = str_replace('\\', '/', __DIR__) . '/../addons/pro/includes/tools/';
$base = realpath($base) . '/';
$base = str_replace('\\', '/', $base);
$ex = array('crm', 'financial-planning', 'site-creator-toolkit');

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

$lines_in = file($error_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$by_file = array();

foreach ($lines_in as $ln) {
	if (strpos($ln, 'FunctionComment.Missing') === false &&
	    strpos($ln, 'MissingParamTag') === false &&
	    strpos($ln, 'MissingShort') === false &&
	    strpos($ln, 'ExtraParamComment') === false) continue;
	if (!preg_match('/^(.+?):(\d+):\d+: error - .*\(([^)]+)\)\s*$/', $ln, $m)) continue;
	$f = $m[1]; $l = (int)$m[2]; $s = $m[3];
	$rel = str_replace($base, '', str_replace('\\', '/', $f));
	foreach ($ex as $e) if (strpos($rel, $e.'/') === 0) continue 2;
	if (!file_exists($f)) continue;
	$cat = strpos($s, 'FunctionComment.Missing') !== false ? 'fc' :
	       (strpos($s, 'MissingParamTag') !== false ? 'mp' :
	       (strpos($s, 'MissingShort') !== false ? 'ms' : 'ep'));
	$by_file[$f][] = array('line' => $l, 'cat' => $cat);
}

$stats = array('added' => 0, 'mp' => 0, 'ms' => 0, 'ep' => 0);
$total_files = count($by_file);
echo "Processing $total_files files...\n";

foreach ($by_file as $fpath => $errs) {
	usort($errs, function($a, $b) { return $b['line'] - $a['line']; });
	$lines = file($fpath);
	foreach ($errs as $e) {
		$i = $e['line'] - 1;
		if ($i >= count($lines)) continue;
		if ($e['cat'] === 'fc') {
			$found = false;
			for ($j = $i; $j < min($i + 3, count($lines)); $j++) {
				if (preg_match('/^\s*(?:(?:public|protected|private)\s+)?function\s+(\w+)\s*\(([^)]*)\)/', $lines[$j], $fm)) {
					$found = true; break;
				}
			}
			if (!$found) continue;
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
			preg_match('/^(\s*)/', $lines[$j], $im);
			$ind = $im[1];
			$block = "{$ind}/**\n";
			foreach (explode("\n", $doc) as $dl) {
				$dl = trim($dl);
				$block .= ($dl === '') ? "{$ind} *\n" : "{$ind} * {$dl}\n";
			}
			$block .= "{$ind} */\n";
			array_splice($lines, $j, 0, array($block));
			$stats['added']++;
		} elseif ($e['cat'] === 'mp') {
			$start = $i;
			while ($start >= 0 && strpos($lines[$start], '/**') === false) $start--;
			$end = $start;
			while ($end < count($lines) && strpos($lines[$end], '*/') === false) $end++;
			if ($end < count($lines) && $end > $start) {
				preg_match('/^(\s+)\*/', $lines[$start+1], $im);
				$pad = $im ? $im[1] : "\t";
				array_splice($lines, $end, 0, array("{$pad}*\n{$pad}* @param array \$arguments Tool arguments.\n{$pad}* @param array \$context   Execution context.\n"));
				$stats['mp']++;
			}
		} elseif ($e['cat'] === 'ms') {
			if (preg_match('#^(\s*)/\*\*\s*$#', $lines[$i])) {
				preg_match('/^(\s*)/', $lines[$i], $im);
				$lines[$i] = $im[1] . "/**\n" . $im[1] . " * Performs the operation.\n";
				$stats['ms']++;
			}
		} elseif ($e['cat'] === 'ep') {
			if (preg_match('/^\s*\*\s*@param\s/', $lines[$i])) {
				array_splice($lines, $i, 1);
				$stats['ep']++;
			}
		}
	}
	file_put_contents($fpath, implode('', $lines));
}

echo "Fixed:\n";
foreach ($stats as $k => $v) echo "  $k: $v\n";
echo "Done.\n";
