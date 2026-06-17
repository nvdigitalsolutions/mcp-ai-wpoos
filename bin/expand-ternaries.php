#!/usr/bin/env php
<?php
/**
 * Expand ALL remaining short ternaries by processing line-by-line.
 * Handles: $var['key'] ?:, $var->prop ?:, $var['key']['nested'] ?:, etc.
 */
$base = __DIR__ . '/../addons/pro/includes/tools/';
$exclude = array('crm', 'financial-planning', 'site-creator-toolkit');

$total = 0;
$files = 0;

$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));

foreach ($iter as $f) {
	if ($f->getExtension() !== 'php') continue;
	$path = $f->getPathname();
	$rel = str_replace($base, '', $path);
	$rel = str_replace('\\', '/', $rel);
	foreach ($exclude as $ex) {
		if (strpos($rel, $ex.'/') === 0) continue 2;
	}

	$lines = file($path);
	$changed = false;

	foreach ($lines as $i => &$line) {
		// Find ?: on this line
		while (($pos = strpos($line, '?:')) !== false) {
			// Check it's not null coalesce ??
			if ($pos > 0 && $line[$pos-1] === '?') break;

			// Find the expression before ?:
				$before = substr($line, 0, $pos);
				$before = rtrim($before);

				// Walk backwards finding token boundaries
				$depth = 0;
				$in_sq = false;
				$in_dq = false;
				$expr_start = 0;
			
				for ($j = strlen($before) - 1; $j >= 0; $j--) {
					$c = $before[$j];
				
					// Handle string escaping
					if ($in_sq) { if ($c === "'" && ($j === 0 || $before[$j-1] !== '\\')) $in_sq = false; continue; }
					if ($in_dq) { if ($c === '"' && ($j === 0 || $before[$j-1] !== '\\')) $in_dq = false; continue; }
					if ($c === "'") { $in_sq = true; continue; }
					if ($c === '"') { $in_dq = true; continue; }
				
					// Track nesting
					if ($c === ')' || $c === ']') { $depth++; continue; }
					if ($c === '(' || $c === '[') {
						if ($depth === 0) {
							$expr_start = $j;
							break;
						}
						$depth--;
						continue;
					}
				
					// At depth 0, check for boundaries
					if ($depth === 0) {
						$ord = ord($c);
						// Boundary chars: space, tab, comma, semicolon, braces, =, >, <, +, -, *, /, ., &, |, !, ?, newline
						if ($c === ' ' || $c === "\t" || $c === ',' || $c === ';' || 
						    $c === '{' || $c === '}' || $c === '=' || 
						    $c === '>' || $c === '<' || $c === '+' || $c === '-' ||
						    $c === '*' || $c === '/' || $c === '.' || $c === '&' ||
						    $c === '|' || $c === '!' || $c === '?' || $c === ':' ||
						    $c === "\n" || $c === "\r") {
							$expr_start = $j + 1;
							break;
						}
					}
				}
			
				$expr = trim(substr($before, $expr_start));
			
				// Skip if expression is empty or doesn't look like something that can be short-ternaried
				if ($expr === '') break;
			
				// Replace ?: with ? expr :
				$line = substr_replace($line, ' ? ' . $expr . ' :', $pos, 2);
				$total++;
				$changed = true;
		}
	}

	if ($changed) {
		file_put_contents($path, implode('', $lines));
		$files++;
	}
}

echo "Fixed $total short ternaries in $files files.\n";
