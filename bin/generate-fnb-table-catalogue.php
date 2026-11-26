<?php

/**
 * Generate the core F&B table catalogue from the Pro data-source class.
 *
 * `WP_MCP_AI_Fnb_Data_Source` holds the 18-table registry (1.0…17.0 plus
 * `assumptions`) and the 77-entry header-alias dictionary as static data. Both
 * are the toolkit's contract with an operator's spreadsheets, so transcribing
 * them by hand is how a column silently stops resolving. This script loads the
 * Pro class with only `ABSPATH` defined (its collaborators are referenced
 * inside methods), reflects the two structures, and writes them as a PHP array
 * the core loads with `require` — the same shape as `resources/tool-readiness.php`.
 *
 * ## Usage
 *
 *     php bin/generate-fnb-table-catalogue.php           # write the resource
 *     php bin/generate-fnb-table-catalogue.php --check   # exit 1 if it drifted
 *
 * A re-run must produce a reviewable diff: tables keep the source order and the
 * alias dictionary is key-sorted, so an unchanged source produces an unchanged
 * file.
 *
 * @package NV_oOS
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

$root       = dirname(__DIR__);
$source     = $root . '/addons/pro/includes/tools/food-beverage/class-wp-mcp-ai-fnb-data-source.php';
$target     = $root . '/lib/core/resources/fnb-table-catalogue.php';
$check_only = in_array('--check', $_SERVER['argv'] ?? array(), true);

if (! is_file($source)) {
	fwrite(STDERR, "Source class not found: {$source}\n");
	exit(2);
}

if (! defined('ABSPATH')) {
	define('ABSPATH', $root . '/');
}

require_once $source;

$reflection = new ReflectionClass('WP_MCP_AI_Fnb_Data_Source');
$tables     = $reflection->getStaticPropertyValue('tables');
$aliases    = $reflection->getConstant('HEADER_ALIASES');

ksort($aliases, SORT_STRING);

/**
 * Emit one PHP value, indented, without var_export's `array (` spacing.
 *
 * @param mixed $value Value to emit.
 * @param int   $depth Current indent depth.
 */
function fnb_export(mixed $value, int $depth = 0): string
{
	$pad = str_repeat('    ', $depth);

	if (is_array($value)) {
		if (array() === $value) {
			return 'array()';
		}

		$is_list = array_is_list($value);
		$lines   = array('array(');

		foreach ($value as $key => $item) {
			$lines[] = str_repeat('    ', $depth + 1)
				. ($is_list ? '' : fnb_export($key, 0) . ' => ')
				. fnb_export($item, $depth + 1) . ',';
		}

		$lines[] = $pad . ')';

		return implode("\n", $lines);
	}

	if (is_bool($value)) {
		return $value ? 'true' : 'false';
	}

	if (null === $value) {
		return 'null';
	}

	if (is_int($value) || is_float($value)) {
		return (string) $value;
	}

	return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'";
}

$docblock = <<<'DOC'
<?php

/**
 * The Food & Beverage toolkit's data-table catalogue.
 *
 * 18 tables — the 17 spec tables (`1.0` … `17.0`) and the `assumptions` sheet —
 * each with its canonical column keys and the header spellings an operator's
 * export is likely to use, plus the 77-entry header-alias dictionary that maps
 * vendor and unit-suffixed spellings onto those keys.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 *
 * ---------------------------------------------------------------------------
 * GENERATED FILE — do not edit by hand.
 *
 * The registry is extracted from `WP_MCP_AI_Fnb_Data_Source` (Proprietary) by
 * `bin/generate-fnb-table-catalogue.php`, so the core resolves the same columns
 * the WordPress toolkit resolves. Change the Pro class, re-run the generator,
 * and review the diff; a hand edit here will be overwritten and will drift from
 * the toolkit it is supposed to match.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

return
DOC;

$body = "array(\n"
	. "    'tables' => " . fnb_export($tables, 1) . ",\n"
	. "    'header_aliases' => " . fnb_export($aliases, 1) . ",\n"
	. ");\n";

$rendered = $docblock . "\n" . $body;

if ($check_only) {
	$existing = is_file($target) ? (string) file_get_contents($target) : '';

	if ($existing !== $rendered) {
		fwrite(STDERR, "The F&B catalogue has drifted from the Pro data source; re-run without --check.\n");
		exit(1);
	}

	fwrite(STDOUT, "F&B catalogue is in step with the Pro data source (" . count($tables) . " tables, " . count($aliases) . " aliases).\n");
	exit(0);
}

if (false === file_put_contents($target, $rendered)) {
	fwrite(STDERR, "Could not write {$target}\n");
	exit(2);
}

fwrite(STDOUT, "Wrote {$target} (" . count($tables) . " tables, " . count($aliases) . " aliases).\n");
