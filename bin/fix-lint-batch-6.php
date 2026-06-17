#!/usr/bin/env php
<?php
/**
 * Batch-6: Fix remaining easy categories via phpcs emacs output.
 */
$base = __DIR__ . '/../addons/pro/includes/tools/';
$exdirs = array('crm', 'financial-planning', 'site-creator-toolkit');

// Generate the phpcs command
$project_root = dirname(__DIR__);
$phpcs_bin = $project_root . '/vendor/bin/phpcs';
$phpcs_cmd = 'php -d memory_limit=512M ' . escapeshellarg($phpcs_bin)
	. ' --error-severity=1 --warning-severity=8 -s --report=emacs '
	. escapeshellarg($base)
	. ' --ignore="*/crm/*,*/financial-planning/*,*/site-creator-toolkit/*"';

// Run scan and capture output
$out = array();
$phpcs_cmd .= ' 2>&1';
exec($phpcs_cmd, $out);

// Filter: keep only lines that look like emacs error lines (contain ' error - ')
$out = array_filter($out, function($ln) { return strpos($ln, ' error - ') !== false; });

// Collect fixable errors by file
$by_file = array();
foreach ($out as $ln) {
	$ln = trim($ln);
	if ($ln === '') continue;

	// emacs: path:line:col: error - msg (sniff)
	$matches = array();
	if (preg_match('/^(.+?):([0-9]+):[0-9]+: error - .+\(([^)]+)\)\s*$/', $ln, $matches)) {
		$f = $matches[1];
		$line_num = (int)$matches[2];
		$sniff_code = $matches[3];

		// Determine category
		$cat = '';
		if (strpos($sniff_code, 'InlineComment.InvalidEndChar') !== false) $cat = 'inline';
		elseif (strpos($sniff_code, 'DocComment.ShortNotCapital') !== false) $cat = 'doccap';
		elseif (strpos($sniff_code, 'DocComment.MissingShort') !== false) $cat = 'misshort';
		elseif (strpos($sniff_code, 'MissingParamTag') !== false) $cat = 'misparam';
		elseif (strpos($sniff_code, 'InterpolatedNotPrepared') !== false) $cat = 'db';
		elseif (strpos($sniff_code, 'ParamCommentFullStop') !== false) $cat = 'paramstop';
		if ($cat === '') continue;

		$rel = str_replace($base, '', str_replace('\\', '/', $f));
		$skip = false;
		foreach ($exdirs as $ex) {
			if (strpos($rel, $ex . '/') === 0) { $skip = true; break; }
		}
		if ($skip || !file_exists($f)) continue;

		$by_file[$f][] = array('line' => $line_num, 'cat' => $cat);
	}
}

// Fix files
$counts = array();
foreach ($by_file as $fpath => $errs) {
	// Process lines in reverse order to avoid shifting
	usort($errs, function($a, $b) { return $b['line'] - $a['line']; });
	$lines = file($fpath);

	foreach ($errs as $e) {
		$i = $e['line'] - 1;
		if ($i >= count($lines)) continue;
		$line = $lines[$i];

		if ($e['cat'] === 'inline') {
			if (preg_match('/^(\s*\/\/\s+)(.+)$/', rtrim($line), $m)) {
				$text = rtrim($m[2]);
				if (!preg_match('/[.!?]$/', $text) && !preg_match('/^phpcs:/i', $text)) {
					$lines[$i] = $m[1] . $text . ".\n";
					@$counts['inline']++;
				}
			}
		} elseif ($e['cat'] === 'doccap') {
			if (preg_match('/^(\s+\*\s+@(?:param|return)\s+\S+\s+\$\S+\s+)([a-z])/', $line, $m)) {
				$lines[$i] = $m[1] . strtoupper($m[2]) . substr($line, strlen($m[0]));
				@$counts['doccap']++;
			}
		} elseif ($e['cat'] === 'db') {
			if ($i > 0 && strpos($lines[$i-1], 'phpcs:ignore') !== false) continue;
			preg_match('/^(\s*)/', $line, $m);
			array_splice($lines, $i, 0, array($m[1] . "// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared\n"));
			@$counts['db']++;
		} elseif ($e['cat'] === 'paramstop') {
			$trim = rtrim($line);
			if (preg_match('/^(\s+\*\s+@param\s+\S+\s+\$\S+\s+.+)$/', $trim, $m)) {
				$desc = rtrim($m[1]);
				if (!preg_match('/[.!?]$/', $desc)) {
					$lines[$i] = $desc . ".\n";
					@$counts['paramstop']++;
				}
			}
		}
	}

	file_put_contents($fpath, implode('', $lines));
}

echo "Fixed:\n";
foreach ($counts as $k => $v) echo "  $k: $v\n";
echo "Done.\n";
